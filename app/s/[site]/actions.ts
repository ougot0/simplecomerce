"use server";

import { redirect } from "next/navigation";
import { audit } from "@/lib/access";
import { publish, revert, type Operation } from "@/lib/changes";
import { entryTitle, findSection, type Section } from "@/lib/content/schema";
import { sanitizeRichText } from "@/lib/content/sanitize";
import { deepEqual, normalizeData, pickFields, type Data } from "@/lib/content/values";
import { AdapterError, userMessageFor } from "@/lib/adapters/types";
import { stageUpload, UploadError } from "@/lib/media";
import { loadSection, loadSite } from "@/lib/site-context";
import { withAdapter } from "@/lib/sites";
import { getStore } from "@/lib/store";
import { log } from "@/lib/log";

export interface EditorState {
  error?: string;
  conflict?: boolean;
  fieldErrors?: Record<string, string>;
}

function parseJsonObject(raw: FormDataEntryValue | null, max = 1_000_000): Data | null {
  if (typeof raw !== "string" || !raw || raw.length > max) return null;
  try {
    const value = JSON.parse(raw);
    return value && typeof value === "object" && !Array.isArray(value) ? (value as Data) : null;
  } catch {
    return null;
  }
}

function cleanId(raw: FormDataEntryValue | null): string | null {
  const id = typeof raw === "string" ? raw : "";
  return id && id.length <= 200 && !/[\0/\\]/.test(id) ? id : null;
}

const CONFLICT = "Ce contenu a été modifié entre-temps (par quelqu'un d'autre ou directement sur le site). Rechargez la page pour partir de la version actuelle.";

async function currentData(siteSlug: string, section: Section, entryId: string | null): Promise<{ current: Data; exists: boolean }> {
  const { site } = await loadSite(siteSlug);
  return withAdapter(site, async (adapter) => {
    if (section.kind === "singleton") return { current: await adapter.getSingleton(section), exists: true };
    if (entryId) {
      const entry = await adapter.getEntry(section, entryId);
      if (!entry) throw new AdapterError("conflict", "Cet élément n'existe plus sur le site.");
      return { current: entry.data, exists: true };
    }
    return { current: adapter.template?.(section) ?? {}, exists: false };
  });
}

function listUrl(siteSlug: string, section: Section, flash: string) {
  return `/s/${siteSlug}/r/${section.key}?ok=${flash}`;
}

export async function saveEntryAction(_prev: EditorState, form: FormData): Promise<EditorState> {
  const siteSlug = String(form.get("site"));
  const { viewer, site, section } = await loadSection(siteSlug, String(form.get("section")));
  const entryId = cleanId(form.get("entryId"));
  const draftId = cleanId(form.get("draftId"));
  const mode = form.get("mode") === "draft" ? "draft" : "publish";
  const input = parseJsonObject(form.get("payload"));
  if (!input) return { error: "Le formulaire est incomplet. Rechargez la page." };
  const expected = parseJsonObject(form.get("expected"));

  let current: Data;
  let exists: boolean;
  try {
    ({ current, exists } = await currentData(siteSlug, section, entryId));
  } catch (err) {
    return { error: userMessageFor(err), conflict: err instanceof AdapterError && err.code === "conflict" };
  }
  if (exists && expected && !deepEqual(pickFields(section, current), pickFields(section, expected))) {
    return { error: CONFLICT, conflict: true };
  }

  const { data, errors } = normalizeData(section.fields, input, current, { sanitizeHtml: sanitizeRichText });
  if (Object.keys(errors).length) return { fieldErrors: errors, error: "Certains champs sont à corriger." };

  const store = getStore();
  if (mode === "draft") {
    const existingDraft = draftId ? await store.getDraft(draftId) : await store.findDraft(site.id, section.key, section.kind === "singleton" ? "_" : entryId);
    if (existingDraft && existingDraft.siteId !== site.id) return { error: "Brouillon introuvable." };
    await store.saveDraft({
      id: existingDraft?.id,
      siteId: site.id,
      sectionKey: section.key,
      entryId: section.kind === "singleton" ? "_" : entryId,
      label: entryTitle(section, data),
      data,
      baseData: exists ? pickFields(section, current) : null,
      createdBy: existingDraft?.createdBy ?? viewer.user.id,
      updatedBy: viewer.user.id,
    });
    redirect(section.kind === "singleton" ? `/s/${siteSlug}/r/${section.key}?ok=brouillon` : listUrl(siteSlug, section, "brouillon"));
  }

  const op: Operation =
    section.kind === "singleton"
      ? { type: "singleton", section, data, expected: pickFields(section, current) }
      : entryId
        ? { type: "update", section, id: entryId, data, expected: pickFields(section, current) }
        : { type: "create", section, data };
  const result = await publish(viewer, site, op);
  if (!result.ok) return { error: result.error, conflict: result.conflict };
  if (draftId) {
    const draft = await store.getDraft(draftId);
    if (draft?.siteId === site.id) await store.deleteDraft(draftId);
  }
  redirect(section.kind === "singleton" ? `/s/${siteSlug}/r/${section.key}?ok=publie` : listUrl(siteSlug, section, "publie"));
}

