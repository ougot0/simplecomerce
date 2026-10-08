import "server-only";
import { createHash } from "node:crypto";
import { formatFromPath, newFile, parseFile, serializeFile, type ParsedFile } from "@/lib/content/formats";
import { inferSchema, type SourceFile } from "@/lib/content/infer";
import { getAt, safeJoin, setAt, slugify } from "@/lib/content/paths";
import { contentSchemaSchema, entryTitle, type ContentSchema, type Section } from "@/lib/content/schema";
import { collectMediaTokens, deepEqual, pickFields, replaceMediaTokens } from "@/lib/content/values";
import type { FileBackend } from "./backend";
import { RetryableConflict } from "./backend";
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
} from "../types";

/** Fichier lu par le site pour savoir s'il est fermé temporairement. */
export const STATUS_FILE = "simplecommerce-statut.json";

/**
 * Adaptateur générique pour tous les sites dont le contenu est dans des fichiers.
 * Il lit le fichier, modifie uniquement l'élément concerné, et réécrit en conservant le reste.
 */

export interface FileSiteOptions {
  /** Dossier qui contient le contenu (« content », « src/data »…). « auto » = détection, vide = racine. */
  contentDir: string;
  /** Où envoyer les photos et comment le site les référence. dir « auto » = détection. */
  media: { dir: string; publicPrefix: string };
  publicUrl?: string;
  /** Sites sur hébergement classique : la racine est déjà le dossier public. */
  webRoot?: boolean;
}

const CONTENT_DIR_CANDIDATES = ["content", "src/content", "data", "src/data", "_data", "src/_data", "contenu", "site/content", "app/content"];
const ROOT_CONTENT_FILE = /^(content|contenu|data|donnees|site)[\w-]*\.(json|ya?ml)$/i;

interface Snapshot {
  parsed: ParsedFile | null;
  version: string | null;
}

class Reader {
  readonly versions = new Map<string, string | null>();
  private cache = new Map<string, Snapshot>();
  constructor(private backend: FileBackend) {}

  async file(path: string, format: "json" | "yaml" | "markdown"): Promise<Snapshot> {
    const cached = this.cache.get(path);
    if (cached) return cached;
    const raw = await this.backend.read(path);
    let snap: Snapshot;
    if (!raw) snap = { parsed: null, version: null };
    else {
      try {
        snap = { parsed: parseFile(raw.content.toString("utf8"), format), version: raw.version };
      } catch (err) {
        throw new AdapterError("invalid", "Un fichier de contenu du site est mal formé ; votre administrateur doit le corriger.", `${path}: ${String(err)}`);
      }
    }
    this.versions.set(path, snap.version);
    this.cache.set(path, snap);
    return snap;
  }
}

const MAX_ATTEMPTS = 3;

export class FileSiteAdapter implements SiteAdapter {
  private resolved: Promise<void> | null = null;
  constructor(
    private backend: FileBackend,
    private options: FileSiteOptions,
  ) {}

  /** Résout « auto » pour le dossier de contenu et le dossier des photos, une seule fois. */
  private init(): Promise<void> {
    if (!this.resolved) this.resolved = this.resolve();
    return this.resolved;
  }

  private async resolve() {
    const needContent = this.options.contentDir === "auto";
    const needMedia = this.options.media.dir === "auto";
    if (!needContent && !needMedia) return;
    const files = await this.backend.list("", 3);
    if (needContent) {
      const found = CONTENT_DIR_CANDIDATES.find((dir) => files.some((f) => f.startsWith(`${dir}/`) && formatFromPath(f)));
      this.options = { ...this.options, contentDir: found ?? "" };
    }
    if (needMedia) {
      const base = this.options.webRoot ? "" : files.some((f) => f.startsWith("public/")) ? "public" : files.some((f) => f.startsWith("static/")) ? "static" : "";
      this.options = { ...this.options, media: { dir: base ? `${base}/images/simplecommerce` : "images/simplecommerce", publicPrefix: "/images/simplecommerce" } };
    }
  }

  /** Les fichiers candidats à la détection : dans le dossier de contenu, ou quelques fichiers bien nommés à la racine. */
  private async contentFiles(): Promise<string[]> {
    if (this.options.contentDir) return (await this.backend.list(this.options.contentDir, 3)).filter((f) => formatFromPath(f));
    return (await this.backend.list("", 1)).filter((f) => ROOT_CONTENT_FILE.test(f));
  }

  capabilities(section: Section): AdapterCapabilities {
    const src = section.source;
    return {
      reorder: section.kind === "collection" && section.orderable !== false && (src?.type === "file" || !!(src?.type === "folder" && src.orderField)),
      create: section.kind === "collection" && section.allowCreate !== false,
      delete: section.kind === "collection" && section.allowDelete !== false,
    };
  }

