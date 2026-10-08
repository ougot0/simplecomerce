import "server-only";
import { AdapterError, ConflictError, userMessageFor, type ChangeContext, type Data, type SiteStatus, type WriteResult } from "./adapters/types";
import { entryTitle, type Section } from "./content/schema";
import { collectMediaTokens } from "./content/values";
import type { Viewer } from "./access";
import { loadAssets } from "./media";
import { withAdapter } from "./sites";
import { getStore, type Change, type Site } from "./store";
import { log } from "./log";

/**
 * Toute modification d'un site passe par ici : elle est journalisée avant d'être envoyée,
 * puis marquée réussie ou échouée. C'est ce journal qui permet d'annuler.
 */

export type Operation =
  | { type: "create"; section: Section; data: Data }
  | { type: "update"; section: Section; id: string; data: Data; expected: Data | null }
  | { type: "delete"; section: Section; id: string; expected: Data | null }
  | { type: "reorder"; section: Section; ids: string[] }
  | { type: "singleton"; section: Section; data: Data; expected: Data | null };

export type PublishResult = { ok: true; change: Change; result: WriteResult } | { ok: false; error: string; conflict?: boolean };

function describe(op: Operation, label: string): string {
  switch (op.type) {
    case "create":
      return `« ${label} » ajouté(e) dans ${op.section.label}`;
    case "update":
      return `« ${label} » modifié(e) dans ${op.section.label}`;
    case "delete":
      return `« ${label} » supprimé(e) de ${op.section.label}`;
    case "reorder":
      return `Ordre de ${op.section.label} modifié`;
    case "singleton":
      return `${op.section.label} modifié(e)`;
  }
}

function labelFor(op: Operation): string {
  if (op.type === "reorder") return op.section.label;
  if (op.type === "singleton") return op.section.label;
  const data = op.type === "delete" ? op.expected ?? {} : op.data;
  return entryTitle(op.section, data);
}

export async function publish(viewer: Viewer, site: Site, op: Operation, options: { revertsChangeId?: string } = {}): Promise<PublishResult> {
  const store = getStore();
  const label = labelFor(op);
  const summary = options.revertsChangeId ? `Annulation : ${describe(op, label)}` : describe(op, label);
  const change = await store.insertChange({
    siteId: site.id,
    actorId: viewer.user.id,
    onBehalfOf: viewer.effective.id !== viewer.user.id ? viewer.effective.id : null,
    action: options.revertsChangeId ? "revert" : op.type === "singleton" ? "update" : op.type,
    sectionKey: op.section.key,
    entryId: "id" in op ? op.id : null,
    entryLabel: label,
    before: null,
    after: null,
    beforeOrder: null,
    afterOrder: null,
    remoteRef: null,
    status: "pending",
    errorMessage: null,
    revertsChangeId: options.revertsChangeId ?? null,
    revertedByChangeId: null,
  });

  try {
    const data = op.type === "create" || op.type === "update" || op.type === "singleton" ? op.data : {};
    const assets = await loadAssets(site.id, collectMediaTokens(data));
    const ctx: ChangeContext = {
      authorName: viewer.effective.id !== viewer.user.id ? `${viewer.user.fullName || viewer.user.email} (assistance pour ${viewer.effective.fullName || viewer.effective.email})` : viewer.user.fullName || viewer.user.email,
      summary,
      changeId: change.id,
      assets,
    };
    const result = await withAdapter(site, async (adapter) => {
      switch (op.type) {
        case "create":
          return adapter.createEntry(op.section, op.data, ctx);
        case "update":
          return adapter.updateEntry(op.section, op.id, op.data, op.expected, ctx);
        case "delete":
          return adapter.deleteEntry(op.section, op.id, op.expected, ctx);
        case "reorder": {
          const r = await adapter.reorder(op.section, op.ids, ctx);
          // On garde la permutation demandée (exprimée avec les identifiants d'avant) pour pouvoir l'annuler.
          return { ...r, afterOrder: op.ids };
        }
        case "singleton":
          return adapter.updateSingleton(op.section, op.data, op.expected, ctx);
      }
    });
    const patch: Partial<Change> = {
      status: "applied",
      before: result.before,
      after: result.after,
      beforeOrder: result.beforeOrder ?? null,
      afterOrder: result.afterOrder ?? null,
      remoteRef: result.ref ?? null,
      entryId: result.id ?? change.entryId,
      entryLabel: result.after ? entryTitle(op.section, result.after) : label,
    };
    await store.updateChange(change.id, patch);
    return { ok: true, change: { ...change, ...patch }, result };
  } catch (err) {
    const message = userMessageFor(err);
    log.error("modification échouée", { site: site.slug, change: change.id, err: err instanceof AdapterError ? err.detail ?? err.message : String(err) });
    await store.updateChange(change.id, { status: "failed", errorMessage: message });
    return { ok: false, error: message, conflict: err instanceof ConflictError };
  }
}

