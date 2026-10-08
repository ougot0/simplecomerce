import "server-only";
import { AdapterError, type CheckResult } from "../types";
import { httpJson } from "../http";
import { RetryableConflict, type FileBackend } from "./backend";

export interface GitHubConfig {
  owner: string;
  repo: string;
  branch: string;
  token: string;
  /** GitHub Enterprise : https://github.example.com/api/v3 */
  apiBase?: string;
}

const enc = (p: string) => p.split("/").map(encodeURIComponent).join("/");

export class GitHubBackend implements FileBackend {
  readonly label = "GitHub";
  private tree: { path: string; type: string }[] | null = null;
  constructor(private cfg: GitHubConfig) {}

  private get base() {
    return `${(this.cfg.apiBase ?? "https://api.github.com").replace(/\/$/, "")}/repos/${encodeURIComponent(this.cfg.owner)}/${encodeURIComponent(this.cfg.repo)}`;
  }

  private api<T>(path: string, init: RequestInit = {}) {
    return httpJson<T>(`${this.base}${path}`, {
      ...init,
      service: "GitHub",
      headers: {
        Authorization: `Bearer ${this.cfg.token}`,
        Accept: "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "SimpleCommerce",
        ...(init.body ? { "Content-Type": "application/json" } : {}),
      },
    });
  }

  async test(): Promise<CheckResult[]> {
    const checks: CheckResult[] = [];
    try {
      const { data } = await this.api<{ full_name: string; permissions?: { push?: boolean } }>("");
      checks.push({ label: `Dépôt ${data.full_name} trouvé`, ok: true });
      if (data.permissions && data.permissions.push === false) {
        checks.push({ label: "Droit d'écriture", ok: false, hint: "Le jeton peut lire le dépôt mais pas le modifier. Donnez-lui l'autorisation « Contents : Read and write »." });
      } else checks.push({ label: "Droit d'écriture", ok: true });
    } catch (err) {
      checks.push({
        label: "Accès au dépôt",
        ok: false,
        hint: err instanceof AdapterError && err.code === "not_found"
          ? "Dépôt introuvable : vérifiez le propriétaire et le nom, et que le jeton a accès à ce dépôt."
          : err instanceof AdapterError ? err.userMessage : "Accès impossible.",
      });
      return checks;
    }
    try {
      await this.api(`/branches/${enc(this.cfg.branch)}`);
      checks.push({ label: `Branche « ${this.cfg.branch} » trouvée`, ok: true });
    } catch {
      checks.push({ label: `Branche « ${this.cfg.branch} »`, ok: false, hint: "Cette branche n'existe pas. En général c'est « main »." });
    }
    return checks;
  }

  async read(path: string, ref = this.cfg.branch) {
    try {
      const { data } = await this.api<{ type: string; sha: string; content?: string; encoding?: string }>(`/contents/${enc(path)}?ref=${encodeURIComponent(ref)}`);
      if (data.type !== "file") return null;
      if (data.encoding === "base64" && data.content !== undefined) {
        return { content: Buffer.from(data.content, "base64"), version: data.sha };
      }
      // Fichier de plus d'1 Mo : lecture par l'API des blobs.
      const blob = await this.api<{ content: string }>(`/git/blobs/${data.sha}`);
      return { content: Buffer.from(blob.data.content, "base64"), version: data.sha };
    } catch (err) {
      if (err instanceof AdapterError && err.code === "not_found") return null;
      throw err;
    }
  }

  async list(dir: string, maxDepth = 3): Promise<string[]> {
    if (!this.tree) {
      const { data } = await this.api<{ tree: { path: string; type: string }[] }>(`/git/trees/${encodeURIComponent(this.cfg.branch)}?recursive=1`);
      this.tree = data.tree;
    }
    const prefix = dir ? `${dir.replace(/\/$/, "")}/` : "";
    return this.tree
      .filter((t) => t.type === "blob" && t.path.startsWith(prefix))
      .filter((t) => t.path.slice(prefix.length).split("/").length <= maxDepth)
      .filter((t) => !t.path.split("/").some((seg) => seg === "node_modules" || seg.startsWith(".")))
      .map((t) => t.path);
  }

  async commit(writes: { path: string; content: Buffer | null }[], message: string, expected: Record<string, string | null>) {
    const ref = await this.api<{ object: { sha: string } }>(`/git/ref/heads/${enc(this.cfg.branch)}`);
    const headSha = ref.data.object.sha;
    for (const [path, version] of Object.entries(expected)) {
      const current = await this.read(path, headSha);
      if ((current?.version ?? null) !== version) throw new RetryableConflict(path);
    }
    const head = await this.api<{ tree: { sha: string } }>(`/git/commits/${headSha}`);
    const tree = [];
    for (const w of writes) {
      if (w.content === null) {
        tree.push({ path: w.path, mode: "100644", type: "blob", sha: null });
        continue;
      }
      const blob = await this.api<{ sha: string }>(`/git/blobs`, {
        method: "POST",
        body: JSON.stringify({ content: w.content.toString("base64"), encoding: "base64" }),
      });
      tree.push({ path: w.path, mode: "100644", type: "blob", sha: blob.data.sha });
    }
    const newTree = await this.api<{ sha: string }>(`/git/trees`, {
      method: "POST",
      body: JSON.stringify({ base_tree: head.data.tree.sha, tree }),
    });
    const commit = await this.api<{ sha: string }>(`/git/commits`, {
      method: "POST",
      body: JSON.stringify({ message, tree: newTree.data.sha, parents: [headSha] }),
    });
    try {
      await this.api(`/git/refs/heads/${enc(this.cfg.branch)}`, {
        method: "PATCH",
        body: JSON.stringify({ sha: commit.data.sha, force: false }),
      });
    } catch (err) {
      // 422 « not a fast forward » : quelqu'un a poussé entre-temps.
      if (err instanceof AdapterError && (err.code === "conflict" || err.code === "invalid")) throw new RetryableConflict("branche déplacée");
      throw err;
    }
    this.tree = null;
    return { ref: commit.data.sha };
  }
}
