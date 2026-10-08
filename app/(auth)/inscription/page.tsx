import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { getViewer } from "@/lib/access";
import { safeNext } from "@/lib/ui/safe-next";
import { AuthFrame } from "../auth-frame";
import { SignupForm } from "./signup-form";

export const metadata: Metadata = { title: "Créer un compte" };

export default async function SignupPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const params = await searchParams;
  const next = safeNext(params.next);
  if (await getViewer()) redirect(next);
  return (
    <AuthFrame>
      <h2>Créer un compte</h2>
      <p className="muted">Ensuite, vous relierez votre site en quelques minutes. Il restera relié : à chaque connexion, vous le retrouverez.</p>
      <SignupForm next={next} email={params.email} />
    </AuthFrame>
  );
}
