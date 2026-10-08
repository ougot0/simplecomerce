import "server-only";
import { AdapterError, type CheckResult } from "../types";
import { httpError, httpJson } from "../http";
import { RetryableConflict, type FileBackend } from "./backend";
import { sha256 } from "./engine";

export interface BitbucketConfig {
  workspace: string;
  repo: string;
  branch: string;
  /** Jeton d'accès au dépôt (Bearer), ou mot de passe d'application / jeton API avec `username`. */
  token: string;
  username?: string;
}

export class BitbucketBackend implements FileBackend {
  readonly label = "Bitbucket";
  constructor(private cfg: BitbucketConfig) {}

  private get base() {
    return `https://api.bitbucket.org/2.0/repositories/${encodeURIComponent(this.cfg.workspace)}/${encodeURIComponent(this.cfg.repo)}`;
  }

  private get auth() {
    return this.cfg.username
      ? `Basic ${Buffer.from(`${this.cfg.username}:${this.cfg.token}`).toString("base64")}`
      : `Bearer ${this.cfg.token}`;
  }

  async test(): Promise<CheckResult[]> {
    const checks: CheckResult[] = [];
    try {
      const { data } = await httpJson<{ full_name: string }>(this.base, { service: "Bitbucket", headers: { Authorization: this.auth } });
      checks.push({ label: `Dépôt ${data.full_name} trouvé`, ok: true });
      await httpJson(`${this.base}/refs/branches/${encodeURIComponent(this.cfg.branch)}`, { service: "Bitbucket", headers: { Authorization: this.auth } });
      checks.push({ label: `Branche « ${this.cfg.branch} » trouvée`, ok: true });
    } catch (err) {
      checks.push({ label: "Accès au dépôt", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Accès impossible." });
    }
    return checks;
  }

  async read(path: string) {
    const res = await fetch(`${this.base}/src/${encodeURIComponent(this.cfg.branch)}/${path.split("/").map(encodeURIComponent).join("/")}`, {
      headers: { Authorization: this.auth },
      signal: AbortSignal.timeout(20_000),
      cache: "no-store",
    });
    if (res.status === 404) return null;
    if (!res.ok) throw httpError("Bitbucket", res.status, await res.text());
    const content = Buffer.from(await res.arrayBuffer());
    return { content, version: sha256(content) };
  }

  async list(dir: string, maxDepth = 3): Promise<string[]> {
    const out: string[] = [];
    let url: string | undefined = `${this.base}/src/${encodeURIComponent(this.cfg.branch)}/${dir ? `${dir.replace(/\/$/, "")}/` : ""}?max_depth=${maxDepth}&pagelen=100`;
    for (let i = 0; url && i < 20; i++) {
      const page: { data: { values: { path: string; type: string }[]; next?: string } } = await httpJson(url, { service: "Bitbucket", headers: { Authorization: this.auth } });
      for (const v of page.data.values) if (v.type === "commit_file") out.push(v.path);
      url = page.data.next;
    }
    return out.filter((p) => !p.split("/").some((s) => s.startsWith(".")));
  }

  async commit(writes: { path: string; content: Buffer | null }[], message: string, expected: Record<string, string | null>) {
    for (const [path, version] of Object.entries(expected)) {
      const current = await this.read(path);
      if ((current?.version ?? null) !== version) throw new RetryableConflict(path);
    }
    const form = new FormData();
    form.set("message", message);
    form.set("branch", this.cfg.branch);
    for (const w of writes) {
      if (w.content === null) form.append("files", w.path);
      else form.set(w.path, new Blob([new Uint8Array(w.content)]), w.path.split("/").pop());
    }
    const res = await fetch(`${this.base}/src`, {
      method: "POST",
      headers: { Authorization: this.auth },
      body: form,
      signal: AbortSignal.timeout(30_000),
    });
    if (!res.ok) throw httpError("Bitbucket", res.status, await res.text());
    const location = res.headers.get("location") ?? "";
    return { ref: location.split("/").pop() ?? "" };
  }
}
