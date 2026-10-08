import "server-only";
import { promises as fs } from "node:fs";
import path from "node:path";
import { randomBytes } from "node:crypto";
import type { CheckResult } from "../types";
import { RetryableConflict, type FileBackend } from "./backend";
import { sha256 } from "./engine";

/** Dossier local : uniquement pour le mode démonstration. */
export class LocalBackend implements FileBackend {
  readonly label = "Dossier local";
  constructor(private root: string) {}

  private abs(rel: string): string {
    const full = path.resolve(this.root, rel);
    if (full !== this.root && !full.startsWith(this.root + path.sep)) throw new Error("Chemin interdit");
    return full;
  }

  async test(): Promise<CheckResult[]> {
    try {
      await fs.access(this.root);
      return [{ label: "Dossier du site accessible", ok: true }];
    } catch {
      return [{ label: "Dossier du site accessible", ok: false, hint: "Dossier introuvable." }];
    }
  }

  async read(rel: string) {
    try {
      const content = await fs.readFile(this.abs(rel));
      return { content, version: sha256(content) };
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code === "ENOENT") return null;
      throw err;
    }
  }

  async list(dir: string, maxDepth = 3): Promise<string[]> {
    const out: string[] = [];
    const walk = async (rel: string, depth: number) => {
      let items;
      try {
        items = await fs.readdir(this.abs(rel), { withFileTypes: true });
      } catch {
        return;
      }
      for (const item of items) {
        if (item.name.startsWith(".") || item.name === "node_modules") continue;
        const child = rel ? `${rel}/${item.name}` : item.name;
        if (item.isDirectory()) {
          if (depth < maxDepth) await walk(child, depth + 1);
        } else out.push(child);
      }
    };
    await walk(dir, 1);
    return out;
  }

  async commit(writes: { path: string; content: Buffer | null }[], _message: string, expected: Record<string, string | null>) {
    for (const [rel, version] of Object.entries(expected)) {
      const current = await this.read(rel);
      if ((current?.version ?? null) !== version) throw new RetryableConflict(rel);
    }
    for (const w of writes) {
      const full = this.abs(w.path);
      if (w.content === null) {
        await fs.rm(full, { force: true });
        continue;
      }
      await fs.mkdir(path.dirname(full), { recursive: true });
      const tmp = `${full}.sc-${randomBytes(4).toString("hex")}`;
      await fs.writeFile(tmp, w.content);
      await fs.rename(tmp, full);
    }
    return { ref: randomBytes(4).toString("hex") };
  }
}
