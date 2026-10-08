/** Formats d'affichage en français. Partagé navigateur / serveur. */

export function formatWhen(iso: string, now = new Date()): string {
  const date = new Date(iso);
  const diff = (now.getTime() - date.getTime()) / 1000;
  const time = date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" }).replace(":", " h ");
  if (diff < 60) return "à l'instant";
  if (diff < 3600) return `il y a ${Math.floor(diff / 60)} min`;
  const dayKey = (d: Date) => d.toLocaleDateString("fr-FR", { timeZone: "Europe/Paris" });
  if (dayKey(date) === dayKey(now)) return `aujourd'hui à ${time}`;
  const yesterday = new Date(now.getTime() - 86_400_000);
  if (dayKey(date) === dayKey(yesterday)) return `hier à ${time}`;
  const sameYear = date.getFullYear() === now.getFullYear();
  return `${date.toLocaleDateString("fr-FR", { day: "numeric", month: "long", ...(sameYear ? {} : { year: "numeric" }), timeZone: "Europe/Paris" })} à ${time}`;
}

export function plural(n: number, one: string, many?: string): string {
  // gâteau → gâteaux, jeu → jeux, prix → prix
  const auto = /(eau|eu)$/.test(one) ? `${one}x` : /[sxz]$/.test(one) ? one : `${one}s`;
  return `${n} ${n > 1 ? many ?? auto : one}`;
}

/** Adresse affichable d'une image stockée dans le contenu. */
export function imageSrc(value: unknown, assetBase: string): string | null {
  if (typeof value !== "string" || !value) return null;
  if (value.startsWith("sc-media:")) return `/api/media/${value.slice(9)}`;
  if (/^https?:\/\//i.test(value)) return value;
  if (/^(data|javascript):/i.test(value)) return null;
  const base = assetBase.replace(/\/$/, "");
  return value.startsWith("/") ? `${base}${value}` : `${base}/${value.replace(/^\.?\//, "")}`;
}

export function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, " ").replace(/&nbsp;/g, " ").replace(/\s+/g, " ").trim();
}
