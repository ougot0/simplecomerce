"use server";

import { redirect } from "next/navigation";
import { audit, getViewer, requireSuperAdmin, startImpersonation, stopImpersonation } from "@/lib/access";
import { getStore } from "@/lib/store";

export async function startAssistAction(form: FormData) {
  const viewer = await requireSuperAdmin();
  const target = await getStore().getProfile(String(form.get("userId") ?? ""));
  if (!target || target.id === viewer.user.id) redirect("/admin");
  await startImpersonation(viewer.user.id, target.id);
  await audit(viewer, "impersonation_started", { target: target.email });
  redirect("/sites?liste=1");
}

export async function stopAssistAction() {
  const viewer = await getViewer();
  const id = await stopImpersonation();
  if (viewer && id) await audit({ ...viewer, effective: viewer.user, impersonationId: null }, "impersonation_ended", { target: viewer.effective.email });
  redirect("/admin");
}

export async function setSiteStatusAction(form: FormData) {
  const viewer = await requireSuperAdmin();
  const store = getStore();
  const site = await store.getSite(String(form.get("siteId") ?? ""));
  if (!site) redirect("/admin");
  const status = form.get("status") === "suspended" ? "suspended" : "active";
  await store.updateSite(site.id, { status });
  await audit(viewer, status === "suspended" ? "site_suspended" : "site_reactivated", { name: site.name }, site.id);
  redirect("/admin");
}
