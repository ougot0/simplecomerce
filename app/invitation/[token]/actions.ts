"use server";

import { createHash } from "node:crypto";
import { redirect } from "next/navigation";
import { audit, requireViewer } from "@/lib/access";
import { getStore } from "@/lib/store";

export async function acceptInvitationAction(_prev: { error?: string }, form: FormData): Promise<{ error?: string }> {
  const viewer = await requireViewer();
  const token = String(form.get("token") ?? "");
  const store = getStore();
  const inv = await store.getInvitationByTokenHash(createHash("sha256").update(token).digest("hex"));
  if (!inv || inv.acceptedAt || inv.expiresAt < new Date().toISOString()) return { error: "Invitation expirée." };
  // L'invitation est liée à une adresse : impossible de l'utiliser depuis un autre compte.
  if (viewer.effective.email.toLowerCase() !== inv.email.toLowerCase()) return { error: "Cette invitation est destinée à une autre adresse." };
  await store.acceptInvitation(inv.id, viewer.effective.id);
  const site = await store.getSite(inv.siteId);
  await audit(viewer, "invitation_accepted", { email: inv.email }, inv.siteId);
  redirect(site ? `/s/${site.slug}` : "/sites");
}
