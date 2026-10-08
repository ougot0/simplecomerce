import "server-only";
import { cookies } from "next/headers";
import { createServerClient } from "@supabase/ssr";
import { requireEnv } from "@/lib/env";

export const REMEMBER_COOKIE = "sc-remember";

/**
 * « Rester connecté » : si la case n'est pas cochée, les cookies de session n'ont pas de date
 * d'expiration et disparaissent à la fermeture du navigateur.
 */
export function sessionCookieOptions<T extends { maxAge?: number; expires?: Date }>(options: T, remember: boolean): T {
  const base = { ...options, httpOnly: true, secure: process.env.NODE_ENV === "production", sameSite: "lax" as const, path: "/" };
  if (remember) return { ...base, maxAge: 60 * 60 * 24 * 30 };
  const { maxAge: _m, expires: _e, ...rest } = base;
  void _m;
  void _e;
  return rest as T;
}

/** Client Supabase lié à la session de l'utilisateur (authentification uniquement). */
export async function supabaseServer() {
  const store = await cookies();
  const remember = store.get(REMEMBER_COOKIE)?.value !== "0";
  return createServerClient(requireEnv("SUPABASE_URL"), requireEnv("SUPABASE_ANON_KEY"), {
    cookies: {
      getAll: () => store.getAll(),
      setAll: (list) => {
        try {
          for (const { name, value, options } of list) store.set(name, value, sessionCookieOptions(options ?? {}, remember));
        } catch {
          // Appel depuis un composant serveur : le proxy se charge de rafraîchir la session.
        }
      },
    },
  });
}