/** L'opération inverse d'une modification réussie. */
export function inverseOperation(change: Change, section: Section): Operation | null {
  if (change.status !== "applied" || change.revertedByChangeId) return null;
  const isSingleton = section.kind === "singleton";
  if (isSingleton) return change.before ? { type: "singleton", section, data: change.before, expected: change.after } : null;
  if (change.beforeOrder && change.afterOrder) {
    const positional = !section.idField && change.beforeOrder.every((id) => /^n\d+$/.test(id));
    const ids = positional
      ? change.beforeOrder.map((id) => `n${change.afterOrder!.indexOf(id)}`)
      : change.beforeOrder;
    return { type: "reorder", section, ids };
  }
  if (change.before && change.after && change.entryId) return { type: "update", section, id: change.entryId, data: change.before, expected: change.after };
  if (!change.before && change.after && change.entryId) return { type: "delete", section, id: change.entryId, expected: change.after };
  if (change.before && !change.after) return { type: "create", section, data: change.before };
  return null;
}

export const CLOSURE_SECTION = "_fermeture";

/** Ferme ou rouvre le site, avec une ligne dans l'historique. */
export async function setSiteClosure(viewer: Viewer, site: Site, status: SiteStatus): Promise<{ ok: true } | { ok: false; error: string }> {
  const store = getStore();
  const change = await store.insertChange({
    siteId: site.id,
    actorId: viewer.user.id,
    onBehalfOf: viewer.effective.id !== viewer.user.id ? viewer.effective.id : null,
    action: "update",
    sectionKey: CLOSURE_SECTION,
    entryId: null,
    entryLabel: status.closed ? "Site fermé temporairement" : "Site rouvert",
    before: null,
    after: null,
    beforeOrder: null,
    afterOrder: null,
    remoteRef: null,
    status: "pending",
    errorMessage: null,
    revertsChangeId: null,
    revertedByChangeId: null,
  });
  try {
    const result = await withAdapter(site, async (adapter) => {
      if (!adapter.setStatus) throw new AdapterError("unsupported", "Ce type de site ne peut pas être fermé depuis Simple Commerce.");
      return adapter.setStatus(status, {
        authorName: viewer.user.fullName || viewer.user.email,
        summary: status.closed ? "Site fermé temporairement" : "Site rouvert",
        changeId: change.id,
        assets: [],
      });
    });
    await store.updateChange(change.id, { status: "applied", before: result.before, after: result.after, remoteRef: result.ref ?? null });
    return { ok: true };
  } catch (err) {
    const message = userMessageFor(err);
    log.error("fermeture du site échouée", { site: site.slug, err: err instanceof AdapterError ? err.detail ?? err.message : String(err) });
    await store.updateChange(change.id, { status: "failed", errorMessage: message });
    return { ok: false, error: message };
  }
}

export async function revert(viewer: Viewer, site: Site, change: Change, section: Section): Promise<PublishResult> {
  const op = inverseOperation(change, section);
  if (!op) return { ok: false, error: "Cette modification ne peut pas être annulée." };
  const result = await publish(viewer, site, op, { revertsChangeId: change.id });
  if (result.ok) await getStore().updateChange(change.id, { revertedByChangeId: result.change.id });
  return result;
}
