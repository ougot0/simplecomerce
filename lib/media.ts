import "server-only";
import { randomUUID } from "node:crypto";
import sharp from "sharp";
import { slugify } from "./content/paths";
import { getStore, type MediaRecord } from "./store";
import type { PendingAsset } from "./adapters/types";

/**
 * Photos : le navigateur recadre et compresse ; le serveur revérifie tout.
 * - vrai type vérifié par les premiers octets (pas l'extension) : JPEG, PNG, WebP uniquement ;
 * - SVG refusé (peut contenir du code) ;
 * - limite de taille et de nombre de pixels (images piégées) ;
 * - métadonnées retirées (position GPS des photos de téléphone) ;
 * - réencodage en WebP.
 */

export const MAX_UPLOAD_BYTES = 6 * 1024 * 1024;
const MAX_PIXELS = 40_000_000;

function sniff(buffer: Buffer): "image/jpeg" | "image/png" | "image/webp" | null {
  if (buffer.length < 12) return null;
  if (buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return "image/jpeg";
  if (buffer.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return "image/png";
  if (buffer.subarray(0, 4).toString("ascii") === "RIFF" && buffer.subarray(8, 12).toString("ascii") === "WEBP") return "image/webp";
  return null;
}

export class UploadError extends Error {}

export async function processUpload(input: Buffer, options: { maxWidth?: number; originalName: string }): Promise<{ buffer: Buffer; width: number; height: number; fileName: string }> {
  if (input.length === 0) throw new UploadError("Le fichier est vide.");
  if (input.length > MAX_UPLOAD_BYTES) throw new UploadError("La photo est trop lourde (6 Mo maximum).");
  if (!sniff(input)) throw new UploadError("Format non accepté. Envoyez une photo JPEG, PNG ou WebP.");
  let image = sharp(input, { limitInputPixels: MAX_PIXELS, failOn: "error" }).rotate();
  const meta = await image.metadata().catch(() => null);
  if (!meta?.width || !meta.height) throw new UploadError("Cette image est illisible.");
  const maxWidth = Math.min(options.maxWidth ?? 2000, 4000);
  if (meta.width > maxWidth) image = image.resize({ width: maxWidth, withoutEnlargement: true });
  const { data, info } = await image.webp({ quality: 82 }).toBuffer({ resolveWithObject: true });
  const base = slugify(options.originalName.replace(/\.[^.]+$/, "")).slice(0, 40) || "photo";
  return { buffer: data, width: info.width, height: info.height, fileName: `${base}-${randomUUID().slice(0, 8)}.webp` };
}

export async function stageUpload(siteId: string, userId: string, input: Buffer, options: { maxWidth?: number; originalName: string; alt: string }): Promise<MediaRecord> {
  const processed = await processUpload(input, options);
  const id = randomUUID();
  const storagePath = `${siteId}/${id}.webp`;
  const store = getStore();
  await store.putMediaBlob(storagePath, processed.buffer, "image/webp");
  return store.insertMedia({
    id,
    siteId,
    uploadedBy: userId,
    storagePath,
    fileName: processed.fileName,
    mime: "image/webp",
    bytes: processed.buffer.length,
    width: processed.width,
    height: processed.height,
    alt: options.alt.slice(0, 300),
  });
}

/** Charge les photos en attente référencées par des données — uniquement celles de ce site. */
export async function loadAssets(siteId: string, tokens: Iterable<string>): Promise<PendingAsset[]> {
  const store = getStore();
  const out: PendingAsset[] = [];
  for (const token of tokens) {
    if (!/^[0-9a-f-]{36}$/.test(token)) continue;
    const media = await store.getMedia(token);
    if (!media || media.siteId !== siteId) continue;
    const buffer = await store.getMediaBlob(media.storagePath);
    if (!buffer) continue;
    out.push({ token, buffer, fileName: media.fileName, mime: media.mime, alt: media.alt || undefined });
  }
  return out;
}
