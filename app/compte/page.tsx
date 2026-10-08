import type { Metadata } from "next";
import { requireViewer } from "@/lib/access";
import { PlainShell } from "@/components/plain-shell";
import { NameForm, PasswordForm } from "./forms";

export const metadata: Metadata = { title: "Mon compte" };

export default async function AccountPage() {
  const viewer = await requireViewer();
  return (
    <PlainShell viewer={viewer}>
      <div className="page-head">
        <div>
          <h1>Mon compte</h1>
          <p className="page-intro">{viewer.user.email}</p>
        </div>
      </div>
      <section>
        <h2 style={{ marginBottom: 14 }}>Votre nom</h2>
        <NameForm name={viewer.user.fullName} />
      </section>
      <section className="section-block">
        <h2>Mot de passe</h2>
        <PasswordForm />
      </section>
    </PlainShell>
  );
}
