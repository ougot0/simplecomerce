import "server-only";
import { createHash } from "node:crypto";
import type { ContentSchema, Field, Section } from "@/lib/content/schema";
import { deepEqual, MEDIA_TOKEN_PREFIX, pickFields } from "@/lib/content/values";
import { slugify } from "@/lib/content/paths";
import { httpJson } from "./http";
import {
  AdapterError,
  ConflictError,
  type AdapterCapabilities,
  type ChangeContext,
  type ConnectionReport,
  type Data,
  type Entry,
  type SiteAdapter,
  type WriteResult,
} from "./types";

/**
 * Webflow — Data API v2. Jeton de site (Site settings → Apps & integrations → API access)
 * avec les droits CMS (lecture + écriture) et Assets (lecture + écriture).
 * Les modifications sont publiées directement (« live »).
 */

export interface WebflowConfig {
  siteId?: string;
  token: string;
}

interface WfField {
  id: string;
  slug: string;
  displayName: string;
  type: string;
  isRequired: boolean;
  isEditable?: boolean;
  helpText?: string;
  validations?: { singleLine?: boolean; maxLength?: number; options?: { id: string; name: string }[] } | null;
}

interface WfItem {
  id: string;
  isDraft: boolean;
  isArchived: boolean;
  fieldData: Record<string, unknown>;
}

const API = "https://api.webflow.com/v2";

function mapField(f: WfField): Field | null {
  const base = { key: f.slug, label: f.displayName, required: f.isRequired, help: f.helpText || undefined };
  switch (f.type) {
    case "PlainText":
      if (f.slug === "slug") return { ...base, type: "text", hidden: true };
      return { ...base, type: f.validations?.singleLine === false ? "textarea" : "text", maxLength: f.validations?.maxLength };
    case "RichText":
      return { ...base, type: "richtext" };
    case "Image":
      return { ...base, type: "image", aspect: "libre" };
    case "MultiImage":
      return { ...base, type: "gallery" };
    case "Number":
      return /(price|prix|tarif)/i.test(f.slug) ? { ...base, type: "price", priceFormat: { store: "number" } } : { ...base, type: "number" };
    case "Switch":
      return { ...base, type: "boolean", required: false };
    case "Link":
    case "VideoLink":
      return { ...base, type: "url" };
    case "Email":
      return { ...base, type: "email" };
    case "Phone":
      return { ...base, type: "phone" };
    case "DateTime":
      return { ...base, type: "date" };
    case "Option":
      return { ...base, type: "select", options: (f.validations?.options ?? []).map((o) => ({ value: o.id, label: o.name })) };
    case "Color":
      return { ...base, type: "text", hidden: true };
    default:
      // Références, fichiers… : conservés tels quels, non modifiables ici.
      return null;
  }
}

export class WebflowAdapter implements SiteAdapter {
  private siteId: string | undefined;
  constructor(private cfg: WebflowConfig) {
    this.siteId = cfg.siteId;
  }

  private api<T>(path: string, init: RequestInit = {}) {
    return httpJson<T>(`${API}${path}`, {
      ...init,
      service: "Webflow",
      headers: { Authorization: `Bearer ${this.cfg.token}`, accept: "application/json", ...(init.body ? { "Content-Type": "application/json" } : {}) },
    });
  }

  private async site(): Promise<string> {
    if (this.siteId) return this.siteId;
    const { data } = await this.api<{ sites: { id: string; displayName: string }[] }>("/sites");
    if (data.sites.length !== 1) throw new AdapterError("invalid", "Ce jeton donne accès à plusieurs sites : précisez l'identifiant du site.");
    this.siteId = data.sites[0].id;
    return this.siteId;
  }

  capabilities(section: Section): AdapterCapabilities {
    return { reorder: false, create: section.allowCreate !== false, delete: section.allowDelete !== false };
  }

