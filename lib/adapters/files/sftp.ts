import "server-only";
import { createHash, randomBytes } from "node:crypto";
import { posix } from "node:path";
import SftpClient from "ssh2-sftp-client";
import { AdapterError, type CheckResult } from "../types";
import { RetryableConflict, type FileBackend } from "./backend";
import { sha256 } from "./engine";

export interface SftpConfig {
  host: string;
  port: number;
  username: string;
  password?: string;
  privateKey?: string;
  passphrase?: string;
  /** Dossier du site sur le serveur (« /www », « /home/user/public_html »). */
  remoteRoot: string;
  /** Empreinte SHA-256 de la clé du serveur, mémorisée à la première connexion réussie. */
  hostFingerprint?: string;
}

/**
 * SFTP : écriture atomique (fichier temporaire puis renommage) pour que le site
 * ne lise jamais un fichier à moitié écrit.
 */
export class SftpBackend implements FileBackend {
  readonly label = "SFTP";
  private client: SftpClient | null = null;
  /** Empreinte vue lors de la connexion (pour la mémoriser au premier test). */
  seenFingerprint: string | null = null;

  constructor(private cfg: SftpConfig) {}

  private abs(rel: string) {
    const full = posix.normalize(posix.join(this.cfg.remoteRoot, rel));
    const root = posix.normalize(this.cfg.remoteRoot).replace(/\/$/, "");
    if (full !== root && !full.startsWith(`${root}/`)) throw new Error("Chemin interdit");
    return full;
  }

  private async connect(): Promise<SftpClient> {
    if (this.client) return this.client;
    const client = new SftpClient("simplecommerce");
    try {
      await client.connect({
        host: this.cfg.host,
        port: this.cfg.port || 22,
        username: this.cfg.username,
        password: this.cfg.password || undefined,
        privateKey: this.cfg.privateKey || undefined,
        passphrase: this.cfg.passphrase || undefined,
        readyTimeout: 15_000,
        retries: 1,
        hostVerifier: (key: Buffer) => {
          const fp = createHash("sha256").update(key).digest("base64");
          this.seenFingerprint = fp;
          return !this.cfg.hostFingerprint || this.cfg.hostFingerprint === fp;
        },
      } as Parameters<SftpClient["connect"]>[0]);
    } catch (err) {
      const message = String((err as Error)?.message ?? err);
      if (this.cfg.hostFingerprint && this.seenFingerprint && this.seenFingerprint !== this.cfg.hostFingerprint) {
        throw new AdapterError("auth", "L'identité du serveur a changé depuis la dernière connexion. Par sécurité, la connexion est bloquée : contactez votre administrateur.", message);
      }
      if (/authentication|auth fail|permission denied/i.test(message)) {
        throw new AdapterError("auth", "Identifiant ou mot de passe refusé par le serveur.", message);
      }
      throw new AdapterError("network", "Impossible de joindre le serveur. Vérifiez l'adresse et le port.", message);
    }
    this.client = client;
    return client;
  }

  async close() {
    if (this.client) {
      await this.client.end().catch(() => undefined);
      this.client = null;
    }
  }

  async test(): Promise<CheckResult[]> {
    const checks: CheckResult[] = [];
    let client: SftpClient;
    try {
      client = await this.connect();
      checks.push({ label: `Connexion à ${this.cfg.host}`, ok: true });
    } catch (err) {
      checks.push({ label: `Connexion à ${this.cfg.host}`, ok: false, hint: err instanceof AdapterError ? err.userMessage : "Connexion impossible." });
      return checks;
    }
    const exists = await client.exists(this.cfg.remoteRoot);
    if (exists !== "d") {
      checks.push({ label: "Dossier du site", ok: false, hint: `Le dossier « ${this.cfg.remoteRoot} » n'existe pas sur le serveur (souvent « /www » chez OVH).` });
      return checks;
    }
    checks.push({ label: "Dossier du site trouvé", ok: true });
    const probe = this.abs(`.simplecommerce-test-${randomBytes(3).toString("hex")}`);
    try {
      await client.put(Buffer.from("test"), probe);
      await client.delete(probe);
      checks.push({ label: "Droit d'écriture", ok: true });
    } catch {
      checks.push({ label: "Droit d'écriture", ok: false, hint: "Le compte peut lire mais pas écrire dans ce dossier." });
    }
    return checks;
  }

  async read(rel: string) {
    const client = await this.connect();
    const full = this.abs(rel);
    if ((await client.exists(full)) !== "-") return null;
    const content = (await client.get(full)) as Buffer;
    return { content, version: sha256(content) };
  }

  async list(dir: string, maxDepth = 3): Promise<string[]> {
    const client = await this.connect();
    const out: string[] = [];
    const walk = async (rel: string, depth: number) => {
      let items;
      try {
        items = await client.list(this.abs(rel));
      } catch {
        return;
      }
      for (const item of items) {
        if (item.name.startsWith(".") || item.name === "node_modules") continue;
        const child = rel ? `${rel}/${item.name}` : item.name;
        if (item.type === "d") {
          if (depth < maxDepth) await walk(child, depth + 1);
        } else if (item.type === "-") out.push(child);
      }
    };
    await walk(dir, 1);
    return out;
  }

  async commit(writes: { path: string; content: Buffer | null }[], _message: string, expected: Record<string, string | null>) {
    const client = await this.connect();
    for (const [rel, version] of Object.entries(expected)) {
      const current = await this.read(rel);
      if ((current?.version ?? null) !== version) throw new RetryableConflict(rel);
    }
    for (const w of writes) {
      const full = this.abs(w.path);
      if (w.content === null) {
        if ((await client.exists(full)) === "-") await client.delete(full);
        continue;
      }
      await client.mkdir(posix.dirname(full), true);
      const tmp = `${posix.dirname(full)}/.${posix.basename(full)}.sc-${randomBytes(4).toString("hex")}`;
      await client.put(w.content, tmp);
      try {
        await client.posixRename(tmp, full);
      } catch {
        // Serveur sans l'extension posix-rename : on remplace en deux temps.
        if ((await client.exists(full)) === "-") await client.delete(full);
        await client.rename(tmp, full);
      }
    }
    return { ref: randomBytes(4).toString("hex") };
  }
}
