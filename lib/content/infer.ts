import { detectPriceFormat } from "./price";
import { formatFromPath, parseFile } from "./formats";
import { slugify } from "./paths";
import type { ContentSchema, Field, FieldType, Section } from "./schema";

/**
 * Détection automatique : à partir des fichiers de contenu d'un site, on propose un schéma.
 * Le client ou l'administrateur peut ensuite renommer, masquer ou réordonner les champs.
 */

type Data = Record<string, unknown>;

const LABELS: Record<string, string> = {
  title: "Titre", titre: "Titre", name: "Nom", nom: "Nom", label: "Libellé",
  description: "Description", desc: "Description", summary: "Résumé", resume: "Résumé", excerpt: "Résumé",
  content: "Texte", body: "Texte", text: "Texte", texte: "Texte", contenu: "Texte",
  price: "Prix", prix: "Prix", tarif: "Tarif", compareatprice: "Prix barré", oldprice: "Ancien prix",
  image: "Photo", photo: "Photo", img: "Photo", picture: "Photo", cover: "Photo principale", thumbnail: "Vignette",
  images: "Photos", photos: "Photos", gallery: "Galerie", galerie: "Galerie",
  available: "Disponible", disponible: "Disponible", instock: "En stock", stock: "Stock", active: "Visible", visible: "Visible",
  published: "Publié", featured: "Mis en avant", category: "Catégorie", categorie: "Catégorie", tags: "Mots-clés",
  phone: "Téléphone", tel: "Téléphone", telephone: "Téléphone", email: "E-mail", mail: "E-mail",
  address: "Adresse", adresse: "Adresse", city: "Ville", ville: "Ville", zip: "Code postal", cp: "Code postal",
  hours: "Horaires", horaires: "Horaires", openinghours: "Horaires", day: "Jour", jour: "Jour",
  date: "Date", url: "Lien", link: "Lien", lien: "Lien", subtitle: "Sous-titre", soustitre: "Sous-titre",
  slug: "Adresse de la page", weight: "Poids", order: "Ordre", ordre: "Ordre", alt: "Description de la photo",
  quantity: "Quantité", ingredients: "Ingrédients", allergenes: "Allergènes", allergens: "Allergènes",
  // Mots français souvent écrits sans accents dans les noms de fichiers.
  gateaux: "Gâteaux", gateau: "Gâteau", actualites: "Actualités", actualite: "Actualité", evenements: "Événements",
  equipe: "Équipe", realisations: "Réalisations", creations: "Créations", presentation: "Présentation",
  infos: "Infos pratiques", entree: "Entrée", entrees: "Entrées", desserts: "Desserts", boissons: "Boissons",
  carte: "Carte", menu: "Menu", projets: "Projets", services: "Services", prestations: "Prestations",
  temoignages: "Témoignages", faq: "Questions fréquentes", accroche: "Accroche", annee: "Année", lieu: "Lieu",
  surface: "Surface", categories: "Catégories", collections: "Collections", site: "Informations générales",
  accueil: "Accueil", apropos: "À propos", horairesouverture: "Horaires d'ouverture", heures: "Heures", reseaux: "Réseaux sociaux",
};

const ID_KEYS = ["id", "_id", "uuid", "slug", "sku", "ref", "reference"];
const TITLE_KEYS = ["name", "nom", "title", "titre", "label", "libelle"];
const IMAGE_EXT = /\.(jpe?g|png|webp|gif|avif|svg)(\?.*)?$/i;
const IMAGE_KEY = /(image|photo|img|picture|cover|thumbnail|visuel|logo|banner|banniere|avatar)/i;
const PRICE_KEY = /(price|prix|tarif|cost|amount|montant)/i;

