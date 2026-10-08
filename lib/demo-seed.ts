import "server-only";
import { appMode } from "./env";
import { demoCreateUser } from "./auth";
import { withDb } from "./store/file-store";
import { getStore } from "./store";
import { discoverSchema, storeSecrets } from "./sites";
import { log } from "./log";

/**
 * Données du mode démonstration : un administrateur, deux clients, deux sites
 * construits différemment (un fichier content.json à la OVH, et un site Astro en Markdown/YAML).
 */

export const DEMO_ACCOUNTS = [
  { email: "alex@simplecommerce.demo", password: "atelier-demo-2026", fullName: "Alex (administrateur)", admin: true },
  { email: "marie@patisserie-lune.fr", password: "tarte-citron-2026", fullName: "Marie Lefort", admin: false },
  { email: "paul@atelier-brun.fr", password: "maison-bois-2026", fullName: "Paul Brun", admin: false },
];

let seeding: Promise<void> | null = null;

export function ensureDemoSeed(): Promise<void> {
  if (appMode() !== "demo") return Promise.resolve();
  // Recontrôlé à chaque fois : supprimer le dossier .data/ suffit à repartir de zéro.
  if (!seeding) {
    seeding = seed()
      .catch((err) => log.error("initialisation de la démo impossible", err))
      .finally(() => {
        seeding = null;
      });
  }
  return seeding;
}

async function seed() {
  const already = await withDb((db) => db.sites.length > 0);
  if (already) return;
  const ids: string[] = [];
  for (const a of DEMO_ACCOUNTS) ids.push(await demoCreateUser(a.email, a.password, a.fullName, a.admin));
  const [, marie, paul] = ids;
  const store = getStore();

  const lune = await store.createSite(
    {
      slug: "patisserie-lune",
      name: "Pâtisserie Lune",
      publicUrl: "/demo-sites/patisserie-lune",
      connector: "demo",
      connectorConfig: { folder: "patisserie-lune", contentDir: "auto", mediaDir: "images/simplecommerce", mediaPublicPrefix: "/images/simplecommerce" },
      status: "active",
      createdBy: marie,
    },
    marie,
  );
  const brun = await store.createSite(
    {
      slug: "atelier-brun",
      name: "Atelier Brun architectes",
      publicUrl: "/demo-sites/atelier-brun",
      connector: "demo",
      connectorConfig: { folder: "atelier-brun", contentDir: "auto", mediaDir: "auto" },
      status: "active",
      createdBy: paul,
    },
    paul,
  );

  for (const [site, owner] of [[lune, marie], [brun, paul]] as const) {
    await storeSecrets(site.id, {}, owner);
    const { schema } = await discoverSchema(site);
    await store.saveSchema(site.id, schema, owner);
    await store.updateSite(site.id, { lastCheckAt: new Date().toISOString(), lastCheckOk: true });
    await store.logEvent({ actorId: owner, onBehalfOf: null, siteId: site.id, kind: "site_created", details: { name: site.name } });
  }
}
