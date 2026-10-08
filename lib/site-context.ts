import "server-only";
import { cache } from "react";
import { notFound } from "next/navigation";
import { requireSiteAccess } from "./access";
import { findSection, type ContentSchema, type Section } from "./content/schema";
import { getStore } from "./store";
import type { Role } from "./store/types";

export const loadSite = cache(async (slug: string, minimum: Role = "editor") => {
  const ctx = await requireSiteAccess(slug, minimum);
  const schemaRecord = await getStore().getActiveSchema(ctx.site.id);
  const schema: ContentSchema = schemaRecord?.definition ?? { version: 1, sections: [] };
  return { ...ctx, schema, sections: schema.sections.filter((s) => !s.hidden) };
});

export async function loadSection(slug: string, key: string): Promise<Awaited<ReturnType<typeof loadSite>> & { section: Section }> {
  const ctx = await loadSite(slug);
  const section = findSection(ctx.schema, key);
  if (!section || section.hidden) notFound();
  return { ...ctx, section };
}

/** Délai d'apparition sur le site, selon la façon dont il est relié. */
export function publishDelayText(connector: string): string {
  return ["github", "gitlab", "bitbucket"].includes(connector)
    ? "Votre site sera à jour d'ici une à deux minutes, le temps qu'il se reconstruise."
    : "C'est déjà en ligne. Pensez à rafraîchir la page de votre site.";
}
