import "server-only";
import type { ContentSchema, Section } from "@/lib/content/schema";
import { deepEqual, MEDIA_TOKEN_PREFIX, pickFields } from "@/lib/content/values";
import { httpError } from "./http";
import {
  AdapterError,
  ConflictError,
  type AdapterCapabilities,
  type ChangeContext,
  type ConnectionReport,
  type Data,
  type Entry,
  type SiteAdapter,
  type WriteResult,
} from "./types";

/**
 * WordPress (API REST) + WooCommerce.
 * Connexion par « mot de passe d'application » (Profil → Mots de passe d'application), disponible depuis WordPress 5.6.
 * WooCommerce : soit le même mot de passe d'application, soit une clé API WooCommerce (ck_… / cs_…).
 */

export interface WordPressConfig {
  siteUrl: string;
  username: string;
  applicationPassword: string;
  wooConsumerKey?: string;
  wooConsumerSecret?: string;
}

interface WpPost {
  id: number;
  title: { rendered: string; raw?: string };
  content: { rendered: string; raw?: string };
  excerpt?: { rendered: string; raw?: string };
  status: string;
  date: string;
  featured_media: number;
  menu_order?: number;
  _embedded?: { "wp:featuredmedia"?: { id: number; source_url: string }[] };
}

interface WooProduct {
  id: number;
  name: string;
  status: string;
  description: string;
  short_description: string;
  regular_price: string;
  sale_price: string;
  manage_stock: boolean;
  stock_quantity: number | null;
  menu_order: number;
  images: { id: number; src: string }[];
}

const POSTS: Section = {
  key: "actualites",
  label: "Actualités",
  kind: "collection",
  itemLabel: "article",
  titleField: "titre",
  imageField: "photo",
  subtitleField: "date",
  orderable: false,
  allowCreate: true,
  allowDelete: true,
  remote: { type: "posts" },
  fields: [
    { key: "titre", label: "Titre", type: "text", required: true, maxLength: 200 },
    { key: "photo", label: "Photo", type: "image", aspect: "16:9", maxWidth: 1920 },
    { key: "extrait", label: "Résumé", type: "textarea", maxLength: 400 },
    { key: "contenu", label: "Texte", type: "richtext" },
    { key: "publie", label: "Visible sur le site", type: "boolean" },
    { key: "date", label: "Date", type: "date", readOnly: true },
    { key: "photoId", label: "Référence photo", type: "number", hidden: true },
  ],
};

const PAGES: Section = {
  key: "pages",
  label: "Pages",
  kind: "collection",
  itemLabel: "page",
  titleField: "titre",
  orderable: false,
  allowCreate: false,
  allowDelete: false,
  // Masqué par défaut : les pages construites avec Elementor, Divi… ne doivent pas être réécrites.
  hidden: true,
  remote: { type: "pages" },
  fields: [
    { key: "titre", label: "Titre", type: "text", required: true, maxLength: 200 },
    { key: "contenu", label: "Texte", type: "richtext" },
  ],
};

const PRODUCTS: Section = {
  key: "produits",
  label: "Produits",
  kind: "collection",
  itemLabel: "produit",
  titleField: "nom",
  imageField: "photos",
  subtitleField: "prix",
  orderable: true,
  allowCreate: true,
  allowDelete: true,
  remote: { type: "woocommerce" },
  fields: [
    { key: "nom", label: "Nom du produit", type: "text", required: true, maxLength: 200 },
    { key: "photos", label: "Photos", type: "gallery", aspect: "1:1", maxWidth: 1600 },
    { key: "prix", label: "Prix", type: "price", required: true, priceFormat: { store: "string", decimal: "." } },
    { key: "prixPromo", label: "Prix soldé", type: "price", priceFormat: { store: "string", decimal: "." }, help: "Facultatif : remplace le prix tant qu'il est rempli." },
    { key: "stock", label: "Stock", type: "number", min: 0, help: "Laissez vide si vous ne suivez pas le stock." },
    { key: "resume", label: "Description courte", type: "richtext", maxLength: 600 },
    { key: "description", label: "Description", type: "richtext" },
    { key: "enVente", label: "En vente sur le site", type: "boolean" },
  ],
};

