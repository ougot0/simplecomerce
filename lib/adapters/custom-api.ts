import "server-only";
import { contentSchemaSchema, type ContentSchema, type Section } from "@/lib/content/schema";
import { collectMediaTokens, MEDIA_TOKEN_PREFIX, replaceMediaTokens } from "@/lib/content/values";
import { httpError } from "./http";
import {
  AdapterError,
  ConflictError,
  type AdapterCapabilities,
  type ChangeContext,
  type ConnectionReport,
  type Data,
  type Entry,
  type SiteAdapter,
  type SiteStatus,
  type WriteResult,
  OPEN_STATUS,
  statusFromFile,
  statusToFile,
} from "./types";

/**
 * « API sur mesure » : pour les sites qui ont leur propre serveur (PHP, Laravel, Symfony, Node, Django…)
 * et une base de données. Le site expose quelques adresses décrites dans docs/API-SUR-MESURE.md ;
 * un exemple prêt à l'emploi en PHP est fourni dans examples/simplecommerce-endpoint.php.
 */

export interface CustomApiConfig {
  baseUrl: string;
  secret: string;
}

export class CustomApiAdapter implements SiteAdapter {
  private base: string;
  private caps = new Map<string, AdapterCapabilities>();
  constructor(private cfg: CustomApiConfig) {
    this.base = cfg.baseUrl.replace(/\/$/, "");
  }

  private async call<T>(method: string, path: string, body?: unknown, conflictAware = false): Promise<T> {
    let res: Response;
    try {
      res = await fetch(`${this.base}${path}`, {
        method,
        headers: {
          Authorization: `Bearer ${this.cfg.secret}`,
          Accept: "application/json",
          ...(body !== undefined && !(body instanceof FormData) ? { "Content-Type": "application/json" } : {}),
        },
        body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
        signal: AbortSignal.timeout(25_000),
        cache: "no-store",
        redirect: "error",
      });
    } catch (err) {
      throw new AdapterError("network", "Impossible de joindre le site.", String(err));
    }
    const text = await res.text();
    if (conflictAware && res.status === 409) throw new ConflictError();
    if (!res.ok) throw httpError("Le site", res.status, text);
    try {
      return (text ? JSON.parse(text) : {}) as T;
    } catch {
      throw new AdapterError("remote", "Le site a renvoyé une réponse illisible.", text.slice(0, 200));
    }
  }

  capabilities(section: Section): AdapterCapabilities {
    return this.caps.get(section.key) ?? {
      reorder: section.kind === "collection" && section.orderable !== false,
      create: section.kind === "collection" && section.allowCreate !== false,
      delete: section.kind === "collection" && section.allowDelete !== false,
    };
  }

