import "server-only";
import { lookup } from "node:dns/promises";
import { isIP } from "node:net";
import { AdapterError } from "./types";

/**
 * Protection contre les requêtes vers le réseau interne (SSRF) :
 * les adresses saisies par les clients (site WordPress, serveur SFTP, API sur mesure…)
 * doivent pointer vers Internet, jamais vers localhost ou une adresse privée.
 */

function isPrivateIPv4(ip: string): boolean {
  const [a, b] = ip.split(".").map(Number);
  return (
    a === 0 || a === 10 || a === 127 ||
    (a === 100 && b >= 64 && b <= 127) ||
    (a === 169 && b === 254) ||
    (a === 172 && b >= 16 && b <= 31) ||
    (a === 192 && b === 168) ||
    (a === 192 && b === 0) ||
    (a === 198 && (b === 18 || b === 19)) ||
    a >= 224
  );
}

function isPrivateIPv6(ip: string): boolean {
  const lower = ip.toLowerCase();
  if (lower === "::1" || lower === "::") return true;
  if (lower.startsWith("::ffff:")) return isPrivateIPv4(lower.slice(7));
  return /^(fc|fd|fe8|fe9|fea|feb|ff)/.test(lower);
}

export function isPrivateAddress(ip: string): boolean {
  return isIP(ip) === 4 ? isPrivateIPv4(ip) : isIP(ip) === 6 ? isPrivateIPv6(ip) : true;
}

export async function assertPublicHost(hostname: string): Promise<void> {
  if (process.env.SIMPLECOMMERCE_MODE === "demo" && process.env.SIMPLECOMMERCE_ALLOW_PRIVATE_HOSTS === "1") return;
  const host = hostname.replace(/^\[|\]$/g, "");
  if (!host || /^(localhost|.*\.local|.*\.internal)$/i.test(host)) {
    throw new AdapterError("invalid", "Cette adresse n'est pas autorisée.");
  }
  let addresses: { address: string }[];
  try {
    addresses = isIP(host) ? [{ address: host }] : await lookup(host, { all: true });
  } catch {
    throw new AdapterError("network", `L'adresse « ${host} » est introuvable. Vérifiez l'orthographe.`);
  }
  if (addresses.length === 0 || addresses.some((a) => isPrivateAddress(a.address))) {
    throw new AdapterError("invalid", "Cette adresse pointe vers un réseau privé : elle n'est pas autorisée.");
  }
}

export async function assertPublicUrl(raw: string, { httpsOnly = true } = {}): Promise<URL> {
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new AdapterError("invalid", "Adresse invalide. Elle doit ressembler à https://www.mon-site.fr");
  }
  if (url.protocol !== "https:" && (httpsOnly || url.protocol !== "http:")) {
    throw new AdapterError("invalid", "L'adresse doit commencer par https://");
  }
  if (url.username || url.password) throw new AdapterError("invalid", "L'adresse ne doit pas contenir d'identifiants.");
  await assertPublicHost(url.hostname);
  return url;
}
