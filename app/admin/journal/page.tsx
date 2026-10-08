import Link from "next/link";
import type { Metadata } from "next";
import { requireSuperAdmin } from "@/lib/access";
import { getStore } from "@/lib/store";
import { formatWhen } from "@/lib/ui/format";
import { actorNames } from "@/lib/ui/people";
import { PlainShell } from "@/components/plain-shell";

export const metadata: Metadata = { title: "Journal d'activité" };

const KINDS: Record<string, string> = {
  login: "Connexion",
  signup: "Création de compte",
  site_created: "Site relié",
  site_removed: "Site retiré",
  site_suspended: "Site suspendu",
  site_reactivated: "Site réactivé",
  schema_detected: "Contenu détecté",
  schema_updated: "Rubriques modifiées",
  connection_updated: "Accès modifiés",
  member_invited: "Invitation envoyée",
  invitation_accepted: "Invitation acceptée",
  invitation_revoked: "Invitation annulée",
  member_removed: "Accès retiré",
  impersonation_started: "Début d'assistance",
  impersonation_ended: "Fin d'assistance",
  draft_discarded: "Brouillon jeté",
  password_changed: "Mot de passe changé",
};

export default async function JournalPage() {
  const viewer = await requireSuperAdmin();
  const store = getStore();
  const [events, sites] = await Promise.all([store.listEvents({ limit: 300 }), store.listAllSites()]);
  const names = await actorNames(events.flatMap((e) => [e.actorId, e.onBehalfOf]));
  const siteNames = new Map(sites.map((s) => [s.id, s]));
  return (
    <PlainShell viewer={viewer} wide>
      <div className="page-head">
        <div>
          <div className="crumbs">
            <Link href="/admin">Administration</Link>
          </div>
          <h1>Journal d&apos;activité</h1>
          <p className="muted" style={{ marginTop: 8, marginBottom: 0 }}>Connexions, accès, invitations, assistance. Les modifications de contenu sont dans l&apos;historique de chaque site.</p>
        </div>
      </div>
      <table className="ledger">
        <thead>
          <tr>
            <th>Quand</th>
            <th>Quoi</th>
            <th>Qui</th>
            <th className="hide-small">Site</th>
            <th className="hide-small">Détail</th>
          </tr>
        </thead>
        <tbody>
          {events.map((e) => {
            const site = e.siteId ? siteNames.get(e.siteId) : null;
            return (
              <tr key={e.id}>
                <td className="small" style={{ whiteSpace: "nowrap" }}>{formatWhen(e.at)}</td>
                <td>{KINDS[e.kind] ?? e.kind}</td>
                <td className="small">
                  {e.actorId ? names.get(e.actorId) ?? "?" : "—"}
                  {e.onBehalfOf && <span className="muted"> pour {names.get(e.onBehalfOf) ?? "?"}</span>}
                </td>
                <td className="hide-small small">{site ? <Link href={`/s/${site.slug}`}>{site.name}</Link> : "—"}</td>
                <td className="hide-small small muted code">{Object.keys(e.details).length ? JSON.stringify(e.details).slice(0, 140) : ""}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </PlainShell>
  );
}