  async testConnection(): Promise<ConnectionReport> {
    const checks = [];
    try {
      const siteId = await this.site();
      const { data } = await this.api<{ displayName: string }>(`/sites/${siteId}`);
      checks.push({ label: `Site « ${data.displayName} » trouvé`, ok: true });
      const { data: cols } = await this.api<{ collections: unknown[] }>(`/sites/${siteId}/collections`);
      checks.push({ label: `${cols.collections.length} collection(s) du CMS accessibles`, ok: true });
    } catch (err) {
      checks.push({ label: "Connexion à Webflow", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Connexion impossible." });
    }
    return { ok: checks.every((c) => c.ok), checks };
  }

  async discover(): Promise<{ schema: ContentSchema; notes: string[] }> {
    const siteId = await this.site();
    const { data } = await this.api<{ collections: { id: string; displayName: string; singularName: string; slug: string }[] }>(`/sites/${siteId}/collections`);
    const sections: Section[] = [];
    const used = new Set<string>();
    for (const c of data.collections) {
      const { data: detail } = await this.api<{ fields: WfField[] }>(`/collections/${c.id}`);
      const fields = detail.fields.map(mapField).filter((f): f is Field => f !== null);
      let key = slugify(c.slug || c.displayName);
      while (used.has(key)) key += "-2";
      used.add(key);
      sections.push({
        key,
        label: c.displayName,
        kind: "collection",
        itemLabel: c.singularName.toLowerCase(),
        titleField: "name",
        imageField: fields.find((f) => f.type === "image" || f.type === "gallery")?.key,
        subtitleField: fields.find((f) => f.type === "price")?.key,
        orderable: false,
        allowCreate: true,
        allowDelete: true,
        remote: { collectionId: c.id },
        fields,
      });
    }
    return { schema: { version: 1, sections }, notes: ["Les pages statiques de Webflow ne sont pas modifiables par l'API : seules les collections du CMS sont proposées."] };
  }

  resolveImageUrl(value: string): string | null {
    return /^https:\/\//.test(value) ? value : null;
  }

  private collection(section: Section): string {
    const id = section.remote?.collectionId;
    if (typeof id !== "string") throw new AdapterError("invalid", "Rubrique mal configurée.", section.key);
    return id;
  }

  private toData(section: Section, item: WfItem): Data {
    const data: Data = {};
    for (const f of section.fields) {
      const v = item.fieldData[f.key];
      if (f.type === "image") data[f.key] = (v as { url?: string } | null)?.url ?? "";
      else if (f.type === "gallery") data[f.key] = Array.isArray(v) ? v.map((x) => (x as { url: string }).url) : [];
      else if (f.type === "date") data[f.key] = typeof v === "string" ? v.slice(0, 10) : "";
      else data[f.key] = v ?? (f.type === "boolean" ? false : "");
    }
    return data;
  }

  private async uploadAsset(token: string, ctx: ChangeContext): Promise<string> {
    const asset = ctx.assets.find((a) => a.token === token);
    if (!asset) throw new AdapterError("invalid", "Une photo n'a pas été retrouvée. Envoyez-la à nouveau.");
    const fileHash = createHash("md5").update(asset.buffer).digest("hex");
    const { data } = await this.api<{ uploadUrl: string; uploadDetails: Record<string, string>; hostedUrl: string }>(`/sites/${await this.site()}/assets`, {
      method: "POST",
      body: JSON.stringify({ fileName: asset.fileName, fileHash }),
    });
    const form = new FormData();
    for (const [k, v] of Object.entries(data.uploadDetails)) form.append(k, v);
    form.append("file", new Blob([new Uint8Array(asset.buffer)], { type: asset.mime }), asset.fileName);
    const res = await fetch(data.uploadUrl, { method: "POST", body: form, signal: AbortSignal.timeout(60_000) });
    if (!res.ok) throw new AdapterError("remote", "L'envoi de la photo vers Webflow a échoué.", `HTTP ${res.status}`);
    return data.hostedUrl;
  }

  private async toFieldData(section: Section, data: Data, ctx: ChangeContext, isNew: boolean): Promise<Record<string, unknown>> {
    const out: Record<string, unknown> = {};
    const resolve = async (v: string) => (v.startsWith(MEDIA_TOKEN_PREFIX) ? this.uploadAsset(v.slice(MEDIA_TOKEN_PREFIX.length), ctx) : v);
    for (const f of section.fields) {
      if (!(f.key in data) || (f.hidden && f.key !== "slug")) continue;
      const v = data[f.key];
      if (f.type === "image") out[f.key] = v ? { url: await resolve(String(v)) } : null;
      else if (f.type === "gallery") out[f.key] = await Promise.all(((v as string[]) ?? []).map(async (u) => ({ url: await resolve(u) })));
      else if (f.type === "date") out[f.key] = v ? new Date(String(v)).toISOString() : null;
      else out[f.key] = v;
    }
    if (isNew && !out.slug) out.slug = `${slugify(String(data.name ?? "element"))}-${Date.now().toString(36).slice(-4)}`;
    return out;
  }

  async listEntries(section: Section): Promise<Entry[]> {
    const col = this.collection(section);
    const out: Entry[] = [];
    for (let offset = 0; offset < 2000; offset += 100) {
      const { data } = await this.api<{ items: WfItem[]; pagination: { total: number } }>(`/collections/${col}/items?limit=100&offset=${offset}`);
      out.push(...data.items.filter((i) => !i.isArchived).map((i) => ({ id: i.id, data: this.toData(section, i) })));
      if (offset + 100 >= data.pagination.total) break;
    }
    return out;
  }

  async getEntry(section: Section, id: string): Promise<Entry | null> {
    if (!/^[a-f0-9]{24}$/i.test(id)) return null;
    try {
      const { data } = await this.api<WfItem>(`/collections/${this.collection(section)}/items/${id}`);
      return { id, data: this.toData(section, data) };
    } catch (err) {
      if (err instanceof AdapterError && err.code === "not_found") return null;
      throw err;
    }
  }

  async createEntry(section: Section, data: Data, ctx: ChangeContext): Promise<WriteResult> {
    const { data: item } = await this.api<WfItem>(`/collections/${this.collection(section)}/items/live`, {
      method: "POST",
      body: JSON.stringify({ isDraft: false, isArchived: false, fieldData: await this.toFieldData(section, data, ctx, true) }),
    });
    return { id: item.id, before: null, after: pickFields(section, this.toData(section, item)) };
  }

  async updateEntry(section: Section, id: string, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const current = await this.getEntry(section, id);
    if (!current) throw new ConflictError("élément supprimé");
    if (expected && !deepEqual(pickFields(section, current.data), pickFields(section, expected))) throw new ConflictError();
    const { data: item } = await this.api<WfItem>(`/collections/${this.collection(section)}/items/${id}/live`, {
      method: "PATCH",
      body: JSON.stringify({ fieldData: await this.toFieldData(section, { ...current.data, ...data }, ctx, false) }),
    });
    return { id, before: current.data, after: pickFields(section, this.toData(section, item)) };
  }

  async deleteEntry(section: Section, id: string, expected: Data | null): Promise<WriteResult> {
    const current = await this.getEntry(section, id);
    if (!current) throw new ConflictError("élément déjà supprimé");
    if (expected && !deepEqual(pickFields(section, current.data), pickFields(section, expected))) throw new ConflictError();
    const col = this.collection(section);
    await this.api(`/collections/${col}/items/${id}/live`, { method: "DELETE" }).catch(() => undefined);
    await this.api(`/collections/${col}/items/${id}`, { method: "DELETE" });
    return { id, before: current.data, after: null };
  }

  async reorder(): Promise<WriteResult> {
    throw new AdapterError("unsupported", "L'ordre se règle dans Webflow.");
  }
  async getSingleton(): Promise<Data> {
    throw new AdapterError("unsupported", "Rubrique non disponible pour Webflow.");
  }
  async updateSingleton(): Promise<WriteResult> {
    throw new AdapterError("unsupported", "Rubrique non disponible pour Webflow.");
  }
}
