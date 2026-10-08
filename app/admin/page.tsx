import Link from "next/link";
import type { Metadata } from "next";
import { requireSuperAdmin } from "@/lib/access";
import { connectorLabel } from "@/lib/adapters/catalog";
import { getStore } from "@/lib/store";
import { formatWhen } from "@/lib/ui/format";
import { PlainShell } from "@/components/plain-shell";
import { setSiteStatusAction, startAssistAction } from "./actions";

export const metadata: Metadata = { title: "Administration" };

export default async function AdminPage() {
  const viewer = await requireSuperAdmin();
  const store = getStore();
  const [sites, profiles] = await Promise.all([store.listAllSites(), store.listProfiles()]);
  const members = new Map<string, string[]>();
  const sitesByUser = new Map<string, number>();
  for (const s of sites) {
    const list = await store.listMembers(s.id);
    members.set(s.id, list.filter((m) => !m.profile.isSuperAdmin).map((m) => m.profile.fullName || m.profile.email));
    for (const m of list) sitesByUser.set(m.userId, (sitesByUser.get(m.userId) ?? 0) + 1);
  }

  return (
    <PlainShell viewer={viewer} wide>
      <div className="page-head">
        <div>
          <h1>Administration</h1>
          <p className="page-intro">
            {sites.length} site{sites.length > 1 ? "s" : ""} · {profiles.length} compte{profiles.length > 1 ? "s" : ""} · <Link href="/admin/journal">Journal d&apos;activité</Link>
          </p>
        </div>
        <Link className="btn btn-primary" href="/sites/nouveau">
          Relier un site
        </Link>
      </div>

      <section>
        <h2 style={{ marginBottom: 14 }}>Sites</h2>
        <div className="ledger-wrap">
        <table className="ledger">
          <thead>
            <tr>
              <th>Site</th>
              <th className="hide-small">Type</th>
              <th className="hide-small">Clients</th>
              <th className="hide-small">Connexion</th>
              <th className="shrink">
                <span className="visually-hidden">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {sites.map((s) => (
              <tr key={s.id}>
                <td className="title-cell">
                  <Link href={`/s/${s.slug}`}>{s.name}</Link>
                  {s.status === "suspended" && (
                    <>
                      {" "}
                      <span className="tag tag-off">Suspendu</span>
                    </>
                  )}
                  <div className="muted small">{s.publicUrl}</div>
                </td>
                <td className="hide-small small">{connectorLabel(s.connector)}</td>
                <td className="hide-small small">{members.get(s.id)?.join(", ") || <span className="faint">aucun</span>}</td>
                <td className="hide-small small">
                  {s.lastCheckAt ? (
                    <>
                      <span className={`tag ${s.lastCheckOk ? "tag-live" : "tag-error"}`}>{s.lastCheckOk ? "OK" : "En échec"}</span> <span className="muted">{formatWhen(s.lastCheckAt)}</span>
                    </>
                  ) : (
                    <span className="faint">jamais testée</span>
                  )}
                </td>
                <td className="shrink">
                  <div className="actions" style={{ gap: 6, flexWrap: "nowrap" }}>
                    <Link className="btn btn-small" href={`/s/${s.slug}/reglages`}>
                      Réglages
                    </Link>
                    <form action={setSiteStatusAction}>
                      <input type="hidden" name="siteId" value={s.id} />
                      <input type="hidden" name="status" value={s.status === "active" ? "suspended" : "active"} />
                      <button className="btn btn-small btn-quiet" type="submit">
                        {s.status === "active" ? "Suspendre" : "Réactiver"}
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
</div>
      </section>

      <section className="section-block">
        <h2>Comptes</h2>
        <div className="ledger-wrap">
        <table className="ledger">
          <thead>
            <tr>
              <th>Personne</th>
              <th className="hide-small num">Sites</th>
              <th className="hide-small">Inscrit</th>
              <th className="shrink">
                <span className="visually-hidden">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {profiles.map((p) => (
              <tr key={p.id}>
                <td className="title-cell">
                  <strong>{p.fullName || "(sans nom)"}</strong>
                  {p.isSuperAdmin && <span className="muted"> · administrateur</span>}
                  <div className="muted small">{p.email}</div>
                </td>
                <td className="hide-small num">{sitesByUser.get(p.id) ?? 0}</td>
                <td className="hide-small small muted">{formatWhen(p.createdAt)}</td>
                <td className="shrink">
                  {!p.isSuperAdmin && (
                    <form action={startAssistAction}>
                      <input type="hidden" name="userId" value={p.id} />
                      <button className="btn btn-small" type="submit">
                        Agir pour ce client
                      </button>
                    </form>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
</div>
        <p className="muted small" style={{ marginTop: 12 }}>
          « Agir pour ce client » ouvre son espace tel qu&apos;il le voit, pendant une heure au plus. Tout ce que vous y faites est enregistré à votre nom.
        </p>
      </section>
    </PlainShell>
  );
}
