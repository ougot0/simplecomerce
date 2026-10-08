import { promises as fs } from "node:fs";
import path from "node:path";
import { NextResponse } from "next/server";
import YAML from "yaml";
import matter from "gray-matter";
import { marked } from "marked";
import { appMode } from "@/lib/env";

/**
 * Les deux « sites clients » de la démonstration, servis tels que le ferait leur hébergeur.
 * Ils relisent leurs fichiers à chaque visite : une modification faite dans le portail apparaît aussitôt.
 */

const ROOT = path.resolve(process.cwd(), "demo", "sites");
const esc = (s: unknown) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]!);

function page(title: string, base: string, css: string, body: string) {
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(title)}</title><style>${css}</style></head><body>${body.replaceAll('src="/', `src="${base}/`)}</body></html>`;
}

async function lune(base: string) {
  const c = JSON.parse(await fs.readFile(path.join(ROOT, "patisserie-lune", "content.json"), "utf8"));
  const css = `body{margin:0;font-family:Georgia,serif;background:#fbf7f0;color:#2b2118}header{padding:48px 6vw 24px}h1{font-size:clamp(40px,7vw,80px);margin:0;font-weight:400;letter-spacing:-.02em}
  .msg{background:#2b2118;color:#fbf7f0;padding:10px 6vw;font-family:system-ui}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:32px;padding:24px 6vw}
  .item img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}.item h3{margin:12px 0 4px;font-size:22px;font-weight:400}.price{font-family:system-ui;font-weight:700}
  .off{opacity:.45}.news,.infos{padding:24px 6vw}h2{font-weight:400;font-size:30px;border-top:1px solid #2b2118;padding-top:16px}table{border-collapse:collapse}td{padding:4px 24px 4px 0;font-family:system-ui}`;
  const body = `${c.accueil?.message ? `<div class="msg">${esc(c.accueil.message)}</div>` : ""}
  <header><h1>${esc(c.accueil?.titre)}</h1><p>${esc(c.accueil?.accroche)}</p></header>
  <section class="grid">${(c.gateaux ?? [])
    .map((g: Record<string, unknown>) => `<article class="item ${g.disponible === false ? "off" : ""}">${g.photo ? `<img src="${esc(g.photo)}" alt="">` : ""}<h3>${esc(g.nom)}</h3><div class="price">${esc(g.prix)}${g.disponible === false ? " — victime de son succès" : ""}</div><p>${esc(g.description)}</p></article>`)
    .join("")}</section>
  <section class="news"><h2>Actualités</h2>${(c.actualites ?? []).map((a: Record<string, unknown>) => `<h3>${esc(a.titre)}</h3><p>${esc(a.texte)}</p>`).join("")}</section>
  <section class="infos"><h2>Infos pratiques</h2><p>${esc(c.infos?.adresse)} · ${esc(c.infos?.telephone)}</p><table>${(c.infos?.horaires ?? []).map((h: Record<string, unknown>) => `<tr><td>${esc(h.jour)}</td><td>${esc(h.heures)}</td></tr>`).join("")}</table></section>`;
  return page(c.accueil?.titre ?? "Pâtisserie Lune", base, css, body);
}

async function brun(base: string) {
  const dir = path.join(ROOT, "atelier-brun", "content");
  const site = YAML.parse(await fs.readFile(path.join(dir, "site.yml"), "utf8")) ?? {};
  const files = (await fs.readdir(path.join(dir, "projets"))).filter((f) => f.endsWith(".md"));
  const projets = await Promise.all(files.map(async (f) => matter(await fs.readFile(path.join(dir, "projets", f), "utf8"))));
  projets.sort((a, b) => Number(a.data.ordre ?? 99) - Number(b.data.ordre ?? 99));
  const css = `body{margin:0;font-family:"Helvetica Neue",Arial,sans-serif;background:#f4f4f1;color:#151515}header{padding:40px 5vw;display:grid;grid-template-columns:1fr 1fr;gap:24px;border-bottom:1px solid #151515}
  h1{margin:0;font-size:28px;text-transform:uppercase;letter-spacing:.04em}.p{display:grid;grid-template-columns:2fr 1fr;gap:32px;padding:32px 5vw;border-bottom:1px solid #ccc}.p img{width:100%;display:block}.p h2{margin:0 0 8px;font-weight:500}.meta{color:#666}`;
  const body = `<header><h1>${esc(site.nom)}</h1><p>${esc(site.presentation)}</p></header>${projets
    .map((p) => `<article class="p">${p.data.photo ? `<img src="${esc(p.data.photo)}" alt="">` : "<div></div>"}<div><h2>${esc(p.data.titre)}</h2><div class="meta">${esc(p.data.lieu)} · ${esc(p.data.annee)} · ${esc(p.data.surface)}</div>${marked.parse(esc(p.content)) as string}</div></article>`)
    .join("")}<footer style="padding:32px 5vw">${esc(site.adresse)} · ${esc(site.telephone)} · ${esc(site.email)}</footer>`;
  return page(site.nom ?? "Atelier Brun", base, css, body);
}

/** Le site lit simplecommerce-statut.json : s'il est fermé, il affiche le message à la place du contenu. */
async function closedPage(siteName: string, title: string): Promise<string | null> {
  for (const rel of ["simplecommerce-statut.json", "content/simplecommerce-statut.json"]) {
    try {
      const s = JSON.parse(await fs.readFile(path.join(ROOT, siteName, rel), "utf8"));
      if (!s.ferme) return null;
      const date = s.reouverture ? new Date(s.reouverture).toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" }) : null;
      const css = "body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Georgia,serif;background:#2b2118;color:#fbf7f0;padding:24px}main{max-width:36ch}h1{font-weight:400;font-size:clamp(34px,6vw,56px);margin:0 0 16px}p{font-size:20px;line-height:1.5}";
      return page(title, "", css, `<main><h1>${esc(title)}</h1><p>${esc(s.message)}</p>${date ? `<p>Réouverture le ${esc(date)}.</p>` : ""}</main>`);
    } catch {
      // pas de fichier : site ouvert
    }
  }
  return null;
}

export async function GET(_req: Request, { params }: { params: Promise<{ path: string[] }> }) {
  if (appMode() !== "demo") return new NextResponse(null, { status: 404 });
  const { path: parts } = await params;
  const [siteName, ...rest] = parts;
  if (!["patisserie-lune", "atelier-brun"].includes(siteName)) return new NextResponse(null, { status: 404 });
  const base = `/demo-sites/${siteName}`;
  if (rest.length === 0) {
    const closed = await closedPage(siteName, siteName === "patisserie-lune" ? "Pâtisserie Lune" : "Atelier Brun architectes");
    const html = closed ?? (siteName === "patisserie-lune" ? await lune(base) : await brun(base));
    return new NextResponse(html, { headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" } });
  }
  const rel = rest.join("/");
  if (!/\.(webp|jpe?g|png)$/i.test(rel) || rel.includes("..")) return new NextResponse(null, { status: 404 });
  for (const candidate of [path.join(ROOT, siteName, "public", rel), path.join(ROOT, siteName, rel)]) {
    if (!candidate.startsWith(path.join(ROOT, siteName) + path.sep)) continue;
    try {
      const data = await fs.readFile(/*turbopackIgnore: true*/ candidate);
      const type = rel.endsWith(".webp") ? "image/webp" : rel.endsWith(".png") ? "image/png" : "image/jpeg";
      return new NextResponse(new Uint8Array(data), { headers: { "Content-Type": type, "Cache-Control": "no-store" } });
    } catch {
      // essai suivant
    }
  }
  return new NextResponse(null, { status: 404 });
}