  async testConnection(): Promise<ConnectionReport> {
    const checks = [];
    try {
      const data = await this.call<{ ok?: boolean; name?: string }>("GET", "/ping");
      checks.push({ label: data.name ? `Site « ${data.name} » joint` : "Site joint", ok: data.ok !== false });
      const schema = await this.call<unknown>("GET", "/schema");
      const parsed = contentSchemaSchema.safeParse(schema);
      checks.push(
        parsed.success
          ? { label: `${parsed.data.sections.length} rubrique(s) décrite(s) par le site`, ok: true }
          : { label: "Description des rubriques", ok: false, hint: "Le site renvoie une description des rubriques invalide." },
      );
    } catch (err) {
      checks.push({ label: "Connexion au site", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Connexion impossible." });
    }
    return { ok: checks.every((c) => c.ok), checks };
  }

  async discover(): Promise<{ schema: ContentSchema; notes: string[] }> {
    const schema = contentSchemaSchema.parse(await this.call("GET", "/schema"));
    return { schema, notes: ["Rubriques fournies par le site."] };
  }

  resolveImageUrl(value: string): string | null {
    if (/^https?:\/\//.test(value)) return value;
    if (value.startsWith("/")) return `${new URL(this.base).origin}${value}`;
    return null;
  }

  private async resolveMedia(data: Data, ctx: ChangeContext): Promise<Data> {
    const tokens = collectMediaTokens(data);
    if (tokens.size === 0) return data;
    const map = new Map<string, string>();
    for (const token of tokens) {
      const asset = ctx.assets.find((a) => a.token === token);
      if (!asset) throw new AdapterError("invalid", "Une photo n'a pas été retrouvée. Envoyez-la à nouveau.");
      const form = new FormData();
      form.append("file", new Blob([new Uint8Array(asset.buffer)], { type: asset.mime }), asset.fileName);
      if (asset.alt) form.append("alt", asset.alt);
      const res = await this.call<{ url: string }>("POST", "/media", form);
      map.set(token, res.url);
    }
    return replaceMediaTokens(data, map) as Data;
  }

  private sectionPath(section: Section) {
    return `/sections/${encodeURIComponent(section.key)}`;
  }

  async listEntries(section: Section): Promise<Entry[]> {
    const res = await this.call<{ entries: Entry[] }>("GET", this.sectionPath(section));
    return (res.entries ?? []).map((e) => ({ id: String(e.id), data: e.data ?? {} }));
  }

  async getEntry(section: Section, id: string): Promise<Entry | null> {
    try {
      const res = await this.call<Entry>("GET", `${this.sectionPath(section)}/entries/${encodeURIComponent(id)}`);
      return { id: String(res.id ?? id), data: res.data ?? {} };
    } catch (err) {
      if (err instanceof AdapterError && err.code === "not_found") return null;
      throw err;
    }
  }

  async createEntry(section: Section, data: Data, ctx: ChangeContext): Promise<WriteResult> {
    const res = await this.call<Entry>("POST", `${this.sectionPath(section)}/entries`, { data: await this.resolveMedia(data, ctx), author: ctx.authorName });
    return { id: String(res.id), before: null, after: res.data ?? data };
  }

  async updateEntry(section: Section, id: string, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const before = (await this.getEntry(section, id))?.data ?? null;
    if (!before) throw new ConflictError("élément supprimé");
    const res = await this.call<Entry>("PUT", `${this.sectionPath(section)}/entries/${encodeURIComponent(id)}`, {
      data: await this.resolveMedia(data, ctx),
      expected,
      author: ctx.authorName,
    }, true);
    return { id, before, after: res.data ?? { ...before, ...data } };
  }

  async deleteEntry(section: Section, id: string, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const before = (await this.getEntry(section, id))?.data ?? null;
    if (!before) throw new ConflictError("élément déjà supprimé");
    await this.call("DELETE", `${this.sectionPath(section)}/entries/${encodeURIComponent(id)}`, { expected, author: ctx.authorName }, true);
    return { id, before, after: null };
  }

  async reorder(section: Section, orderedIds: string[], ctx: ChangeContext): Promise<WriteResult> {
    const beforeOrder = (await this.listEntries(section)).map((e) => e.id);
    await this.call("PUT", `${this.sectionPath(section)}/order`, { ids: orderedIds, author: ctx.authorName }, true);
    return { before: null, after: null, beforeOrder, afterOrder: orderedIds };
  }

  async getSingleton(section: Section): Promise<Data> {
    const res = await this.call<{ data: Data }>("GET", this.sectionPath(section));
    return res.data ?? {};
  }

  async getStatus(): Promise<SiteStatus> {
    try {
      return statusFromFile(await this.call("GET", "/status"));
    } catch (err) {
      if (err instanceof AdapterError && err.code === "not_found") return OPEN_STATUS;
      throw err;
    }
  }

  async setStatus(status: SiteStatus, ctx: ChangeContext): Promise<WriteResult> {
    const before = statusToFile(await this.getStatus());
    const after = statusToFile(status);
    await this.call("PUT", "/status", { ...after, author: ctx.authorName });
    return { before, after };
  }

  async updateSingleton(section: Section, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const before = await this.getSingleton(section);
    const res = await this.call<{ data: Data }>("PUT", this.sectionPath(section), { data: await this.resolveMedia(data, ctx), expected, author: ctx.authorName }, true);
    return { before, after: res.data ?? { ...before, ...data } };
  }
}

export { MEDIA_TOKEN_PREFIX };