export function humanize(key: string): string {
  const compact = key.toLowerCase().replace(/[_\-\s]/g, "");
  if (LABELS[compact]) return LABELS[compact];
  const words = key
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(/[_\-.]+/g, " ")
    .trim()
    .toLowerCase();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

function isPlainObject(v: unknown): v is Data {
  return !!v && typeof v === "object" && !Array.isArray(v);
}

function looksLikeImage(key: string, values: unknown[]): boolean {
  const strings = values.filter((v): v is string => typeof v === "string" && v.length > 0);
  if (strings.length === 0) return IMAGE_KEY.test(key);
  return strings.every((s) => IMAGE_EXT.test(s) || /^https?:\/\/.*(cdn|images?|media|uploads?)/i.test(s)) ||
    (IMAGE_KEY.test(key) && strings.every((s) => !/\s/.test(s)));
}

function inferType(key: string, values: unknown[]): Field {
  const present = values.filter((v) => v !== undefined && v !== null);
  const label = humanize(key);
  const base = { key, label };
  const lowerKey = key.toLowerCase();

  if (ID_KEYS.includes(lowerKey)) return { ...base, type: "text", hidden: true };
  if (present.length === 0) return { ...base, type: IMAGE_KEY.test(key) ? "image" : "text" };

  if (present.every((v) => typeof v === "boolean")) return { ...base, type: "boolean" };

  if (PRICE_KEY.test(key) && present.every((v) => typeof v === "number" || (typeof v === "string" && /\d/.test(v) && v.length < 20))) {
    return { ...base, type: "price", priceFormat: detectPriceFormat(present) };
  }
  if (present.every((v) => typeof v === "number")) return { ...base, type: "number" };

  if (present.every((v) => Array.isArray(v))) {
    const items = (present as unknown[][]).flat();
    if (items.length === 0 || items.every((i) => typeof i === "string")) {
      return looksLikeImage(key, items) && items.length > 0
        ? { ...base, type: "gallery" }
        : { ...base, type: "list" };
    }
    if (items.every(isPlainObject)) {
      return { ...base, type: "repeater", itemLabel: "ligne", fields: inferFields(items as Data[]) };
    }
    return { ...base, type: "list", hidden: true };
  }

  if (present.every(isPlainObject)) {
    return { ...base, type: "group", fields: inferFields(present as Data[]) };
  }

  if (present.every((v) => typeof v === "string")) {
    const strings = present as string[];
    if (looksLikeImage(key, strings)) return { ...base, type: "image", aspect: "libre" };
    if (strings.every((s) => /^\d{4}-\d{2}-\d{2}/.test(s))) return { ...base, type: "date" };
    if (strings.every((s) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s))) return { ...base, type: "email" };
    if (/(phone|tel|mobile|portable)/i.test(key)) return { ...base, type: "phone" };
    if (strings.every((s) => /^(https?:\/\/|\/)/.test(s)) && !/\s/.test(strings.join(""))) return { ...base, type: "url" };
    if (strings.some((s) => /<\/?(p|strong|em|br|a|ul|li|b|i)\b/i.test(s))) return { ...base, type: "richtext" };
    const longest = Math.max(...strings.map((s) => s.length));
    if (strings.some((s) => s.includes("\n")) || longest > 90 || /(description|desc|texte|text|content|body|resume|summary|bio|intro)/i.test(key)) {
      return { ...base, type: "textarea" };
    }
    return { ...base, type: "text" };
  }

  // Types mélangés : on ne prend pas le risque de laisser modifier.
  return { ...base, type: "text", hidden: true };
}

export function inferFields(samples: Data[]): Field[] {
  const keys: string[] = [];
  for (const sample of samples) for (const key of Object.keys(sample)) if (!keys.includes(key)) keys.push(key);
  return keys.map((key) => inferType(key, samples.map((s) => s[key])));
}

function pickKey(fields: Field[], candidates: string[], type?: FieldType): string | undefined {
  for (const c of candidates) {
    const f = fields.find((x) => x.key.toLowerCase() === c && !x.hidden);
    if (f) return f.key;
  }
  return type ? fields.find((f) => f.type === type && !f.hidden)?.key : undefined;
}

function collectionSection(key: string, label: string, items: Data[], source: Section["source"]): Section {
  const fields = inferFields(items);
  const idField = ID_KEYS.find((k) => {
    const values = items.map((i) => i[k]);
    return values.every((v) => typeof v === "string" || typeof v === "number") && new Set(values.map(String)).size === values.length;
  });
  const titleField = pickKey(fields, TITLE_KEYS, "text");
  const imageField = pickKey(fields, [], "image");
  const subtitleField = fields.find((f) => f.type === "price")?.key;
  // Aperçu au format des photos existantes : on ne le connaît pas, on reste libre.
  return {
    key,
    label,
    kind: "collection",
    itemLabel: singular(label),
    titleField,
    imageField,
    subtitleField,
    idField,
    orderable: true,
    allowCreate: true,
    allowDelete: true,
    source,
    fields: orderFields(fields, titleField, imageField),
  };
}

function orderFields(fields: Field[], titleField?: string, imageField?: string): Field[] {
  const rank = (f: Field) => (f.key === titleField ? 0 : f.key === imageField ? 1 : f.type === "price" ? 2 : f.hidden ? 9 : 5);
  return [...fields].sort((a, b) => rank(a) - rank(b));
}

function singular(label: string): string {
  const lower = label.toLowerCase();
  if (lower.endsWith("eaux")) return lower.slice(0, -1);
  if (lower.endsWith("aux")) return lower.slice(0, -3) + "al";
  if (lower.endsWith("s") && !lower.endsWith("ss")) return lower.slice(0, -1);
  return lower;
}

function uniqueKey(base: string, used: Set<string>): string {
  let key = slugify(base);
  let i = 2;
  while (used.has(key)) key = `${slugify(base)}-${i++}`;
  used.add(key);
  return key;
}

