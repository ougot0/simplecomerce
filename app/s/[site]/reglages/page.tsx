import type { Metadata } from "next";
import { CONNECTORS, connectorLabel } from "@/lib/adapters/catalog";
import { loadSite } from "@/lib/site-context";
import { getStore } from "@/lib/store";
import { formatWhen } from "@/lib/ui/format";
import { ConnectionForm, DeleteSiteForm, InviteForm, SchemaJsonForm, SectionsForm, SmallAction } from "./forms";
import { removeMemberAction as removeMember, revokeInvitationAction as revokeInvitation } from "./actions";

export const metadata: Metadata = { title: "Réglages" };

export default async function SettingsPage({ params }: { params: Promise<{ site: string }> }) {
  const { site: slug } = await params;
  const { viewer, site, schema, role } = await loadSite(slug, "owner");
  const store = getStore();
  const [members, invitations, creds] = await Promise.all([store.listMembers(site.id), store.listInvitations(site.id), store.getCredentials(site.id)]);
  const def = CONNECTORS.find((c) => c.id === site.connector)!;
  // Valeurs non secrètes à réafficher dans le formulaire (l'adresse du dépôt est reconstituée).
  const config: Record<string, unknown> = { ...site.connectorConfig };
  if (site.connector === "github" && config.owner) config.repository = `https://github.com/${config.owner}/${config.repo}`;
  if (site.connector === "bitbucket" && config.workspace) config.repository = `https://bitbucket.org/${config.workspace}/${config.repo}`;
  if (site.connector === "gitlab" && config.project) config.repository = `${config.baseUrl ?? "https://gitlab.com"}/${config.project}`;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Réglages</h1>
          <p className="muted" style={{ marginTop: 8, marginBottom: 0 }}>
            {connectorLabel(site.connector)}
            {site.lastCheckAt && ` · dernière vérification ${formatWhen(site.lastCheckAt)} : ${site.lastCheckOk ? "connexion correcte" : "problème de connexion"}`}
          </p>
        </div>
      </div>

      <section>
        <h2 style={{ marginBottom: 14 }}>Équipe</h2>
        <ul className="lines" style={{ maxWidth: 760 }}>
          {members.map((m) => (
            <li key={m.userId}>
              <div className="line-main">
                <span className="line-title">{m.profile.fullName || m.profile.email}</span>
                {m.profile.isSuperAdmin && <span className="muted"> (administrateur)</span>}
                <div className="muted small">
                  {m.profile.email} · {m.role === "owner" ? "tout gérer" : "modifier le contenu"}
                </div>
              </div>
              {m.userId !== viewer.effective.id && (
                <SmallAction action={removeMember} fields={{ site: site.slug, userId: m.userId }} label="Retirer l'accès" confirm={`Retirer l'accès de ${m.profile.fullName || m.profile.email} ?`} />
              )}
            </li>
          ))}
          {invitations.map((i) => (
            <li key={i.id}>
              <div className="line-main">
                <span className="line-title">{i.email}</span> <span className="tag tag-draft">Invitation en attente</span>
                <div className="muted small">expire {formatWhen(i.expiresAt).replace(/^il y a/, "")}</div>
              </div>
              <SmallAction action={revokeInvitation} fields={{ site: site.slug, invitationId: i.id }} label="Annuler l'invitation" />
            </li>
          ))}
        </ul>
        <h3 style={{ margin: "28px 0 12px" }}>Inviter un collègue</h3>
        <InviteForm site={site.slug} />
      </section>

      <section className="section-block">
        <h2>Rubriques modifiables</h2>
        <p className="muted">Renommez les rubriques avec vos mots, ou masquez celles que vous ne modifiez jamais.</p>
        <SectionsForm site={site.slug} sections={schema.sections} />
      </section>

      <section className="section-block">
        <h2>Connexion au site</h2>
        <p className="muted">Les accès enregistrés ne sont jamais réaffichés. Laissez un champ secret vide pour garder la valeur actuelle.</p>
        <ConnectionForm site={site.slug} def={def} name={site.name} publicUrl={site.publicUrl} config={config} fingerprints={creds?.fingerprints ?? {}} />
      </section>

      {role === "admin" && (
        <section className="section-block">
          <h2>Schéma de contenu (administrateur)</h2>
          <p className="muted">Champs modifiables, formats de photo, limites de longueur. Voir docs/PREPARER-UN-SITE.md.</p>
          <SchemaJsonForm site={site.slug} schema={JSON.stringify(schema, null, 2)} />
        </section>
      )}

      <section className="section-block">
        <h2>Retirer le site</h2>
        <DeleteSiteForm site={site.slug} name={site.name} />
      </section>
    </>
  );
}
