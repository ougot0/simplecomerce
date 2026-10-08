/** N'accepte que des chemins internes pour les redirections après connexion. */
export function safeNext(value: unknown, fallback = "/sites"): string {
  if (typeof value !== "string") return fallback;
  if (!value.startsWith("/") || value.startsWith("//") || value.startsWith("/\\")) return fallback;
  return value;
}