export async function deleteEntryAction(_prev: EditorState, form: FormData): Promise<EditorState> {
  const siteSlug = String(form.get("site"));
  const { viewer, site, section } = await loadSection(siteSlug, String(form.get("section")));
  const entryId = cleanId(form.get("entryId"));
  if (!entryId || section.kind !== "collection" || section.allowDelete === false) return { error: "Suppression impossible." };
  const expected = parseJsonObject(form.get("expected"));
  const result = await publish(viewer, site, { type: "delete", section, id: entryId, expected });
  if (!result.ok) return { error: result.error, conflict: result.conflict };
  redirect(listUrl(siteSlug, section, "supprime"));
}

export async function reorderAction(_prev: EditorState, form: FormData): Promise<EditorState> {
  const siteSlug = String(form.get("site"));
  const { viewer, site, section } = await loadSection(siteSlug, String(form.get("section")));
  let ids: unknown;
  try {
    ids = JSON.parse(String(form.get("ids") ?? "[]"));
  } catch {
    ids = null;
  }
  if (!Array.isArray(ids) || ids.length > 2000 || !ids.every((i) => typeof i === "string" && i.length <= 200)) return { error: "Ordre invalide." };
  const result = await publish(viewer, site, { type: "reorder", section, ids: ids as string[] });
  if (!result.ok) return { error: result.error, conflict: result.conflict };
  redirect(listUrl(siteSlug, section, "ordre"));
}

export async function revertAction(_prev: EditorState, form: FormData): Promise<EditorState> {
  const siteSlug = String(form.get("site"));
  const { viewer, site, schema } = await loadSite(siteSlug);
  const change = await getStore().getChange(String(form.get("changeId") ?? ""));
  if (!change || change.siteId !== site.id) return { error: "Modification introuvable." };
  const section = findSection(schema, change.sectionKey);
  if (!section) return { error: "Cette rubrique n'existe plus." };
  const result = await revert(viewer, site, change, section);
  if (!result.ok) return { error: result.conflict ? "Impossible d'annuler : ce contenu a été modifié depuis. Modifiez-le directement." : result.error };
  redirect(`/s/${siteSlug}/historique?ok=annule`);
}

export async function publishDraftAction(_prev: EditorState, form: FormData): Promise<EditorState> {
  const siteSlug = String(form.get("site"));
  const { viewer, site, schema } = await loadSite(siteSlug);
  const store = getStore();
  const draft = await store.getDraft(String(form.get("draftId") ?? ""));
  if (!draft || draft.siteId !== site.id) return { error: "Brouillon introuvable." };
  const section = findSection(schema, draft.sectionKey);
  if (!section) return { error: "Cette rubrique n'existe plus." };
  const op: Operation =
    section.kind === "singleton"
      ? { type: "singleton", section, data: draft.data, expected: draft.baseData }
      : draft.entryId
        ? { type: "update", section, id: draft.entryId, data: draft.data, expected: draft.baseData }
        : { type: "create", section, data: draft.data };
  const result = await publish(viewer, site, op);
  if (!result.ok) {
    return { error: result.conflict ? "Le site a été modifié depuis ce brouillon. Ouvrez-le pour vérifier, puis mettez-le en ligne depuis le formulaire." : result.error };
  }
  await store.deleteDraft(draft.id);
  redirect(`/s/${siteSlug}/brouillons?ok=publie`);
}

export async function discardDraftAction(_prev: EditorState, form: FormData): Promise<EditorState> {
  const siteSlug = String(form.get("site"));
  const { viewer, site } = await loadSite(siteSlug);
  const store = getStore();
  const draft = await store.getDraft(String(form.get("draftId") ?? ""));
  if (!draft || draft.siteId !== site.id) return { error: "Brouillon introuvable." };
  await store.deleteDraft(draft.id);
  await audit(viewer, "draft_discarded", { label: draft.label }, site.id);
  redirect(`/s/${siteSlug}/brouillons?ok=jete`);
}

export async function uploadImageAction(form: FormData): Promise<{ token?: string; url?: string; error?: string }> {
  const siteSlug = String(form.get("site"));
  const { viewer, site } = await loadSite(siteSlug);
  const file = form.get("file");
  if (!(file instanceof File)) return { error: "Aucune photo reçue." };
  try {
    const media = await stageUpload(site.id, viewer.user.id, Buffer.from(await file.arrayBuffer()), {
      originalName: file.name || "photo",
      maxWidth: Number(form.get("maxWidth")) || undefined,
      alt: String(form.get("alt") ?? ""),
    });
    return { token: `sc-media:${media.id}`, url: `/api/media/${media.id}` };
  } catch (err) {
    if (err instanceof UploadError) return { error: err.message };
    log.error("envoi de photo échoué", { site: site.slug, err });
    return { error: "La photo n'a pas pu être enregistrée. Réessayez." };
  }
}
