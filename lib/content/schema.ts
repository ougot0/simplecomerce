import { z } from "zod";

/**
 * Le schéma de contenu décrit ce que le client voit et a le droit de modifier.
 * Tout ce qui n'est pas décrit ici est conservé tel quel dans les fichiers du site.
 */

export const FIELD_TYPES = [
  "text",
  "textarea",
  "richtext",
  "markdown",
  "price",
  "number",
  "boolean",
  "select",
  "date",
  "image",
  "gallery",
  "url",
  "email",
  "phone",
  "list",
  "group",
  "repeater",
] as const;
export type FieldType = (typeof FIELD_TYPES)[number];

export interface Field {
  key: string;
  label: string;
  type: FieldType;
  help?: string;
  required?: boolean;
  hidden?: boolean;
  readOnly?: boolean;
  maxLength?: number;
  min?: number;
  max?: number;
  options?: { value: string; label: string }[];
  /** Image : format imposé au recadrage, « 4:3 », « 1:1 », ou « libre ». */
  aspect?: string;
  maxWidth?: number;
  /** Prix : manière dont le site stocke la valeur. */
  priceFormat?: { store: "number" | "cents" | "string"; decimal?: "," | "."; prefix?: string; suffix?: string };
  /** group / repeater */
  fields?: Field[];
  itemLabel?: string;
  /** repeater : lignes fixes (pas d'ajout ni de suppression), ex. déclinaisons Shopify. */
  fixedRows?: boolean;
}

const fieldSchema: z.ZodType<Field> = z.lazy(() =>
  z.object({
    key: z.string().min(1).max(80).regex(/^[A-Za-z0-9_$@\-.]+$/, "Clé de champ invalide"),
    label: z.string().min(1).max(80),
    type: z.enum(FIELD_TYPES),
    help: z.string().max(200).optional(),
    required: z.boolean().optional(),
    hidden: z.boolean().optional(),
    readOnly: z.boolean().optional(),
    maxLength: z.number().int().positive().max(100_000).optional(),
    min: z.number().optional(),
    max: z.number().optional(),
    options: z.array(z.object({ value: z.string(), label: z.string() })).optional(),
    aspect: z.string().regex(/^(\d+:\d+|libre)$/).optional(),
    maxWidth: z.number().int().min(200).max(4000).optional(),
    priceFormat: z
      .object({
        store: z.enum(["number", "cents", "string"]),
        decimal: z.enum([",", "."]).optional(),
        prefix: z.string().max(10).optional(),
        suffix: z.string().max(10).optional(),
      })
      .optional(),
    fields: z.array(fieldSchema).optional(),
    itemLabel: z.string().max(40).optional(),
    fixedRows: z.boolean().optional(),
  }),
);

const relativePath = z
  .string()
  .min(1)
  .max(300)
  .refine((p) => !p.split(/[\\/]/).includes("..") && !p.startsWith("/") && !p.includes("\0"), "Chemin invalide");

export const fileSourceSchema = z.discriminatedUnion("type", [
  z.object({
    type: z.literal("file"),
    file: relativePath,
    format: z.enum(["json", "yaml"]).optional(),
    /** Chemin à l'intérieur du fichier, « produits » ou « boutique.produits ». Vide = racine. */
    path: z.string().max(200).optional(),
  }),
  z.object({
    type: z.literal("folder"),
    folder: relativePath,
    format: z.enum(["markdown", "json", "yaml"]),
    extension: z.string().max(10).optional(),
    bodyField: z.string().max(80).optional(),
    orderField: z.string().max(80).optional(),
  }),
]);
export type FileSource = z.infer<typeof fileSourceSchema>;

export const sectionSchema = z.object({
  key: z.string().min(1).max(80).regex(/^[a-z0-9-]+$/, "Identifiant de section : minuscules, chiffres et tirets"),
  label: z.string().min(1).max(80),
  kind: z.enum(["collection", "singleton"]),
  itemLabel: z.string().max(40).optional(),
  titleField: z.string().optional(),
  imageField: z.string().optional(),
  subtitleField: z.string().optional(),
  idField: z.string().optional(),
  orderable: z.boolean().optional(),
  allowCreate: z.boolean().optional(),
  allowDelete: z.boolean().optional(),
  hidden: z.boolean().optional(),
  source: fileSourceSchema.optional(),
  /** Données propres à un connecteur (identifiant de collection Webflow, type WordPress…). */
  remote: z.record(z.string(), z.unknown()).optional(),
  fields: z.array(fieldSchema).min(1),
});
export type Section = z.infer<typeof sectionSchema>;

export const contentSchemaSchema = z.object({
  version: z.literal(1),
  /** Dossier de contenu détecté (sites à fichiers). */
  contentDir: z.union([z.literal(""), relativePath]).optional(),
  sections: z.array(sectionSchema).max(60),
  media: z
    .object({
      dir: relativePath,
      publicPrefix: z.string().max(300),
    })
    .optional(),
});
export type ContentSchema = z.infer<typeof contentSchemaSchema>;

export function parseContentSchema(input: unknown): ContentSchema {
  const schema = contentSchemaSchema.parse(input);
  const keys = new Set<string>();
  for (const section of schema.sections) {
    if (keys.has(section.key)) throw new Error(`Section en double : ${section.key}`);
    keys.add(section.key);
  }
  return schema;
}

export function findSection(schema: ContentSchema, key: string): Section | undefined {
  return schema.sections.find((s) => s.key === key);
}

export function visibleFields(fields: Field[]): Field[] {
  return fields.filter((f) => !f.hidden);
}

/** Texte qui représente un élément dans les listes et l'historique. */
export function entryTitle(section: Section, data: Record<string, unknown>): string {
  const key = section.titleField ?? section.fields.find((f) => f.type === "text")?.key;
  const value = key ? data[key] : undefined;
  if (typeof value === "string" && value.trim()) return value.trim().slice(0, 120);
  if (typeof value === "number") return String(value);
  return section.kind === "singleton" ? section.label : `${section.itemLabel ?? "Élément"} sans titre`;
}
