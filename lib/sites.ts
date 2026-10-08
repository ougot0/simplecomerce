import "server-only";
import { createAdapter, parseConnectorValues, sftpFingerprint } from "./adapters/registry";
import type { ConnectorId } from "./adapters/catalog";
import { AdapterError, type ConnectionReport, type SiteAdapter } from "./adapters/types";
import { parseContentSchema, type ContentSchema } from "./content/schema";
import { fingerprint, seal, unseal } from "./vault";
import { getStore, type Site } from "./store";
import { log } from "./log";

/** Ouvre la connexion vers un site. Les secrets ne quittent jamais cette fonction. */
export async function openAdapter(site: Site, schema?: ContentSchema | null): Promise<SiteAdapter> {
  const store = getStore();
  const creds = await store.getCredentials(site.id);
  const secrets = creds ? unseal<Record<string, string>>(creds.sealed, site.id) : {};
  const activeSchema = schema === undefined ? (await store.getActiveSchema(site.id))?.definition ?? null : schema;
  return createAdapter(site.connector, site.connectorConfig, secrets, { siteId: site.id, publicUrl: site.publicUrl, schema: activeSchema });
}

export async function withAdapter<T>(site: Site, fn: (adapter: SiteAdapter) => Promise<T>, schema?: ContentSchema | null): Promise<T> {
  const adapter = await openAdapter(site, schema);
  try {
    return await fn(adapter);
  } finally {
    await adapter.close?.().catch(() => undefined);
  }
}

export async function storeSecrets(siteId: string, secrets: Record<string, string>, userId: string) {
  const fingerprints: Record<string, string> = {};
  for (const [k, v] of Object.entries(secrets)) if (v) fingerprints[k] = fingerprint({ [k]: v });
  await getStore().setCredentials({ siteId, sealed: seal(secrets, siteId), fingerprints, updatedAt: new Date().toISOString(), updatedBy: userId });
}

export async function existingSecrets(siteId: string): Promise<Record<string, string> | null> {
  const creds = await getStore().getCredentials(siteId);
  return creds ? unseal<Record<string, string>>(creds.sealed, siteId) : null;
}

/** Teste des identifiants (enregistrés ou non) sans rien écrire sur le site. */
export async function testConnection(
  connector: ConnectorId,
  config: Record<string, unknown>,
  secrets: Record<string, string>,
  ctx: { siteId: string; publicUrl: string },
): Promise<{ report: ConnectionReport; fingerprint: string | null }> {
  let adapter: SiteAdapter | null = null;
  try {
    adapter = await createAdapter(connector, config, secrets, { ...ctx, schema: null });
    const report = await adapter.testConnection();
    return { report, fingerprint: sftpFingerprint(adapter) };
  } catch (err) {
    log.warn("test de connexion échoué", { connector, err });
    return {
      report: { ok: false, checks: [{ label: "Connexion", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Connexion impossible." }] },
      fingerprint: null,
    };
  } finally {
    await adapter?.close?.().catch(() => undefined);
  }
}

export async function discoverSchema(site: Site): Promise<{ schema: ContentSchema; notes: string[] }> {
  return withAdapter(site, async (adapter) => {
    const result = await adapter.discover();
    return { schema: parseContentSchema(result.schema), notes: result.notes };
  }, null);
}

export { parseConnectorValues };
