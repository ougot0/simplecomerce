"use server";

import { redirect } from "next/navigation";
import { z } from "zod";
import { passwordProblem, requestPasswordReset, sendMagicLink, signIn, signUp } from "@/lib/auth";
import { audit, getViewer } from "@/lib/access";
import { safeNext } from "@/lib/ui/safe-next";

export interface AuthFormState {
  error?: string;
  fieldErrors?: Record<string, string>;
  info?: string;
  demoLink?: string;
  values?: Record<string, string>;
}

const email = z.string().trim().toLowerCase().email("Adresse e-mail invalide.").max(200);

export async function loginAction(_prev: AuthFormState, form: FormData): Promise<AuthFormState> {
  const values = { email: String(form.get("email") ?? "") };
  const parsed = email.safeParse(form.get("email"));
  if (!parsed.success) return { fieldErrors: { email: "Adresse e-mail invalide." }, values };
  const remember = form.get("remember") === "on";
  const next = safeNext(form.get("next"));

  if (form.get("intent") === "magic") {
    const res = await sendMagicLink({ email: parsed.data, remember, next });
    if (!res.ok) return { error: res.error, values };
    return {
      info: "Si un compte existe avec cette adresse, un lien de connexion vient de lui être envoyé. Il est valable 15 minutes.",
      demoLink: res.demoLink,
      values,
    };
  }

  const password = String(form.get("password") ?? "");
  if (!password) return { fieldErrors: { password: "Indiquez votre mot de passe." }, values };
  const res = await signIn({ email: parsed.data, password, remember });
  if (!res.ok) return { error: res.error, values };
  await audit(await getViewer(), "login", { method: "password" });
  redirect(next);
}

export async function signupAction(_prev: AuthFormState, form: FormData): Promise<AuthFormState> {
  const values = { email: String(form.get("email") ?? ""), fullName: String(form.get("fullName") ?? "") };
  const fieldErrors: Record<string, string> = {};
  const parsedEmail = email.safeParse(form.get("email"));
  if (!parsedEmail.success) fieldErrors.email = "Adresse e-mail invalide.";
  const fullName = values.fullName.trim();
  if (fullName.length < 2 || fullName.length > 80) fieldErrors.fullName = "Indiquez votre prénom et votre nom.";
  const password = String(form.get("password") ?? "");
  const problem = passwordProblem(password);
  if (problem) fieldErrors.password = problem;
  if (Object.keys(fieldErrors).length) return { fieldErrors, values };

  const next = safeNext(form.get("next"));
  const res = await signUp({ email: parsedEmail.data!, password, fullName, remember: form.get("remember") === "on", next });
  if (!res.ok) return { error: res.error, values };
  if (res.needsConfirmation) {
    return { info: "Compte créé. Ouvrez l'e-mail que nous venons de vous envoyer et cliquez sur le lien pour confirmer votre adresse.", values };
  }
  await audit(await getViewer(), "signup");
  redirect(next);
}

export async function forgotAction(_prev: AuthFormState, form: FormData): Promise<AuthFormState> {
  const parsed = email.safeParse(form.get("email"));
  if (!parsed.success) return { fieldErrors: { email: "Adresse e-mail invalide." } };
  const res = await requestPasswordReset(parsed.data);
  return {
    info: "Si un compte existe avec cette adresse, vous allez recevoir un e-mail pour choisir un nouveau mot de passe.",
    demoLink: res.ok ? res.demoLink : undefined,
  };
}
