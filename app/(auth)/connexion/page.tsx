import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { getViewer } from "@/lib/access";
import { appMode } from "@/lib/env";
import { safeNext } from "@/lib/ui/safe-next";
import { AuthFrame } from "../auth-frame";
import { LoginForm } from "./login-form";

export const metadata: Metadata = { title: "Connexion" };

export default async function LoginPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const params = await searchParams;
  const next = safeNext(params.next);
  if (await getViewer()) redirect(next);
  return (
    <AuthFrame>
      <h2>Connexion</h2>
      <p className="muted">Retrouvez votre site et vos modifications.</p>
      {params.erreur === "lien" && <div className="notice notice-error">Ce lien de connexion a expiré ou a déjà servi. Demandez-en un nouveau.</div>}
      <LoginForm next={next} demo={appMode() === "demo"} />
    </AuthFrame>
  );
}
