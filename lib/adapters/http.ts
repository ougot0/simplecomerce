import "server-only";
import { AdapterError } from "./types";

/**
 * fetch avec délai maximal et traduction des erreurs HTTP en messages compréhensibles.
 * Le corps des réponses d'erreur n'est jamais renvoyé au navigateur.
 */
export async function httpJson<T>(
  url: string,
  init: RequestInit & { timeoutMs?: number; service: string } ,
): Promise<{ data: T; headers: Headers; status: number }> {
  const { timeoutMs = 20_000, service, ...rest } = init;
  let response: Response;
  try {
    response = await fetch(url, { ...rest, signal: AbortSignal.timeout(timeoutMs), cache: "no-store", redirect: "error" });
  } catch (err) {
    throw new AdapterError("network", `Impossible de joindre ${service}. Vérifiez l'adresse, puis réessayez.`, String(err));
  }
  const text = await response.text();
  if (!response.ok) throw httpError(service, response.status, text);
  let data: T;
  try {
    data = (text ? JSON.parse(text) : null) as T;
  } catch {
    throw new AdapterError("remote", `${service} a renvoyé une réponse illisible.`, text.slice(0, 200));
  }
  return { data, headers: response.headers, status: response.status };
}

export function httpError(service: string, status: number, body: string): AdapterError {
  const detail = `HTTP ${status} ${body.slice(0, 300)}`;
  if (status === 401) return new AdapterError("auth", `${service} refuse les identifiants enregistrés. Ils ont peut-être expiré ou été révoqués.`, detail);
  if (status === 403) return new AdapterError("auth", `${service} refuse l'accès : les droits accordés sont insuffisants.`, detail);
  if (status === 404) return new AdapterError("not_found", `Élément introuvable sur ${service}.`, detail);
  if (status === 409 || status === 412 || (status === 422 && /sha|conflict|fast.forward/i.test(body))) {
    return new AdapterError("conflict", "Le site a été modifié entre-temps. Réessayez.", detail);
  }
  if (status === 429) return new AdapterError("rate_limited", `${service} demande de patienter un peu. Réessayez dans une minute.`, detail);
  if (status >= 500) return new AdapterError("remote", `${service} rencontre un problème de son côté. Réessayez plus tard.`, detail);
  return new AdapterError("invalid", `${service} a refusé la modification.`, detail);
}
