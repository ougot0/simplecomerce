"use server";

import { createHash, randomBytes } from "node:crypto";
import { redirect } from "next/navigation";
import { z } from "zod";
import { audit } from "@/lib/access";
import { parseContentSchema, type ContentSchema } from "@/lib/content/schema";
import { siteUrl } from "@/lib/env";
import { sendMail } from "@/lib/mail";
import { loadSite } from "@/lib/site-context";
import { discoverSchema } from "@/lib/sites";
import { getStore } from "@/lib/store";
import { userMessageFor } from "@/lib/adapters/types";

export interface SettingsState {
  error?: string;
  info?: string;
  link?: string;
}

const INVITE_DAYS = 14;

export async function inviteAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site } = await loadSite(String(form.get("site")), "owner");
  const email = z.string().trim().toLowerCase().email().max(200).safeParse(form.get("email"));
  if (!email.success) return { error: "Adresse e-mail invalide." };
  const role = form.get("role") === "owner" ? "owner" : "editor";
  const store = getStore();
  const existing = await store.getProfileByEmail(email.data);
  if (existing && (await store.getMembership(site.id, existing.id))) return { error: "Cette personne a déjà accès au site." };

  const token = randomBytes(32).toString("base64url");
  await store.createInvitation({
    siteId: site.id,
    email: email.data,
    role,
    tokenHash: createHash("sha256").update(token).digest("hex"),
    invitedBy: viewer.user.id,
    expiresAt: new Date(Date.now() + INVITE_DAYS * 86_400_000).toISOString(),
  });
  const link = `${siteUrl()}/invitation/${token}`;
  const inviter = viewer.effective.fullName || viewer.effective.email;
  const sent = await sendMail(
    email.data,
    `${inviter} vous invite à modifier le site ${site.name}`,
    `Bonjour,\n\n${inviter} vous invite à mettre à jour le site « ${site.name} » avec Simple Commerce.\n\nPour accepter, ouvrez ce lien (valable ${INVITE_DAYS} jours) :\n${link}\n\nSi vous ne vous attendiez pas à cette invitation, ignorez simplement ce message.`,
  );
  await audit(viewer, "member_invited", { email: email.data, role, sent }, site.id);
  return sent
    ? { info: `Invitation envoyée à ${email.data}.`, link }
    : { info: `Invitation créée. Envoyez ce lien à ${email.data} (par e-mail ou SMS) : il est valable ${INVITE_DAYS} jours et ne sera plus affiché.`, link };
}

export async function removeMemberAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site } = await loadSite(String(form.get("site")), "owner");
  const userId = String(form.get("userId") ?? "");
  const store = getStore();
  const members = await store.listMembers(site.id);
  const target = members.find((m) => m.userId === userId);
  if (!target) return { error: "Personne introuvable." };
  if (target.role === "owner" && members.filter((m) => m.role === "owner").length <= 1) {
    return { error: "Le site doit garder au moins un propriétaire." };
  }
  await store.removeMember(site.id, userId);
  await audit(viewer, "member_removed", { email: target.profile.email }, site.id);
  return { info: `${target.profile.fullName || target.profile.email} n'a plus accès au site.` };
}

export async function revokeInvitationAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site } = await loadSite(String(form.get("site")), "owner");
  const store = getStore();
  const inv = (await store.listInvitations(site.id)).find((i) => i.id === form.get("invitationId"));
  if (!inv) return { error: "Invitation introuvable." };
  await store.deleteInvitation(inv.id);
  await audit(viewer, "invitation_revoked", { email: inv.email }, site.id);
  return { info: "Invitation annulée." };
}

export async function saveSectionsAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site, schema } = await loadSite(String(form.get("site")), "owner");
  const next: ContentSchema = {
    ...schema,
    sections: schema.sections.map((s) => {
      const label = String(form.get(`label_${s.key}`) ?? s.label).trim().slice(0, 80) || s.label;
      return { ...s, label, hidden: form.get(`visible_${s.key}`) !== "on" };
    }),
  };
  await getStore().saveSchema(site.id, parseContentSchema(next), viewer.user.id);
  await audit(viewer, "schema_updated", { via: "rubriques" }, site.id);
  return { info: "Rubriques enregistrées." };
}

/** Relit le site ; garde les noms et choix de visibilité déjà faits pour les rubriques retrouvées. */
export async function rediscoverAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site, schema } = await loadSite(String(form.get("site")), "owner");
  try {
    const found = await discoverSchema(site);
    const previous = new Map(schema.sections.map((s) => [s.key, s]));
    const merged: ContentSchema = {
      ...found.schema,
      sections: found.schema.sections.map((s) => {
        const old = previous.get(s.key);
        if (!old) return s;
        const oldFields = new Map(old.fields.map((f) => [f.key, f]));
        return {
          ...s,
          label: old.label,
          hidden: old.hidden,
          itemLabel: old.itemLabel ?? s.itemLabel,
          fields: s.fields.map((f) => (oldFields.has(f.key) ? { ...f, label: oldFields.get(f.key)!.label, hidden: oldFields.get(f.key)!.hidden, help: oldFields.get(f.key)!.help } : f)),
        };
      }),
    };
    await getStore().saveSchema(site.id, parseContentSchema(merged), viewer.user.id);
    await audit(viewer, "schema_detected", { notes: found.notes }, site.id);
    return { info: `Contenu relu : ${merged.sections.length} rubrique(s). ${found.notes.join(" ")}` };
  } catch (err) {
    return { error: userMessageFor(err) };
  }
}

/** Édition directe du schéma (administrateur uniquement). */
export async function saveSchemaJsonAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site, role } = await loadSite(String(form.get("site")), "owner");
  if (role !== "admin") return { error: "Réservé à l'administrateur." };
  let schema: ContentSchema;
  try {
    schema = parseContentSchema(JSON.parse(String(form.get("schema") ?? "")));
  } catch (err) {
    const issue = err instanceof z.ZodError ? err.issues[0] : null;
    return { error: issue ? `Schéma invalide : ${issue.path.join(".")} — ${issue.message}` : `Schéma invalide : ${String((err as Error).message).slice(0, 200)}` };
  }
  await getStore().saveSchema(site.id, schema, viewer.user.id);
  await audit(viewer, "schema_updated", { via: "json" }, site.id);
  return { info: "Schéma enregistré (nouvelle version)." };
}

export async function deleteSiteAction(_prev: SettingsState, form: FormData): Promise<SettingsState> {
  const { viewer, site } = await loadSite(String(form.get("site")), "owner");
  if (String(form.get("confirm") ?? "").trim() !== site.name) return { error: `Pour confirmer, recopiez exactement : ${site.name}` };
  await audit(viewer, "site_removed", { name: site.name }, null);
  await getStore().deleteSite(site.id);
  redirect("/sites?liste=1");
}
