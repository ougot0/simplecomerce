import "server-only";
import { randomBytes } from "node:crypto";
import { posix } from "node:path";
import { PassThrough, Readable } from "node:stream";
import { Client } from "basic-ftp";
import { AdapterError, type CheckResult } from "../types";
import { RetryableConflict, type FileBackend } from "./backend";
import { sha256 } from "./engine";

export interface FtpConfig {
  host: string;
  port: number;
  username: string;
  password: string;
  /** « explicit » = FTPS (recommandé), « none » = FTP non chiffré (à éviter). */
  tls: "explicit" | "implicit" | "none";
  remoteRoot: string;
}

export class FtpBackend implements FileBackend {
  readonly label = "FTP";
  private client: Client | null = null;
  constructor(private cfg: FtpConfig) {}

  private abs(rel: string) {
    const full = posix.normalize(posix.join(this.cfg.remoteRoot || "/", rel));
    const root = posix.normalize(this.cfg.remoteRoot || "/").replace(/\/$/, "");
    if (full !== root && !full.startsWith(`${root}/`)) throw new Error("Chemin interdit");
    return full;
  }

  private async connect(): Promise<Client> {
    if (this.client && !this.client.closed) return this.client;
    const client = new Client(20_000);
    try {
      await client.access({
        host: this.cfg.host,
        port: this.cfg.port || (this.cfg.tls === "implicit" ? 990 : 21),
        user: this.cfg.username,
        password: this.cfg.password,
        secure: this.cfg.tls === "none" ? false : this.cfg.tls === "implicit" ? "implicit" : true,
      });
    } catch (err) {
      const message = String((err as Error)?.message ?? err);
      if (/530|login|auth/i.test(message)) throw new AdapterError("auth", "Identifiant ou mot de passe refusé par le serveur.", message);
      if (/TLS|SSL|AUTH TLS/i.test(message)) throw new AdapterError("network", "Le serveur n'accepte pas la connexion sécurisée (FTPS). Essayez SFTP si votre hébergeur le propose.", message);
      throw new AdapterError("network", "Impossible de joindre le serveur. Vérifiez l'adresse et le port.", message);
    }
    this.client = client;
    return client;
  }

  async close() {
    this.client?.close();
    this.client = null;
  }

  async test(): Promise<CheckResult[]> {
    const checks: CheckResult[] = [];
    let client: Client;
    try {
      client = await this.connect();
      checks.push({ label: `Connexion à ${this.cfg.host}`, ok: true });
    } catch (err) {
      checks.push({ label: `Connexion à ${this.cfg.host}`, ok: false, hint: err instanceof AdapterError ? err.userMessage : "Connexion impossible." });
      return checks;
    }
    if (this.cfg.tls === "none") checks.push({ label: "Connexion non chiffrée", ok: true, hint: "Le mot de passe circule en clair. Préférez SFTP ou FTPS si possible." });
    try {
      await client.list(this.abs(""));
      checks.push({ label: "Dossier du site trouvé", ok: true });
    } catch {
      checks.push({ label: "Dossier du site", ok: false, hint: `Le dossier « ${this.cfg.remoteRoot} » est introuvable.` });
      return checks;
    }
    const probe = this.abs(`.simplecommerce-test-${randomBytes(3).toString("hex")}`);
    try {
      await client.uploadFrom(Readable.from(Buffer.from("test")), probe);
      await client.remove(probe);
      checks.push({ label: "Droit d'écriture", ok: true });
    } catch {
      checks.push({ label: "Droit d'écriture", ok: false, hint: "Le compte peut lire mais pas écrire dans ce dossier." });
    }
    return checks;
  }

  async read(rel: string) {
    const client = await this.connect();
    const chunks: Buffer[] = [];
    const sink = new PassThrough();
    sink.on("data", (c: Buffer) => chunks.push(c));
    try {
      await client.downloadTo(sink, this.abs(rel));
    } catch (err) {
      if (/550|not found|no such/i.test(String((err as Error)?.message))) return null;
      throw new AdapterError("network", "Lecture impossible sur le serveur.", String(err));
    }
    const content = Buffer.concat(chunks);
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
        if (item.isDirectory) {
          if (depth < maxDepth) await walk(child, depth + 1);
        } else if (item.isFile) out.push(child);
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
        await client.remove(full).catch(() => undefined);
        continue;
      }
      await client.ensureDir(posix.dirname(full));
      await client.cd("/");
      const tmp = `${posix.dirname(full)}/.${posix.basename(full)}.sc-${randomBytes(4).toString("hex")}`;
      await client.uploadFrom(Readable.from(w.content), tmp);
      await client.remove(full).catch(() => undefined);
      await client.rename(tmp, full);
    }
    return { ref: randomBytes(4).toString("hex") };
  }
}
