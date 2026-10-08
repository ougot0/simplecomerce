"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { randomUUID } from "node:crypto";
import { audit, requireSiteAccess, requireViewer } from "@/lib/access";
import { CONNECTORS, type ConnectorId } from "@/lib/adapters/catalog";
import type { ConnectionReport } from "@/lib/adapters/types";
import { slugify } from "@/lib/content/paths";
import { appMode } from "@/lib/env";
import { log } from "@/lib/log";
import { discoverSchema, existingSecrets, parseConnectorValues, storeSecrets, testConnection } from "@/lib/sites";
import { getStore } from "@/lib/store";

export interface ConnectState {
  report?: ConnectionReport;
  error?: string;
  fieldErrors?: Record<string, string>;
  saved?: boolean;
}

function formValues(form: FormData): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of form.entries()) if (typeof v === "string" && k.startsWith("c_")) out[k.slice(2)] = v;
  return out;
}

function validConnector(id: unknown): ConnectorId | null {
  const def = CONNECTORS.find((c) => c.id === id);
  if (!def || (def.demoOnly && appMode() !== "demo")) return null;
  return def.id;
}

function checkPublicUrl(raw: string): string | null {
  if (appMode() === "demo" && raw.startsWith("/demo-sites/")) return raw;
  try {
    const url = new URL(raw.startsWith("http") ? raw : `https://${raw}`);
    if (url.protocol !== "https:" && url.protocol !== "http:") return null;
    return url.toString().replace(/\/$/, "");
  } catch {
    return null;
  }
}

async function uniqueSlug(name: string): Promise<string> {
  const store = getStore();
  const base = slugify(name).slice(0, 50) || "site";
  let slug = base;
  let n = 2;
  while (await store.slugExists(slug)) slug = `${base}-${n++}`;
  return slug;
}

/** Relier un nouveau site (n'importe quel compte peut le faire : il en devient propriétaire). */
export async function connectSiteAction(_prev: ConnectState, form: FormData): Promise<ConnectState> {
  const viewer = await requireViewer();
  const connector = validConnector(form.get("connector"));
  if (!connector) return { error: "Choisissez le type de votre site." };

  const name = String(form.get("name") ?? "").trim();
  const publicUrlRaw = String(form.get("publicUrl") ?? "").trim();
  const fieldErrors: Record<string, string> = {};
  if (name.length < 2 || name.length > 120) fieldErrors.name = "Indiquez le nom de votre site ou de votre commerce.";
  const publicUrl = checkPublicUrl(publicUrlRaw);
  if (!publicUrl) fieldErrors.publicUrl = "Indiquez l'adresse de votre site, par exemple https://www.mon-site.fr";

  const { config, secrets, errors } = await parseConnectorValues(connector, formValues(form), null);
  for (const [k, v] of Object.entries(errors)) fieldErrors[`c_${k}`] = v;
  if (Object.keys(fieldErrors).length) return { fieldErrors };

  const provisionalId = randomUUID();
  const { report, fingerprint } = await testConnection(connector, config, secrets, { siteId: provisionalId, publicUrl: publicUrl! });
  if (form.get("intent") === "test") return { report };
  if (!report.ok && form.get("force") !== "on") {
    return { report, error: "La connexion ne fonctionne pas encore. Corrigez les points signalés, puis réessayez." };
  }
  if (fingerprint && connector === "sftp" && !config.hostFingerprint) config.hostFingerprint = fingerprint;

  const store = getStore();
  const site = await store.createSite(
    { slug: await uniqueSlug(name), name, publicUrl: publicUrl!, connector, connectorConfig: config, status: "active", createdBy: viewer.effective.id },
    viewer.effective.id,
  );
  await storeSecrets(site.id, secrets, viewer.user.id);
  await store.updateSite(site.id, { lastCheckAt: new Date().toISOString(), lastCheckOk: report.ok });
  await audit(viewer, "site_created", { name, connector }, site.id);

  let notes: string[] = [];
  try {
    const discovered = await discoverSchema({ ...site, connectorConfig: config });
    await store.saveSchema(site.id, discovered.schema, viewer.user.id);
    notes = discovered.notes;
  } catch (err) {
    log.warn("détection du contenu impossible", { site: site.slug, err });
    notes = ["Le contenu n'a pas pu être lu automatiquement. Votre administrateur peut définir les rubriques à la main."];
  }
  await audit(viewer, "schema_detected", { notes }, site.id);
  redirect(`/s/${site.slug}?bienvenue=1`);
}

/** Modifier les accès d'un site existant (propriétaire ou administrateur). */
export async function updateConnectionAction(_prev: ConnectState, form: FormData): Promise<ConnectState> {
  const { viewer, site } = await requireSiteAccess(String(form.get("site")), "owner");
  const name = String(form.get("name") ?? "").trim();
  const publicUrl = checkPublicUrl(String(form.get("publicUrl") ?? "").trim());
  const fieldErrors: Record<string, string> = {};
  if (name.length < 2 || name.length > 120) fieldErrors.name = "Nom invalide.";
  if (!publicUrl) fieldErrors.publicUrl = "Adresse invalide.";
  const previous = await existingSecrets(site.id);
  const { config, secrets, errors } = await parseConnectorValues(site.connector, formValues(form), previous);
  for (const [k, v] of Object.entries(errors)) fieldErrors[`c_${k}`] = v;
  if (Object.keys(fieldErrors).length) return { fieldErrors };

  // Une empreinte SFTP déjà mémorisée ne se change pas par erreur : seul un champ explicite la remplace.
  if (site.connector === "sftp" && !config.hostFingerprint && site.connectorConfig.hostFingerprint) config.hostFingerprint = site.connectorConfig.hostFingerprint;
  const { report, fingerprint } = await testConnection(site.connector, config, secrets, { siteId: site.id, publicUrl: publicUrl! });
  if (form.get("intent") === "test") return { report };
  if (fingerprint && site.connector === "sftp" && !config.hostFingerprint) config.hostFingerprint = fingerprint;

  const store = getStore();
  await store.updateSite(site.id, { name, publicUrl: publicUrl!, connectorConfig: config, lastCheckAt: new Date().toISOString(), lastCheckOk: report.ok });
  const changedSecrets = Object.keys(secrets).filter((k) => secrets[k] !== previous?.[k]);
  if (changedSecrets.length) await storeSecrets(site.id, secrets, viewer.user.id);
  await audit(viewer, "connection_updated", { fields: changedSecrets, ok: report.ok }, site.id);
  revalidatePath(`/s/${site.slug}`, "layout");
  return { report, saved: true };
}
