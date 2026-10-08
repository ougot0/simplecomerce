import type { Field, Section } from "./schema";

/**
 * Validation côté serveur des valeurs envoyées par un formulaire.
 * Les champs masqués ou en lecture seule gardent toujours leur valeur actuelle.
 * Les clés inconnues du schéma sont ignorées en entrée et conservées depuis la version actuelle.
 */

export type FieldErrors = Record<string, string>;
export type Data = Record<string, unknown>;

export const MEDIA_TOKEN_PREFIX = "sc-media:";

const URL_RE = /^(https?:\/\/[^\s]+|\/[^\s]*|#[^\s]*|mailto:[^\s]+|tel:[^\s]+)$/i;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE_RE = /^[+()\d\s.\-]{4,25}$/;
const DATE_RE = /^\d{4}-\d{2}-\d{2}([T ][\d:.]+(Z|[+\-]\d{2}:?\d{2})?)?$/;
const IMAGE_RE = /^(https?:\/\/|\/|\.{0,2}\/?[\w\-./]+|sc-media:[\w\-]+$)/;

export interface NormalizeOptions {
  sanitizeHtml: (html: string) => string;
}

export function normalizeData(
  fields: Field[],
  input: Data,
  current: Data,
  options: NormalizeOptions,
  pathPrefix = "",
): { data: Data; errors: FieldErrors } {
  const data: Data = { ...current };
  const errors: FieldErrors = {};

  for (const field of fields) {
    const path = pathPrefix + field.key;
    if (field.hidden || field.readOnly) continue;
    if (!(field.key in input)) continue;
    const raw = input[field.key];
    const result = normalizeValue(field, raw, current[field.key], options, path);
    if (result.error) errors[path] = result.error;
    Object.assign(errors, result.nested ?? {});
    if (!result.error) data[field.key] = result.value;
  }

  for (const field of fields) {
    if (!field.required || field.hidden) continue;
    const path = pathPrefix + field.key;
    if (errors[path]) continue;
    if (isEmpty(data[field.key])) errors[path] = "Ce champ est obligatoire.";
  }

  return { data, errors };
}

function isEmpty(v: unknown): boolean {
  return v === undefined || v === null || v === "" || (Array.isArray(v) && v.length === 0);
}

interface ValueResult {
  value?: unknown;
  error?: string;
  nested?: FieldErrors;
}

function normalizeValue(field: Field, raw: unknown, current: unknown, options: NormalizeOptions, path: string): ValueResult {
  const tooLong = (s: string) => field.maxLength !== undefined && s.length > field.maxLength;

  switch (field.type) {
    case "text":
    case "textarea":
    case "markdown": {
      if (raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string") return { error: "Texte attendu." };
      const value = field.type === "text" ? raw.replace(/[\r\n]+/g, " ") : raw.replace(/\r\n/g, "\n");
      if (tooLong(value)) return { error: `${field.maxLength} caractères au maximum (actuellement ${value.length}).` };
      return { value };
    }
    case "richtext": {
      if (raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string") return { error: "Texte attendu." };
      const value = options.sanitizeHtml(raw);
      const visible = value.replace(/<[^>]*>/g, "");
      if (tooLong(visible)) return { error: `${field.maxLength} caractères au maximum (actuellement ${visible.length}).` };
      return { value };
    }
    case "price": {
      const store = field.priceFormat?.store ?? "number";
      if (raw === null || raw === "" || raw === undefined) return { value: store === "string" ? "" : null };
      if (store === "string") {
        if (typeof raw !== "string" || !/\d/.test(raw) || raw.length > 30) return { error: "Prix invalide." };
        return { value: raw };
      }
      if (typeof raw !== "number" || !Number.isFinite(raw) || raw < 0 || raw > 10_000_000) return { error: "Prix invalide." };
      if (store === "cents" && !Number.isInteger(raw)) return { error: "Prix invalide." };
      return { value: raw };
    }
    case "number": {
      if (raw === null || raw === "" || raw === undefined) return { value: null };
      const n = typeof raw === "string" ? Number(raw.replace(",", ".")) : raw;
      if (typeof n !== "number" || !Number.isFinite(n)) return { error: "Nombre attendu." };
      if (field.min !== undefined && n < field.min) return { error: `Minimum : ${field.min}.` };
      if (field.max !== undefined && n > field.max) return { error: `Maximum : ${field.max}.` };
      return { value: n };
    }
    case "boolean":
      if (typeof raw !== "boolean") return { error: "Oui ou non attendu." };
      return { value: raw };
    case "select": {
      if (raw === "" || raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string" || !(field.options ?? []).some((o) => o.value === raw)) return { error: "Choix invalide." };
      return { value: raw };
    }
    case "date": {
      if (raw === "" || raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string" || !DATE_RE.test(raw)) return { error: "Date invalide." };
      return { value: raw };
    }
    case "image": {
      if (raw === "" || raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string" || raw.length > 1000 || !IMAGE_RE.test(raw) || /^javascript:/i.test(raw)) {
        return { error: "Image invalide." };
      }
      return { value: raw };
    }
    case "gallery":
    case "list": {
      if (raw === null || raw === undefined) return { value: [] };
      if (!Array.isArray(raw) || raw.length > 200) return { error: "Liste invalide." };
      for (const item of raw) {
        if (typeof item !== "string" || item.length > 1000) return { error: "Liste invalide." };
        if (field.type === "gallery" && (!IMAGE_RE.test(item) || /^javascript:/i.test(item))) return { error: "Image invalide." };
      }
      return { value: raw };
    }
    case "url": {
      if (raw === "" || raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string" || !URL_RE.test(raw.trim())) return { error: "Adresse invalide (elle doit commencer par https://)." };
      return { value: raw.trim() };
    }
    case "email": {
      if (raw === "" || raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string" || !EMAIL_RE.test(raw.trim())) return { error: "Adresse e-mail invalide." };
      return { value: raw.trim() };
    }
    case "phone": {
      if (raw === "" || raw === null || raw === undefined) return { value: "" };
      if (typeof raw !== "string" || !PHONE_RE.test(raw.trim())) return { error: "Numéro invalide." };
      return { value: raw.trim() };
    }
    case "group": {
      if (raw === null || raw === undefined) return { value: current ?? {} };
      if (typeof raw !== "object" || Array.isArray(raw)) return { error: "Bloc invalide." };
      const cur = current && typeof current === "object" && !Array.isArray(current) ? (current as Data) : {};
      const r = normalizeData(field.fields ?? [], raw as Data, cur, options, `${path}.`);
      return Object.keys(r.errors).length ? { error: undefined, value: r.data, nested: r.errors } : { value: r.data };
    }
    case "repeater": {
      if (raw === null || raw === undefined) return { value: [] };
      if (!Array.isArray(raw) || raw.length > 500) return { error: "Liste invalide." };
      const curList = Array.isArray(current) ? (current as Data[]) : [];
      if (field.fixedRows && raw.length !== curList.length) return { error: "Le nombre de lignes ne peut pas être modifié ici." };
      const out: Data[] = [];
      const nested: FieldErrors = {};
      raw.forEach((item, i) => {
        if (!item || typeof item !== "object" || Array.isArray(item)) {
          nested[`${path}.${i}`] = "Ligne invalide.";
          return;
        }
        // Une ligne garde ses propriétés inconnues grâce à la clé technique __index (position d'origine).
        const origin = typeof (item as Data).__index === "number" ? curList[(item as Data).__index as number] : undefined;
        const { __index: _ignored, ...rest } = item as Data;
        void _ignored;
        const r = normalizeData(field.fields ?? [], rest, origin && typeof origin === "object" ? origin : {}, options, `${path}.${i}.`);
        Object.assign(nested, r.errors);
        out.push(r.data);
      });
      return Object.keys(nested).length ? { value: out, nested } : { value: out };
    }
  }
}

/** Ne garde que les champs décrits par le schéma : c'est ce que l'on montre et ce que l'on historise. */
export function pickFields(section: Section, data: Data): Data {
  const out: Data = {};
  for (const field of section.fields) {
    if (field.key in data) out[field.key] = data[field.key];
  }
  return out;
}

export function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (typeof a !== typeof b) return false;
  if (a === null || b === null || typeof a !== "object") {
    // Une valeur absente et une chaîne vide sont équivalentes pour le client.
    return (a ?? "") === (b ?? "");
  }
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  if (Array.isArray(a)) {
    const bb = b as unknown[];
    return a.length === bb.length && a.every((v, i) => deepEqual(v, bb[i]));
  }
  const ao = a as Data;
  const bo = b as Data;
  const keys = new Set([...Object.keys(ao), ...Object.keys(bo)]);
  for (const k of keys) if (!deepEqual(ao[k], bo[k])) return false;
  return true;
}

/** Collecte les jetons d'images en attente (« sc-media:… ») présents dans des données. */
export function collectMediaTokens(value: unknown, out = new Set<string>()): Set<string> {
  if (typeof value === "string" && value.startsWith(MEDIA_TOKEN_PREFIX)) out.add(value.slice(MEDIA_TOKEN_PREFIX.length));
  else if (Array.isArray(value)) value.forEach((v) => collectMediaTokens(v, out));
  else if (value && typeof value === "object") Object.values(value).forEach((v) => collectMediaTokens(v, out));
  return out;
}

export function replaceMediaTokens(value: unknown, map: Map<string, string>): unknown {
  if (typeof value === "string" && value.startsWith(MEDIA_TOKEN_PREFIX)) {
    return map.get(value.slice(MEDIA_TOKEN_PREFIX.length)) ?? value;
  }
  if (Array.isArray(value)) return value.map((v) => replaceMediaTokens(v, map));
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, replaceMediaTokens(v, map)]));
  }
  return value;
}
