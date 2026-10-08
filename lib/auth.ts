import "server-only";
import { cookies } from "next/headers";
import { createHash, randomBytes, randomUUID, scrypt as scryptCb, timingSafeEqual } from "node:crypto";
import { promisify } from "node:util";
import { appMode, siteUrl } from "./env";
import { signValue, verifyValue } from "./signing";
import { REMEMBER_COOKIE, supabaseServer } from "./supabase/server";
import { withDb } from "./store/file-store";

/**
 * Authentification : Supabase Auth en production, comptes locaux en démonstration.
 * Les deux exposent les mêmes fonctions, appelées par les actions du dossier app/(auth).
 */

const scrypt = promisify(scryptCb) as (pw: string, salt: Buffer, len: number) => Promise<Buffer>;
const DEMO_SESSION = "sc-demo-session";
const THIRTY_DAYS = 60 * 60 * 24 * 30;

export interface AuthUser {
  id: string;
  email: string;
}

export type AuthResult = { ok: true; needsConfirmation?: boolean; demoLink?: string } | { ok: false; error: string };

async function setRemember(remember: boolean) {
  (await cookies()).set(REMEMBER_COOKIE, remember ? "1" : "0", { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: "/", maxAge: 60 * 60 * 24 * 365 });
}

function translateSupabaseError(message: string): string {
  if (/invalid login credentials/i.test(message)) return "E-mail ou mot de passe incorrect.";
  if (/email not confirmed/i.test(message)) return "Votre adresse e-mail n'est pas encore confirmée : cliquez sur le lien reçu par e-mail.";
  if (/already registered|already exists/i.test(message)) return "Un compte existe déjà avec cette adresse. Connectez-vous ou réinitialisez votre mot de passe.";
  if (/password should be|weak password/i.test(message)) return "Mot de passe trop faible : 10 caractères minimum, avec lettres et chiffres.";
  if (/rate limit|too many/i.test(message)) return "Trop de tentatives. Patientez quelques minutes avant de réessayer.";
  return "La demande n'a pas abouti. Réessayez dans un instant.";
}

// ---------------------------------------------------------------- démo

async function hashPassword(password: string): Promise<string> {
  const salt = randomBytes(16);
  const hash = await scrypt(password, salt, 32);
  return `${salt.toString("base64")}:${hash.toString("base64")}`;
}

async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const [salt, hash] = stored.split(":");
  const candidate = await scrypt(password, Buffer.from(salt, "base64"), 32);
  const expected = Buffer.from(hash, "base64");
  return candidate.length === expected.length && timingSafeEqual(candidate, expected);
}

async function setDemoSession(userId: string, remember: boolean) {
  const exp = Date.now() + (remember ? THIRTY_DAYS * 1000 : 12 * 3600 * 1000);
  (await cookies()).set(DEMO_SESSION, signValue({ uid: userId, exp }), {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    ...(remember ? { maxAge: THIRTY_DAYS } : {}),
  });
}

export async function demoCreateUser(email: string, password: string, fullName: string, isSuperAdmin = false): Promise<string> {
  const passwordHash = await hashPassword(password);
  return withDb((db) => {
    const existing = db.authUsers.find((u) => u.email.toLowerCase() === email.toLowerCase());
    if (existing) return existing.id;
    const id = randomUUID();
    db.authUsers.push({ id, email, passwordHash });
    db.profiles.push({ id, email, fullName, isSuperAdmin, createdAt: new Date().toISOString() });
    return id;
  }, true);
}

// ---------------------------------------------------------------- API commune

export async function getAuthUser(): Promise<AuthUser | null> {
  if (appMode() === "demo") {
    const payload = verifyValue<{ uid: string }>((await cookies()).get(DEMO_SESSION)?.value);
    if (!payload) return null;
    return withDb((db) => {
      const u = db.authUsers.find((x) => x.id === payload.uid);
      return u ? { id: u.id, email: u.email } : null;
    });
  }
  const supabase = await supabaseServer();
  const { data } = await supabase.auth.getUser();
  return data.user ? { id: data.user.id, email: data.user.email ?? "" } : null;
}

export async function signUp(input: { email: string; password: string; fullName: string; remember: boolean; next?: string }): Promise<AuthResult> {
  await setRemember(input.remember);
  if (appMode() === "demo") {
    const exists = await withDb((db) => db.authUsers.some((u) => u.email.toLowerCase() === input.email.toLowerCase()));
    if (exists) return { ok: false, error: "Un compte existe déjà avec cette adresse. Connectez-vous." };
    const id = await demoCreateUser(input.email, input.password, input.fullName);
    await setDemoSession(id, input.remember);
    return { ok: true };
  }
  const supabase = await supabaseServer();
  const { data, error } = await supabase.auth.signUp({
    email: input.email,
    password: input.password,
    options: {
      data: { full_name: input.fullName },
      emailRedirectTo: `${siteUrl()}/auth/callback?next=${encodeURIComponent(input.next ?? "/sites")}`,
    },
  });
  if (error) return { ok: false, error: translateSupabaseError(error.message) };
  return { ok: true, needsConfirmation: !data.session };
}

