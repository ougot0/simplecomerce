import { createHash } from "node:crypto";
import Link from "next/link";
import type { Metadata } from "next";
import { getViewer } from "@/lib/access";
import { getStore } from "@/lib/store";
import { AuthFrame } from "@/app/(auth)/auth-frame";
import { AcceptButton } from "./accept";

export const metadata: Metadata = { title: "Invitation" };

export default async function InvitationPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const store = getStore();
  const inv = /^[\w-]{20,100}$/.test(token) ? await store.getInvitationByTokenHash(createHash("sha256").update(token).digest("hex")) : null;
  const valid = inv && !inv.acceptedAt && inv.expiresAt > new Date().toISOString();
  const site = valid ? await store.getSite(inv.siteId) : null;
  const viewer = await getViewer();
  const next = `/invitation/${token}`;

  if (!valid || !site) {
    return (
      <AuthFrame>
        <h2>Invitation expirée</h2>
        <p className="muted">Ce lien n&apos;est plus valable (il a déjà servi, ou a plus de 14 jours). Demandez une nouvelle invitation à la personne qui vous l&apos;a envoyé.</p>
        <Link href="/connexion">Aller à la connexion</Link>
      </AuthFrame>
    );
  }

  const inviter = await store.getProfile(inv.invitedBy);
  return (
    <AuthFrame>
      <h2>{site.name}</h2>
      <p>
        {inviter?.fullName || "Quelqu'un"} vous invite à {inv.role === "owner" ? "gérer" : "mettre à jour"} ce site avec Simple Commerce.
      </p>
      {!viewer ? (
        <div className="actions" style={{ marginTop: 16 }}>
          <Link className="btn btn-primary" href={`/inscription?next=${encodeURIComponent(next)}&email=${encodeURIComponent(inv.email)}`}>
            Créer mon compte
          </Link>
          <Link className="btn" href={`/connexion?next=${encodeURIComponent(next)}`}>
            J&apos;ai déjà un compte
          </Link>
        </div>
      ) : viewer.effective.email.toLowerCase() !== inv.email.toLowerCase() ? (
        <div className="notice notice-warn">
          <p>
            Cette invitation est destinée à <strong>{inv.email}</strong>, mais vous êtes connecté avec {viewer.effective.email}. Déconnectez-vous puis rouvrez ce lien.
          </p>
          <form action="/deconnexion" method="post">
            <button className="btn btn-small" type="submit">
              Se déconnecter
            </button>
          </form>
        </div>
      ) : (
        <AcceptButton token={token} />
      )}
    </AuthFrame>
  );
}
