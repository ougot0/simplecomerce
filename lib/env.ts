import "server-only";

/**
 * Deux modes :
 * - "supabase" (production) : Supabase Auth + Postgres, variables d'environnement obligatoires.
 * - "demo" : tout est stocké dans .data/ sur le disque, pour essayer le portail sans aucun compte.
 *   Activé uniquement si SIMPLECOMMERCE_MODE=demo, jamais par défaut.
 */
export type AppMode = "supabase" | "demo";

export function appMode(): AppMode {
  if (process.env.SIMPLECOMMERCE_MODE === "demo") {
    if (process.env.VERCEL_ENV === "production") {
      throw new Error("Le mode démo est interdit en production.");
    }
    return "demo";
  }
  return "supabase";
}

export function requireEnv(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Variable d'environnement manquante : ${name}`);
  return value;
}

export function siteUrl(): string {
  return (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");
}

/** Secret servant à signer les cookies du portail (impersonation, session démo). */
export function sessionSecret(): string {
  const value = process.env.SESSION_SECRET;
  if (value && value.length >= 32) return value;
  if (appMode() === "demo") return "demo-only-secret-not-for-production-use-0000";
  throw new Error("SESSION_SECRET manquant ou trop court (32 caractères minimum).");
}
