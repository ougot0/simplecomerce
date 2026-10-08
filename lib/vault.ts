import "server-only";
import { createCipheriv, createDecipheriv, randomBytes } from "node:crypto";
import { appMode } from "./env";

/**
 * Chiffrement des identifiants de connexion (AES-256-GCM).
 * La clé maîtresse vit uniquement dans les variables d'environnement :
 *   CREDENTIALS_KEY_V1=<32 octets en base64>   (générer : openssl rand -base64 32)
 * Pour changer de clé : ajouter CREDENTIALS_KEY_V2 et CREDENTIALS_KEY_CURRENT=2 ;
 * les anciens enregistrements restent lisibles et sont réécrits avec la nouvelle clé à la prochaine sauvegarde.
 */

export interface SealedSecret {
  ciphertext: string; // base64
  iv: string; // base64
  authTag: string; // base64
  keyVersion: number;
}

const DEMO_KEY = Buffer.alloc(32, 7);

function currentVersion(): number {
  return Number(process.env.CREDENTIALS_KEY_CURRENT ?? "1");
}

function keyFor(version: number): Buffer {
  const raw = process.env[`CREDENTIALS_KEY_V${version}`];
  if (!raw) {
    if (appMode() === "demo") return DEMO_KEY;
    throw new Error(`Clé de chiffrement CREDENTIALS_KEY_V${version} absente.`);
  }
  const key = Buffer.from(raw, "base64");
  if (key.length !== 32) throw new Error(`CREDENTIALS_KEY_V${version} doit faire 32 octets (base64).`);
  return key;
}

export function seal(secret: Record<string, unknown>, aad: string): SealedSecret {
  const keyVersion = currentVersion();
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", keyFor(keyVersion), iv);
  // L'identifiant du site sert de donnée associée : un secret recopié sur un autre site ne se déchiffre pas.
  cipher.setAAD(Buffer.from(aad));
  const ciphertext = Buffer.concat([cipher.update(JSON.stringify(secret), "utf8"), cipher.final()]);
  return {
    ciphertext: ciphertext.toString("base64"),
    iv: iv.toString("base64"),
    authTag: cipher.getAuthTag().toString("base64"),
    keyVersion,
  };
}

export function unseal<T = Record<string, unknown>>(sealed: SealedSecret, aad: string): T {
  const decipher = createDecipheriv("aes-256-gcm", keyFor(sealed.keyVersion), Buffer.from(sealed.iv, "base64"));
  decipher.setAAD(Buffer.from(aad));
  decipher.setAuthTag(Buffer.from(sealed.authTag, "base64"));
  const plain = Buffer.concat([decipher.update(Buffer.from(sealed.ciphertext, "base64")), decipher.final()]);
  return JSON.parse(plain.toString("utf8")) as T;
}

/** Empreinte affichable d'un secret : « …a3F9 ». Jamais plus de 4 caractères. */
export function fingerprint(secret: Record<string, unknown>): string {
  const main = Object.entries(secret).find(([, v]) => typeof v === "string" && (v as string).length >= 8);
  if (!main) return "enregistré";
  const value = main[1] as string;
  return `…${value.slice(-4)}`;
}
