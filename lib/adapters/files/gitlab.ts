import "server-only";
import { AdapterError, type CheckResult } from "../types";
import { httpJson } from "../http";
import { RetryableConflict, type FileBackend } from "./backend";

export interface GitLabConfig {
  /** « groupe/projet » ou identifiant numérique */
  project: string;
  branch: string;
  token: string;
  baseUrl?: string;
}

export class GitLabBackend implements FileBackend {
  readonly label = "GitLab";
  constructor(private cfg: GitLabConfig) {}

  private get base() {
    return `${(this.cfg.baseUrl ?? "https://gitlab.com").replace(/\/$/, "")}/api/v4/projects/${encodeURIComponent(this.cfg.project)}`;
  }

  private api<T>(path: string, init: RequestInit = {}) {
    return httpJson<T>(`${this.base}${path}`, {
      ...init,
      service: "GitLab",
      headers: { "PRIVATE-TOKEN": this.cfg.token, ...(init.body ? { "Content-Type": "application/json" } : {}) },
    });
  }

  async test(): Promise<CheckResult[]> {
    const checks: CheckResult[] = [];
    try {
      const { data } = await this.api<{
        path_with_namespace: string;
        permissions?: { project_access?: { access_level: number } | null; group_access?: { access_level: number } | null };
      }>("");
      checks.push({ label: `Projet ${data.path_with_namespace} trouvé`, ok: true });
      const level = Math.max(data.permissions?.project_access?.access_level ?? 0, data.permissions?.group_access?.access_level ?? 0);
      checks.push(
        level === 0 || level >= 30
          ? { label: "Droit d'écriture", ok: true }
          : { label: "Droit d'écriture", ok: false, hint: "Le jeton doit avoir au moins le rôle « Developer » et la portée « api »." },
      );
    } catch (err) {
      checks.push({ label: "Accès au projet", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Accès impossible." });
      return checks;
    }
    try {
      await this.api(`/repository/branches/${encodeURIComponent(this.cfg.branch)}`);
      checks.push({ label: `Branche « ${this.cfg.branch} » trouvée`, ok: true });
    } catch {
      checks.push({ label: `Branche « ${this.cfg.branch} »`, ok: false, hint: "Cette branche n'existe pas." });
    }
    return checks;
  }

  async read(path: string) {
    try {
      const { data } = await this.api<{ content: string; blob_id: string }>(
        `/repository/files/${encodeURIComponent(path)}?ref=${encodeURIComponent(this.cfg.branch)}`,
      );
      return { content: Buffer.from(data.content, "base64"), version: data.blob_id };
    } catch (err) {
      if (err instanceof AdapterError && err.code === "not_found") return null;
      throw err;
    }
  }

  async list(dir: string, maxDepth = 3): Promise<string[]> {
    const out: string[] = [];
    for (let page = 1; page <= 20; page++) {
      const { data, headers } = await this.api<{ path: string; type: string }[]>(
        `/repository/tree?recursive=true&per_page=100&page=${page}&ref=${encodeURIComponent(this.cfg.branch)}${dir ? `&path=${encodeURIComponent(dir)}` : ""}`,
      );
      for (const item of data) if (item.type === "blob") out.push(item.path);
      if (!headers.get("x-next-page")) break;
    }
    const prefix = dir ? `${dir.replace(/\/$/, "")}/` : "";
    return out.filter((p) => p.slice(prefix.length).split("/").length <= maxDepth && !p.split("/").some((s) => s.startsWith(".")));
  }

  async commit(writes: { path: string; content: Buffer | null }[], message: string, expected: Record<string, string | null>) {
    const existing = new Map<string, boolean>();
    for (const [path, version] of Object.entries(expected)) {
      const current = await this.read(path);
      if ((current?.version ?? null) !== version) throw new RetryableConflict(path);
      existing.set(path, !!current);
    }
    const actions = [];
    for (const w of writes) {
      if (w.content === null) {
        actions.push({ action: "delete", file_path: w.path });
        continue;
      }
      const exists = existing.has(w.path) ? existing.get(w.path) : !!(await this.read(w.path));
      actions.push({ action: exists ? "update" : "create", file_path: w.path, content: w.content.toString("base64"), encoding: "base64" });
    }
    try {
      const { data } = await this.api<{ id: string }>(`/repository/commits`, {
        method: "POST",
        body: JSON.stringify({ branch: this.cfg.branch, commit_message: message, actions }),
      });
      return { ref: data.id };
    } catch (err) {
      if (err instanceof AdapterError && err.code === "invalid") throw new RetryableConflict(err.detail);
      throw err;
    }
  }
}