  private path(rel: string): string {
    return safeJoin(this.options.contentDir, rel);
  }

  private source(section: Section) {
    if (!section.source) throw new AdapterError("invalid", "Cette rubrique n'est pas reliée à un fichier du site.", section.key);
    return section.source;
  }

  async close() {
    await this.backend.close?.();
  }

  async testConnection(): Promise<ConnectionReport> {
    const checks = await this.backend.test();
    if (checks.every((c) => c.ok)) {
      try {
        await this.init();
        const contentFiles = await this.contentFiles();
        checks.push(
          contentFiles.length > 0
            ? { label: `Fichiers de contenu trouvés (${contentFiles.length})`, ok: true }
            : {
                label: "Fichiers de contenu",
                ok: false,
                hint: `Aucun fichier de contenu trouvé (${this.options.contentDir ? `dossier « ${this.options.contentDir} »` : "content.json, data.json… à la racine"}). Le contenu du site doit d'abord être séparé du code.`,
              },
        );
      } catch (err) {
        checks.push({ label: "Lecture du dossier de contenu", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Dossier illisible." });
      }
    }
    return { ok: checks.every((c) => c.ok), checks };
  }

  async discover(): Promise<{ schema: ContentSchema; notes: string[] }> {
    await this.init();
    const notes: string[] = [];
    // 1. Un schéma écrit à la main dans le dépôt a toujours la priorité.
    for (const candidate of ["simplecommerce.json", "_simplecommerce.json"]) {
      const raw = await this.backend.read(this.path(candidate));
      if (raw) {
        try {
          const schema = contentSchemaSchema.parse(JSON.parse(raw.content.toString("utf8")));
          notes.push(`Schéma lu depuis ${candidate}.`);
          return { schema, notes };
        } catch (err) {
          notes.push(`${candidate} est présent mais invalide (${String(err).slice(0, 120)}) ; détection automatique utilisée.`);
        }
      }
    }
    // 2. Sinon, détection automatique à partir des fichiers.
    const candidates = (await this.contentFiles()).slice(0, 80);
    notes.push(this.options.contentDir ? `Contenu lu dans le dossier « ${this.options.contentDir} ».` : "Contenu lu à la racine du site.");
    const prefix = this.options.contentDir ? `${safeJoin(this.options.contentDir)}/` : "";
    const files: SourceFile[] = [];
    for (const full of candidates) {
      const raw = await this.backend.read(full);
      if (!raw || raw.content.length > 2_000_000) continue;
      files.push({ path: full.startsWith(prefix) ? full.slice(prefix.length) : full, text: raw.content.toString("utf8") });
    }
    const { schema, skipped } = inferSchema(files);
    if (skipped.length) notes.push(`Fichiers ignorés : ${skipped.slice(0, 10).join(", ")}${skipped.length > 10 ? "…" : ""}`);
    if (schema.sections.length === 0) notes.push("Aucune rubrique modifiable n'a été reconnue.");
    schema.media = this.options.media;
    return { schema: { ...schema, contentDir: this.options.contentDir }, notes };
  }

  resolveImageUrl(value: string): string | null {
    if (!value) return null;
    if (/^https?:\/\//i.test(value)) return value;
    if (!this.options.publicUrl) return null;
    const base = this.options.publicUrl.replace(/\/$/, "");
    return value.startsWith("/") ? `${base}${value}` : `${base}/${value.replace(/^\.?\//, "")}`;
  }

  // ---------- Lecture ----------

  private async readCollection(reader: Reader, section: Section): Promise<{ entries: Entry[]; raw: Data[]; files?: Map<string, string> }> {
    await this.init();
    const src = this.source(section);
    if (src.type === "file") {
      const path = this.path(src.file);
      const snap = await reader.file(path, src.format ?? (formatFromPath(src.file) as "json" | "yaml"));
      if (!snap.parsed) throw new AdapterError("not_found", "Le fichier de cette rubrique est introuvable sur le site.", path);
      const list = getAt(snap.parsed.data, src.path);
      if (list !== undefined && !Array.isArray(list)) throw new AdapterError("invalid", "Le contenu de cette rubrique n'a pas la forme attendue.", path);
      const raw = (list ?? []) as Data[];
      return { raw, entries: raw.map((item, i) => ({ id: this.itemId(section, item, i), data: item })) };
    }
    const folder = this.path(src.folder);
    const ext = src.extension ?? (src.format === "markdown" ? ".md" : `.${src.format}`);
    const paths = (await this.backend.list(folder, 1)).filter((p) => p.endsWith(ext) && !p.split("/").pop()!.startsWith("_"));
    const entries: Entry[] = [];
    const files = new Map<string, string>();
    for (const p of paths) {
      const snap = await reader.file(p, src.format);
      if (!snap.parsed) continue;
      const id = p.split("/").pop()!.slice(0, -ext.length);
      files.set(id, p);
      const data = { ...(snap.parsed.data as Data) };
      // Les retours à la ligne finaux ne comptent pas : sinon une relecture ne serait jamais « identique ».
      if (src.format === "markdown") data[src.bodyField ?? "body"] = (snap.parsed.body ?? "").replace(/\s+$/, "");
      entries.push({ id, data });
    }
    if (src.orderField) {
      const key = src.orderField;
      entries.sort((a, b) => Number(a.data[key] ?? 9999) - Number(b.data[key] ?? 9999));
    } else entries.sort((a, b) => a.id.localeCompare(b.id));
    return { entries, raw: entries.map((e) => e.data), files };
  }

  private itemId(section: Section, item: Data, index: number): string {
    if (section.idField && item[section.idField] !== undefined && item[section.idField] !== null) return String(item[section.idField]);
    return `n${index}`;
  }

  async listEntries(section: Section): Promise<Entry[]> {
    const { entries } = await this.readCollection(new Reader(this.backend), section);
    return entries.map((e) => ({ id: e.id, data: pickFields(section, e.data) }));
  }

  async getEntry(section: Section, id: string): Promise<Entry | null> {
    const entries = await this.listEntries(section);
    return entries.find((e) => e.id === id) ?? null;
  }

  async getSingleton(section: Section): Promise<Data> {
    await this.init();
    const src = this.source(section);
    if (src.type !== "file") throw new AdapterError("invalid", "Rubrique mal configurée.", section.key);
    const snap = await new Reader(this.backend).file(this.path(src.file), src.format ?? (formatFromPath(src.file) as "json" | "yaml"));
    if (!snap.parsed) throw new AdapterError("not_found", "Le fichier de cette rubrique est introuvable sur le site.", src.file);
    const value = getAt(snap.parsed.data, src.path);
    return pickFields(section, (value && typeof value === "object" ? value : {}) as Data);
  }

  // ---------- Écriture ----------

  /** Prépare les photos en attente : chemin dans le dépôt / sur le serveur + valeur à écrire dans le contenu. */
  private planAssets(data: Data, ctx: ChangeContext): { data: Data; writes: { path: string; content: Buffer }[] } {
    const tokens = collectMediaTokens(data);
    if (tokens.size === 0) return { data, writes: [] };
    const map = new Map<string, string>();
    const writes: { path: string; content: Buffer }[] = [];
    for (const token of tokens) {
      const asset = ctx.assets.find((a) => a.token === token);
      if (!asset) throw new AdapterError("invalid", "Une photo n'a pas été retrouvée. Envoyez-la à nouveau.", token);
      const path = safeJoin(this.options.media.dir, asset.fileName);
      writes.push({ path, content: asset.buffer });
      map.set(token, `${this.options.media.publicPrefix.replace(/\/$/, "")}/${asset.fileName}`);
    }
    return { data: replaceMediaTokens(data, map) as Data, writes };
  }

  private async run(ctx: ChangeContext, build: (reader: Reader) => Promise<{ writes: { path: string; content: Buffer | null }[]; result: WriteResult }>): Promise<WriteResult> {
    await this.init();
    let lastError: unknown;
    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
      const reader = new Reader(this.backend);
      const { writes, result } = await build(reader);
      const expected: Record<string, string | null> = {};
      for (const w of writes) if (reader.versions.has(w.path)) expected[w.path] = reader.versions.get(w.path)!;
      for (const w of writes) if (!(w.path in expected) && w.content !== null && !w.path.startsWith(safeJoin(this.options.media.dir))) expected[w.path] = null;
      try {
        const { ref } = await this.backend.commit(writes, `${ctx.summary}\n\nPar ${ctx.authorName} via Simple Commerce\nSimpleCommerce-Change: ${ctx.changeId}`, expected);
        return { ...result, ref };
      } catch (err) {
        if (err instanceof RetryableConflict) {
          lastError = err;
          continue;
        }
        throw err;
      }
    }
    throw lastError instanceof ConflictError ? lastError : new ConflictError();
  }

  private assertExpected(section: Section, current: Data | null, expected: Data | null) {
    if (expected === null) return;
    if (!current || !deepEqual(pickFields(section, current), pickFields(section, expected))) {
      throw new ConflictError(`section ${section.key}`);
    }
  }

  /** Construit un nouvel élément dans l'ordre des champs du schéma. */
  private buildItem(section: Section, data: Data, id?: string): Data {
    const item: Data = {};
    if (section.idField && id !== undefined) item[section.idField] = id;
    for (const f of section.fields) if (f.key in data && f.key !== section.idField) item[f.key] = data[f.key];
    return item;
  }

  private newId(section: Section, existing: Entry[], data: Data): string | undefined {
    if (!section.idField) return undefined;
    const ids = existing.map((e) => e.id);
    if (ids.length > 0 && ids.every((i) => /^\d+$/.test(i))) return String(Math.max(...ids.map(Number)) + 1);
    const base = slugify(String(data[section.titleField ?? ""] ?? section.itemLabel ?? "element"));
    let id = base;
    let n = 2;
    while (ids.includes(id)) id = `${base}-${n++}`;
    return id;
  }

  private async writeCollectionFile(reader: Reader, section: Section, items: Data[]): Promise<{ path: string; content: Buffer }> {
    const src = this.source(section);
    if (src.type !== "file") throw new Error("unreachable");
    const path = this.path(src.file);
    const snap = await reader.file(path, src.format ?? (formatFromPath(src.file) as "json" | "yaml"));
    const root = setAt(snap.parsed!.data, src.path, items);
    return { path, content: Buffer.from(serializeFile(snap.parsed!, root), "utf8") };
  }

  async createEntry(section: Section, input: Data, ctx: ChangeContext): Promise<WriteResult> {
    const src = this.source(section);
    return this.run(ctx, async (reader) => {
      const { data, writes } = this.planAssets(input, ctx);
      const { entries, raw } = await this.readCollection(reader, section);
      if (src.type === "file") {
        // Pour garder des identifiants numériques cohérents, on respecte le type existant.
        const id = this.newId(section, entries, data);
        const typedId = id !== undefined && entries.length > 0 && typeof raw[0][section.idField!] === "number" ? Number(id) : id;
        const item = this.buildItem(section, data, typedId as string | undefined);
        if (typedId !== undefined) item[section.idField!] = typedId;
        const next = [...raw, item];
        writes.push(await this.writeCollectionFile(reader, section, next));
        return { writes, result: { id: this.itemId(section, item, next.length - 1), before: null, after: pickFields(section, item) } };
      }
      const ext = src.extension ?? (src.format === "markdown" ? ".md" : `.${src.format}`);
      const base = slugify(String(data[section.titleField ?? ""] ?? section.itemLabel ?? "element"));
      let id = base;
      let n = 2;
      while (entries.some((e) => e.id === id)) id = `${base}-${n++}`;
      const item = this.buildItem(section, data);
      if (src.orderField) item[src.orderField] = entries.length ? Math.max(...entries.map((e) => Number(e.data[src.orderField!] ?? 0))) + 1 : 1;
      const path = safeJoin(this.path(src.folder), `${id}${ext}`);
      writes.push({ path, content: Buffer.from(this.serializeFolderItem(src.format, src.bodyField, item), "utf8") });
      return { writes, result: { id, before: null, after: pickFields(section, item) } };
    });
  }

  private serializeFolderItem(format: "markdown" | "json" | "yaml", bodyField: string | undefined, item: Data, existing?: ParsedFile | null): string {
    if (format === "markdown") {
      const key = bodyField ?? "body";
      const { [key]: body, ...front } = item;
      return existing ? serializeFile(existing, front, String(body ?? "")) : newFile("markdown", front, String(body ?? ""));
    }
    return existing ? serializeFile(existing, item) : newFile(format, item);
  }

  async updateEntry(section: Section, id: string, input: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const src = this.source(section);
    return this.run(ctx, async (reader) => {
      const { data, writes } = this.planAssets(input, ctx);
      const { entries, raw, files } = await this.readCollection(reader, section);
      const index = entries.findIndex((e) => e.id === id);
      if (index === -1) throw new ConflictError(`élément ${id} introuvable`);
      const current = entries[index].data;
      this.assertExpected(section, current, expected);
      const merged = { ...current, ...data };
      if (src.type === "file") {
        const next = raw.map((item, i) => (i === index ? merged : item));
        writes.push(await this.writeCollectionFile(reader, section, next));
      } else {
        const path = files!.get(id)!;
        const snap = await reader.file(path, src.format);
        writes.push({ path, content: Buffer.from(this.serializeFolderItem(src.format, src.bodyField, merged, snap.parsed), "utf8") });
      }
      return { writes, result: { id, before: pickFields(section, current), after: pickFields(section, merged) } };
    });
  }

  async deleteEntry(section: Section, id: string, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const src = this.source(section);
    return this.run(ctx, async (reader) => {
      const { entries, raw, files } = await this.readCollection(reader, section);
      const index = entries.findIndex((e) => e.id === id);
      if (index === -1) throw new ConflictError(`élément ${id} introuvable`);
      const current = entries[index].data;
      this.assertExpected(section, current, expected);
      const writes: { path: string; content: Buffer | null }[] = [];
      if (src.type === "file") writes.push(await this.writeCollectionFile(reader, section, raw.filter((_, i) => i !== index)));
      else writes.push({ path: files!.get(id)!, content: null });
      return { writes, result: { id, before: pickFields(section, current), after: null } };
    });
  }

  async reorder(section: Section, orderedIds: string[], ctx: ChangeContext): Promise<WriteResult> {
    const src = this.source(section);
    return this.run(ctx, async (reader) => {
      const { entries, raw, files } = await this.readCollection(reader, section);
      const beforeOrder = entries.map((e) => e.id);
      if (orderedIds.length !== beforeOrder.length || [...orderedIds].sort().join("|") !== [...beforeOrder].sort().join("|")) {
        throw new ConflictError("la liste a changé");
      }
      const writes: { path: string; content: Buffer | null }[] = [];
      if (src.type === "file") {
        const next = orderedIds.map((id) => raw[beforeOrder.indexOf(id)]);
        writes.push(await this.writeCollectionFile(reader, section, next));
        // Sans identifiant stable, les positions changent : on renvoie les nouvelles.
        const afterOrder = section.idField ? orderedIds : next.map((item, i) => this.itemId(section, item, i));
        return { writes, result: { before: null, after: null, beforeOrder, afterOrder } };
      }
      if (!src.orderField) throw new AdapterError("unsupported", "L'ordre de cette rubrique ne peut pas être modifié.");
      for (const [i, id] of orderedIds.entries()) {
        const entry = entries.find((e) => e.id === id)!;
        if (Number(entry.data[src.orderField]) === i + 1) continue;
        const path = files!.get(id)!;
        const snap = await reader.file(path, src.format);
        const item = { ...entry.data, [src.orderField]: i + 1 };
        writes.push({ path, content: Buffer.from(this.serializeFolderItem(src.format, src.bodyField, item, snap.parsed), "utf8") });
      }
      return { writes, result: { before: null, after: null, beforeOrder, afterOrder: orderedIds } };
    });
  }

  async getStatus(): Promise<SiteStatus> {
    await this.init();
    const raw = await this.backend.read(this.path(STATUS_FILE));
    if (!raw) return OPEN_STATUS;
    try {
      return statusFromFile(JSON.parse(raw.content.toString("utf8")));
    } catch {
      return OPEN_STATUS;
    }
  }

  async setStatus(status: SiteStatus, ctx: ChangeContext): Promise<WriteResult> {
    return this.run(ctx, async (reader) => {
      const path = this.path(STATUS_FILE);
      const snap = await reader.file(path, "json");
      const before = snap.parsed ? statusToFile(statusFromFile(snap.parsed.data)) : statusToFile(OPEN_STATUS);
      const after = statusToFile(status);
      const text = snap.parsed ? serializeFile(snap.parsed, after) : newFile("json", after);
      return { writes: [{ path, content: Buffer.from(text, "utf8") }], result: { before, after } };
    });
  }

  async updateSingleton(section: Section, input: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const src = this.source(section);
    if (src.type !== "file") throw new AdapterError("invalid", "Rubrique mal configurée.", section.key);
    return this.run(ctx, async (reader) => {
      const { data, writes } = this.planAssets(input, ctx);
      const path = this.path(src.file);
      const snap = await reader.file(path, src.format ?? (formatFromPath(src.file) as "json" | "yaml"));
      if (!snap.parsed) throw new AdapterError("not_found", "Le fichier de cette rubrique est introuvable sur le site.", src.file);
      const currentRaw = getAt(snap.parsed.data, src.path);
      const current = (currentRaw && typeof currentRaw === "object" ? currentRaw : {}) as Data;
      this.assertExpected(section, current, expected);
      const merged = { ...current, ...data };
      const root = setAt(snap.parsed.data, src.path, merged);
      writes.push({ path, content: Buffer.from(serializeFile(snap.parsed, root), "utf8") });
      return { writes, result: { before: pickFields(section, current), after: pickFields(section, merged) } };
    });
  }
}

export function sha256(content: Buffer): string {
  return createHash("sha256").update(content).digest("hex");
}

export { entryTitle };
