import "server-only";
import { cache } from "react";
import { cookies } from "next/headers";
import { notFound, redirect } from "next/navigation";
import { getAuthUser } from "./auth";
import { signValue, verifyValue } from "./signing";
import { getStore, type Profile, type Role, type Site } from "./store";

/**
 * Qui fait la demande, et à quoi a-t-il droit.
 * Chaque page et chaque action commence par l'une de ces fonctions.
 */

const IMPERSONATION_COOKIE = "sc-assist";
export const IMPERSONATION_MINUTES = 60;

export interface Viewer {
  /** La personne réellement connectée. */
  user: Profile;
  /** Pour qui elle agit : elle-même, ou le client en mode assistance. */
  effective: Profile;
  impersonationId: string | null;
}

export type SiteRole = Role | "admin";

export const getViewer = cache(async (): Promise<Viewer | null> => {
  const auth = await getAuthUser();
  if (!auth) return null;
  const store = getStore();
  const user = await store.getProfile(auth.id);
  if (!user) return null;

  const token = (await cookies()).get(IMPERSONATION_COOKIE)?.value;
  const payload = verifyValue<{ id: string; admin: string }>(token);
  if (payload && user.isSuperAdmin && payload.admin === user.id) {
    const imp = await store.getImpersonation(payload.id);
    if (imp && !imp.endedAt && imp.expiresAt > new Date().toISOString() && imp.adminId === user.id) {
      const target = await store.getProfile(imp.targetUserId);
      if (target) return { user, effective: target, impersonationId: imp.id };
    }
  }
  return { user, effective: user, impersonationId: null };
});

export async function requireViewer(): Promise<Viewer> {
  const viewer = await getViewer();
  if (!viewer) redirect("/connexion");
  return viewer;
}

export async function requireSuperAdmin(): Promise<Viewer> {
  const viewer = await requireViewer();
  // En mode assistance, l'administrateur voit exactement ce que voit le client : pas d'écrans d'admin.
  if (!viewer.user.isSuperAdmin || viewer.impersonationId) notFound();
  return viewer;
}

/**
 * Accès à un site. Renvoie 404 (et non 403) si la personne n'y a pas droit,
 * pour ne même pas révéler que le site existe.
 */
export async function requireSiteAccess(slug: string, minimum: Role = "editor"): Promise<{ viewer: Viewer; site: Site; role: SiteRole }> {
  const viewer = await requireViewer();
  if (typeof slug !== "string" || !/^[a-z0-9-]{1,80}$/.test(slug)) notFound();
  const store = getStore();
  const site = await store.getSiteBySlug(slug);
  if (!site) notFound();

  let role: SiteRole | null = null;
  if (viewer.user.isSuperAdmin && !viewer.impersonationId) role = "admin";
  else {
    const membership = await store.getMembership(site.id, viewer.effective.id);
    role = membership?.role ?? null;
  }
  if (!role) notFound();
  if (minimum === "owner" && role === "editor") notFound();
  if (site.status === "suspended" && role !== "admin") notFound();
  return { viewer, site, role };
}

export async function startImpersonation(adminId: string, targetUserId: string) {
  const imp = await getStore().createImpersonation(adminId, targetUserId, IMPERSONATION_MINUTES);
  (await cookies()).set(IMPERSONATION_COOKIE, signValue({ id: imp.id, admin: adminId, exp: Date.parse(imp.expiresAt) }), {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge: IMPERSONATION_MINUTES * 60,
  });
  return imp;
}

export async function stopImpersonation(): Promise<string | null> {
  const store = await cookies();
  const payload = verifyValue<{ id: string }>(store.get(IMPERSONATION_COOKIE)?.value);
  store.delete(IMPERSONATION_COOKIE);
  if (payload) await getStore().endImpersonation(payload.id);
  return payload?.id ?? null;
}

export async function audit(viewer: Viewer | null, kind: string, details: Record<string, unknown> = {}, siteId: string | null = null) {
  await getStore().logEvent({
    actorId: viewer?.user.id ?? null,
    onBehalfOf: viewer && viewer.effective.id !== viewer.user.id ? viewer.effective.id : null,
    siteId,
    kind,
    details,
  });
}