export interface SourceFile {
  /** Chemin relatif à la racine du dossier de contenu. */
  path: string;
  text: string;
}

/**
 * Construit un schéma à partir des fichiers trouvés.
 * - fichier JSON/YAML contenant un tableau d'objets → une liste (produits, actualités…)
 * - fichier JSON/YAML contenant un objet → ses tableaux d'objets deviennent des listes, le reste un bloc
 * - dossier de fichiers Markdown → une liste dont chaque fichier est un élément
 */
export function inferSchema(files: SourceFile[]): { schema: ContentSchema; skipped: string[] } {
  const sections: Section[] = [];
  const used = new Set<string>();
  const skipped: string[] = [];
  const markdownByFolder = new Map<string, SourceFile[]>();

  for (const file of files) {
    const format = formatFromPath(file.path);
    const baseName = file.path.split("/").pop()!.replace(/\.[^.]+$/, "");
    if (baseName.startsWith("_") || baseName.startsWith("simplecommerce") || /package(-lock)?|tsconfig|manifest/i.test(baseName)) {
      skipped.push(file.path);
      continue;
    }
    if (format === "markdown") {
      const folder = file.path.includes("/") ? file.path.slice(0, file.path.lastIndexOf("/")) : "";
      markdownByFolder.set(folder, [...(markdownByFolder.get(folder) ?? []), file]);
      continue;
    }
    if (!format) {
      skipped.push(file.path);
      continue;
    }
    let data: unknown;
    try {
      data = parseFile(file.text, format).data;
    } catch {
      skipped.push(file.path);
      continue;
    }
    const fileFormat = format as "json" | "yaml";

    if (Array.isArray(data)) {
      if (data.length > 0 && data.every(isPlainObject)) {
        sections.push(collectionSection(uniqueKey(baseName, used), humanize(baseName), data as Data[], { type: "file", file: file.path, format: fileFormat }));
      } else skipped.push(file.path);
      continue;
    }
    if (!isPlainObject(data)) {
      skipped.push(file.path);
      continue;
    }
    const rest: Data = {};
    for (const [key, value] of Object.entries(data)) {
      if (Array.isArray(value) && value.length > 0 && value.every(isPlainObject) && Object.keys(value[0] as Data).length >= 2) {
        sections.push(collectionSection(uniqueKey(key, used), humanize(key), value as Data[], { type: "file", file: file.path, format: fileFormat, path: key }));
      } else if (isPlainObject(value) && Object.keys(data).length > 1 && Object.values(value).some((v) => typeof v === "string")) {
        // Un objet de premier niveau (« contact », « accueil ») devient son propre bloc.
        sections.push({
          key: uniqueKey(key, used),
          label: humanize(key),
          kind: "singleton",
          source: { type: "file", file: file.path, format: fileFormat, path: key },
          fields: inferFields([value]),
        });
      } else {
        rest[key] = value;
      }
    }
    if (Object.keys(rest).length > 0) {
      sections.push({
        key: uniqueKey(baseName, used),
        label: humanize(baseName),
        kind: "singleton",
        source: { type: "file", file: file.path, format: fileFormat },
        fields: inferFields([rest]),
      });
    }
  }

  for (const [folder, mdFiles] of markdownByFolder) {
    const samples: Data[] = [];
    for (const f of mdFiles) {
      try {
        const parsed = parseFile(f.text, "markdown");
        samples.push({ ...(parsed.data as Data), body: parsed.body ?? "" });
      } catch {
        skipped.push(f.path);
      }
    }
    if (samples.length === 0) continue;
    const name = folder.split("/").pop() || "pages";
    const ext = mdFiles[0].path.slice(mdFiles[0].path.lastIndexOf("."));
    const fields = inferFields(samples).map((f) => (f.key === "body" ? { ...f, type: "markdown" as const, label: "Texte" } : f));
    const orderField = fields.find((f) => ["order", "ordre", "weight", "position"].includes(f.key.toLowerCase()) && f.type === "number")?.key;
    const titleField = pickKey(fields, TITLE_KEYS, "text");
    const imageField = pickKey(fields, [], "image");
    sections.push({
      key: uniqueKey(name, used),
      label: humanize(name),
      kind: "collection",
      itemLabel: singular(humanize(name)),
      titleField,
      imageField,
      orderable: !!orderField,
      allowCreate: true,
      allowDelete: true,
      source: { type: "folder", folder, format: "markdown", extension: ext, bodyField: "body", orderField },
      fields: orderFields(fields, titleField, imageField).map((f) => (f.key === orderField ? { ...f, hidden: true } : f)),
    });
  }

  return { schema: { version: 1, sections }, skipped };
}