export async function signIn(input: { email: string; password: string; remember: boolean }): Promise<AuthResult> {
  await setRemember(input.remember);
  if (appMode() === "demo") {
    const user = await withDb((db) => db.authUsers.find((u) => u.email.toLowerCase() === input.email.toLowerCase()) ?? null);
    if (!user || !(await verifyPassword(input.password, user.passwordHash))) return { ok: false, error: "E-mail ou mot de passe incorrect." };
    await setDemoSession(user.id, input.remember);
    return { ok: true };
  }
  const supabase = await supabaseServer();
  const { error } = await supabase.auth.signInWithPassword({ email: input.email, password: input.password });
  if (error) return { ok: false, error: translateSupabaseError(error.message) };
  return { ok: true };
}

export async function sendMagicLink(input: { email: string; remember: boolean; next?: string }): Promise<AuthResult> {
  await setRemember(input.remember);
  const next = input.next ?? "/sites";
  if (appMode() === "demo") {
    const user = await withDb((db) => db.authUsers.find((u) => u.email.toLowerCase() === input.email.toLowerCase()) ?? null);
    // Même réponse que l'adresse existe ou non, pour ne pas révéler qui a un compte.
    if (!user) return { ok: true };
    const token = randomBytes(24).toString("base64url");
    await withDb((db) => {
      db.magicLinks.push({
        tokenHash: createHash("sha256").update(token).digest("hex"),
        userId: user.id,
        expiresAt: new Date(Date.now() + 15 * 60_000).toISOString(),
        remember: input.remember,
      });
    }, true);
    return { ok: true, demoLink: `/auth/demo-link?token=${token}&next=${encodeURIComponent(next)}` };
  }
  const supabase = await supabaseServer();
  const { error } = await supabase.auth.signInWithOtp({
    email: input.email,
    options: { shouldCreateUser: false, emailRedirectTo: `${siteUrl()}/auth/callback?next=${encodeURIComponent(next)}` },
  });
  if (error && !/signups not allowed|user not found/i.test(error.message)) return { ok: false, error: translateSupabaseError(error.message) };
  return { ok: true };
}

export async function consumeDemoMagicLink(token: string): Promise<boolean> {
  const tokenHash = createHash("sha256").update(token).digest("hex");
  const link = await withDb((db) => {
    const found = db.magicLinks.find((l) => l.tokenHash === tokenHash && l.expiresAt > new Date().toISOString());
    db.magicLinks = db.magicLinks.filter((l) => l.tokenHash !== tokenHash);
    return found ?? null;
  }, true);
  if (!link) return false;
  await setDemoSession(link.userId, link.remember);
  return true;
}

export async function requestPasswordReset(email: string): Promise<AuthResult> {
  if (appMode() === "demo") return sendMagicLink({ email, remember: false, next: "/compte" });
  const supabase = await supabaseServer();
  await supabase.auth.resetPasswordForEmail(email, { redirectTo: `${siteUrl()}/auth/callback?next=/compte` });
  return { ok: true };
}

export async function updatePassword(password: string): Promise<AuthResult> {
  const user = await getAuthUser();
  if (!user) return { ok: false, error: "Session expirée : reconnectez-vous." };
  if (appMode() === "demo") {
    const passwordHash = await hashPassword(password);
    await withDb((db) => {
      const u = db.authUsers.find((x) => x.id === user.id);
      if (u) u.passwordHash = passwordHash;
    }, true);
    return { ok: true };
  }
  const supabase = await supabaseServer();
  const { error } = await supabase.auth.updateUser({ password });
  if (error) return { ok: false, error: translateSupabaseError(error.message) };
  return { ok: true };
}

export async function signOut() {
  if (appMode() === "demo") {
    (await cookies()).delete(DEMO_SESSION);
    return;
  }
  const supabase = await supabaseServer();
  await supabase.auth.signOut();
}

export function passwordProblem(password: string): string | null {
  if (password.length < 10) return "10 caractères minimum.";
  if (!/[a-zA-Z]/.test(password) || !/\d/.test(password)) return "Utilisez des lettres et au moins un chiffre.";
  return null;
}
