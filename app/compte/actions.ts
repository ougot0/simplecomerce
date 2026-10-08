"use server";

import { audit, requireViewer } from "@/lib/access";
import { passwordProblem, updatePassword } from "@/lib/auth";
import { getStore } from "@/lib/store";

export interface AccountState {
  error?: string;
  info?: string;
}

export async function saveNameAction(_prev: AccountState, form: FormData): Promise<AccountState> {
  const viewer = await requireViewer();
  if (viewer.impersonationId) return { error: "Impossible en mode assistance." };
  const name = String(form.get("fullName") ?? "").trim();
  if (name.length < 2 || name.length > 80) return { error: "Nom invalide." };
  await getStore().updateProfile(viewer.user.id, { fullName: name });
  return { info: "Nom enregistré." };
}

export async function changePasswordAction(_prev: AccountState, form: FormData): Promise<AccountState> {
  const viewer = await requireViewer();
  if (viewer.impersonationId) return { error: "Impossible en mode assistance." };
  const password = String(form.get("password") ?? "");
  const problem = passwordProblem(password);
  if (problem) return { error: problem };
  if (password !== form.get("confirm")) return { error: "Les deux mots de passe ne sont pas identiques." };
  const res = await updatePassword(password);
  if (!res.ok) return { error: res.error };
  await audit(viewer, "password_changed");
  return { info: "Mot de passe modifié." };
}
