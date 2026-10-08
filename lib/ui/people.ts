import "server-only";
import { getStore } from "@/lib/store";

/** Noms affichables pour une liste d'identifiants (historique, journal). */
export async function actorNames(ids: (string | null)[]): Promise<Map<string, string>> {
  const store = getStore();
  const out = new Map<string, string>();
  for (const id of new Set(ids.filter((x): x is string => !!x))) {
    const p = await store.getProfile(id);
    if (p) out.set(id, p.fullName || p.email);
  }
  return out;
}