function decode(html: string): string {
  return html
    .replace(/<[^>]*>/g, "")
    .replace(/&#8217;|&rsquo;/g, "’")
    .replace(/&#8211;/g, "–")
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/&nbsp;/g, " ");
}

export class WordPressAdapter implements SiteAdapter {
  private base: string;
  constructor(private cfg: WordPressConfig) {
    const url = new URL(cfg.siteUrl);
    if (url.protocol !== "https:" && !/^(localhost|127\.0\.0\.1)$/.test(url.hostname)) {
      throw new AdapterError("invalid", "L'adresse du site doit commencer par https:// pour protéger le mot de passe.");
    }
    this.base = `${url.origin}${url.pathname.replace(/\/$/, "")}/wp-json`;
  }

  private auth(woo = false) {
    if (woo && this.cfg.wooConsumerKey && this.cfg.wooConsumerSecret) {
      return `Basic ${Buffer.from(`${this.cfg.wooConsumerKey}:${this.cfg.wooConsumerSecret}`).toString("base64")}`;
    }
    return `Basic ${Buffer.from(`${this.cfg.username}:${this.cfg.applicationPassword.replace(/\s+/g, "")}`).toString("base64")}`;
  }

  private async api<T>(path: string, init: RequestInit & { woo?: boolean } = {}): Promise<{ data: T; headers: Headers }> {
    const { woo, ...rest } = init;
    let res: Response;
    try {
      res = await fetch(`${this.base}${path}`, {
        ...rest,
        headers: {
          Authorization: this.auth(woo),
          Accept: "application/json",
          ...(typeof rest.body === "string" ? { "Content-Type": "application/json" } : {}),
          ...(rest.headers ?? {}),
        },
        signal: AbortSignal.timeout(25_000),
        cache: "no-store",
      });
    } catch (err) {
      throw new AdapterError("network", "Impossible de joindre le site WordPress.", String(err));
    }
    const text = await res.text();
    if (!res.ok) throw httpError("WordPress", res.status, text);
    try {
      return { data: JSON.parse(text) as T, headers: res.headers };
    } catch {
      throw new AdapterError("remote", "Le site WordPress a renvoyé une réponse illisible (une extension de sécurité bloque peut-être l'API).", text.slice(0, 200));
    }
  }

  private async hasWoo(): Promise<boolean> {
    try {
      await this.api("/wc/v3/products?per_page=1", { woo: true });
      return true;
    } catch {
      return false;
    }
  }

  capabilities(section: Section): AdapterCapabilities {
    return {
      reorder: section.remote?.type === "woocommerce",
      create: section.allowCreate !== false,
      delete: section.allowDelete !== false,
    };
  }

  async testConnection(): Promise<ConnectionReport> {
    const checks = [];
    try {
      const { data } = await this.api<{ name: string; capabilities?: Record<string, boolean> }>("/wp/v2/users/me?context=edit");
      checks.push({ label: `Connecté en tant que « ${data.name} »`, ok: true });
      checks.push(
        data.capabilities?.edit_posts === false
          ? { label: "Droits de modification", ok: false, hint: "Ce compte WordPress ne peut pas modifier les contenus. Utilisez un compte « Éditeur » ou « Administrateur »." }
          : { label: "Droits de modification", ok: true },
      );
    } catch (err) {
      checks.push({
        label: "Connexion à WordPress",
        ok: false,
        hint: err instanceof AdapterError && err.code === "auth"
          ? "Identifiant ou mot de passe d'application refusé. Le mot de passe d'application n'est pas votre mot de passe habituel : créez-le dans Profil → Mots de passe d'application."
          : err instanceof AdapterError ? err.userMessage : "Connexion impossible.",
      });
      return { ok: false, checks };
    }
    if (await this.hasWoo()) checks.push({ label: "Boutique WooCommerce détectée", ok: true });
    return { ok: checks.every((c) => c.ok), checks };
  }

  async discover(): Promise<{ schema: ContentSchema; notes: string[] }> {
    const sections = [POSTS, PAGES];
    const notes = ["Les pages sont masquées par défaut : si elles sont construites avec un constructeur (Elementor, Divi…), leur texte ne doit pas être réécrit."];
    if (await this.hasWoo()) sections.unshift(PRODUCTS);
    return { schema: { version: 1, sections }, notes };
  }

  resolveImageUrl(value: string): string | null {
    return /^https?:\/\//.test(value) ? value : null;
  }

  template(section: Section): Data {
    return section.remote?.type === "woocommerce" ? { enVente: true } : section.remote?.type === "posts" ? { publie: true } : {};
  }

  // ---------- Correspondances ----------

  private postToData(p: WpPost, type: string): Data {
    if (type === "pages") return { titre: decode(p.title.raw ?? p.title.rendered), contenu: p.content.raw ?? p.content.rendered };
    const media = p._embedded?.["wp:featuredmedia"]?.[0];
    return {
      titre: decode(p.title.raw ?? p.title.rendered),
      photo: media?.source_url ?? "",
      extrait: decode(p.excerpt?.raw ?? p.excerpt?.rendered ?? "").trim(),
      contenu: p.content.raw ?? p.content.rendered,
      publie: p.status === "publish",
      date: p.date.slice(0, 10),
      photoId: p.featured_media || 0,
    };
  }

  private productToData(p: WooProduct): Data {
    return {
      nom: decode(p.name),
      photos: p.images.map((i) => i.src),
      prix: p.regular_price,
      prixPromo: p.sale_price,
      stock: p.manage_stock ? p.stock_quantity : null,
      resume: p.short_description,
      description: p.description,
      enVente: p.status === "publish",
    };
  }

  async listEntries(section: Section): Promise<Entry[]> {
    const type = String(section.remote?.type ?? "posts");
    const out: Entry[] = [];
    for (let page = 1; page <= 10; page++) {
      if (type === "woocommerce") {
        const { data, headers } = await this.api<WooProduct[]>(`/wc/v3/products?per_page=100&page=${page}&orderby=menu_order&order=asc&status=any`, { woo: true });
        out.push(...data.map((p) => ({ id: String(p.id), data: this.productToData(p) })));
        if (page >= Number(headers.get("x-wp-totalpages") ?? 1)) break;
      } else {
        const { data, headers } = await this.api<WpPost[]>(`/wp/v2/${type}?per_page=100&page=${page}&context=edit&_embed=wp:featuredmedia&status=publish,draft,future,private`);
        out.push(...data.map((p) => ({ id: String(p.id), data: this.postToData(p, type) })));
        if (page >= Number(headers.get("x-wp-totalpages") ?? 1)) break;
      }
    }
    return out;
  }

  async getEntry(section: Section, id: string): Promise<Entry | null> {
    if (!/^\d+$/.test(id)) return null;
    const type = String(section.remote?.type ?? "posts");
    try {
      if (type === "woocommerce") {
        const { data } = await this.api<WooProduct>(`/wc/v3/products/${id}`, { woo: true });
        return { id, data: this.productToData(data) };
      }
      const { data } = await this.api<WpPost>(`/wp/v2/${type}/${id}?context=edit&_embed=wp:featuredmedia`);
      return { id, data: this.postToData(data, type) };
    } catch (err) {
      if (err instanceof AdapterError && err.code === "not_found") return null;
      throw err;
    }
  }

  async getSingleton(): Promise<Data> {
    throw new AdapterError("unsupported", "Rubrique non disponible.");
  }
  async updateSingleton(): Promise<WriteResult> {
    throw new AdapterError("unsupported", "Rubrique non disponible.");
  }

  // ---------- Photos ----------

  private async uploadMedia(token: string, ctx: ChangeContext): Promise<{ id: number; url: string }> {
    const asset = ctx.assets.find((a) => a.token === token);
    if (!asset) throw new AdapterError("invalid", "Une photo n'a pas été retrouvée. Envoyez-la à nouveau.");
    const { data } = await this.api<{ id: number; source_url: string }>("/wp/v2/media", {
      method: "POST",
      headers: { "Content-Type": asset.mime, "Content-Disposition": `attachment; filename="${asset.fileName}"` },
      body: new Uint8Array(asset.buffer),
    });
    if (asset.alt) {
      await this.api(`/wp/v2/media/${data.id}`, { method: "POST", body: JSON.stringify({ alt_text: asset.alt }) }).catch(() => undefined);
    }
    return { id: data.id, url: data.source_url };
  }

  private async postPayload(data: Data, ctx: ChangeContext, type: string): Promise<Record<string, unknown>> {
    if (type === "pages") return { title: data.titre, content: data.contenu };
    let featured = Number(data.photoId ?? 0);
    const photo = String(data.photo ?? "");
    if (photo.startsWith(MEDIA_TOKEN_PREFIX)) featured = (await this.uploadMedia(photo.slice(MEDIA_TOKEN_PREFIX.length), ctx)).id;
    else if (!photo) featured = 0;
    return {
      title: data.titre,
      content: data.contenu ?? "",
      excerpt: data.extrait ?? "",
      status: data.publie ? "publish" : "draft",
      featured_media: featured,
    };
  }

  private async productPayload(data: Data, current: WooProduct | null, ctx: ChangeContext): Promise<Record<string, unknown>> {
    const images = [];
    for (const value of (data.photos as string[]) ?? []) {
      if (value.startsWith(MEDIA_TOKEN_PREFIX)) images.push({ id: (await this.uploadMedia(value.slice(MEDIA_TOKEN_PREFIX.length), ctx)).id });
      else {
        const existing = current?.images.find((i) => i.src === value);
        images.push(existing ? { id: existing.id } : { src: value });
      }
    }
    const stock = data.stock;
    return {
      name: data.nom,
      regular_price: String(data.prix ?? ""),
      sale_price: String(data.prixPromo ?? ""),
      short_description: data.resume ?? "",
      description: data.description ?? "",
      status: data.enVente ? "publish" : "draft",
      images,
      ...(stock === null || stock === undefined || stock === "" ? { manage_stock: false } : { manage_stock: true, stock_quantity: Number(stock) }),
    };
  }

  // ---------- Écriture ----------

  async createEntry(section: Section, data: Data, ctx: ChangeContext): Promise<WriteResult> {
    const type = String(section.remote?.type ?? "posts");
    if (type === "woocommerce") {
      const { data: created } = await this.api<WooProduct>("/wc/v3/products", { method: "POST", woo: true, body: JSON.stringify(await this.productPayload(data, null, ctx)) });
      return { id: String(created.id), before: null, after: pickFields(section, this.productToData(created)) };
    }
    const { data: created } = await this.api<WpPost>(`/wp/v2/${type}?_embed=wp:featuredmedia`, { method: "POST", body: JSON.stringify(await this.postPayload(data, ctx, type)) });
    const fresh = await this.getEntry(section, String(created.id));
    return { id: String(created.id), before: null, after: fresh?.data ?? pickFields(section, data) };
  }

  async updateEntry(section: Section, id: string, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const type = String(section.remote?.type ?? "posts");
    const current = await this.getEntry(section, id);
    if (!current) throw new ConflictError("élément supprimé");
    if (expected && !deepEqual(pickFields(section, current.data), pickFields(section, expected))) throw new ConflictError();
    const merged = { ...current.data, ...data };
    if (type === "woocommerce") {
      const { data: raw } = await this.api<WooProduct>(`/wc/v3/products/${id}`, { woo: true });
      const { data: updated } = await this.api<WooProduct>(`/wc/v3/products/${id}`, { method: "PUT", woo: true, body: JSON.stringify(await this.productPayload(merged, raw, ctx)) });
      return { id, before: current.data, after: pickFields(section, this.productToData(updated)) };
    }
    await this.api(`/wp/v2/${type}/${id}`, { method: "POST", body: JSON.stringify(await this.postPayload(merged, ctx, type)) });
    const fresh = await this.getEntry(section, id);
    return { id, before: current.data, after: fresh?.data ?? pickFields(section, merged) };
  }

  async deleteEntry(section: Section, id: string, expected: Data | null): Promise<WriteResult> {
    const type = String(section.remote?.type ?? "posts");
    const current = await this.getEntry(section, id);
    if (!current) throw new ConflictError("élément déjà supprimé");
    if (expected && !deepEqual(pickFields(section, current.data), pickFields(section, expected))) throw new ConflictError();
    // Mise à la corbeille (pas de suppression définitive) : récupérable depuis WordPress.
    if (type === "woocommerce") await this.api(`/wc/v3/products/${id}`, { method: "DELETE", woo: true });
    else await this.api(`/wp/v2/${type}/${id}`, { method: "DELETE" });
    return { id, before: current.data, after: null };
  }

  async reorder(section: Section, orderedIds: string[]): Promise<WriteResult> {
    if (section.remote?.type !== "woocommerce") throw new AdapterError("unsupported", "L'ordre de cette rubrique ne peut pas être modifié.");
    const current = await this.listEntries(section);
    const beforeOrder = current.map((e) => e.id);
    if ([...orderedIds].sort().join() !== [...beforeOrder].sort().join()) throw new ConflictError("la liste a changé");
    const update = orderedIds.map((id, i) => ({ id: Number(id), menu_order: i }));
    for (let i = 0; i < update.length; i += 100) {
      await this.api("/wc/v3/products/batch", { method: "POST", woo: true, body: JSON.stringify({ update: update.slice(i, i + 100) }) });
    }
    return { before: null, after: null, beforeOrder, afterOrder: orderedIds };
  }
}
