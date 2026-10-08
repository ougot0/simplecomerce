import type { Section } from "@/lib/content/schema";

/** Le champ « visible / en vente / disponible » d'une rubrique, s'il existe. */
export function visibilityField(section: Section): string | undefined {
  return section.fields.find((f) => f.type === "boolean" && !f.hidden && /(disponible|available|envente|publie|published|visible|active|enligne|instock)/i.test(f.key))?.key;
}
