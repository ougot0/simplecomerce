import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { requireViewer } from "@/lib/access";
import { getStore } from "@/lib/store";
import { connectorLabel } from "@/lib/adapters/catalog";
import { PlainShell } from "@/components/plain-shell";

export const metadata: Metadata = { title: "Mes sites" };

export default async function SitesPage({ searchParams }: { searchParams: Promise<{ liste?: string }> }) {
  const viewer = await requireViewer();
  const sites = await getStore().listSitesForUser(viewer.effective.id);
  const { liste } = await searchParams;
  // Un seul site : on y va directement, c'est le cas de la plupart des clients.
  if (sites.length === 1 && !liste && !(viewer.user.isSuperAdmin && !viewer.impersonationId)) redirect(`/s/${sites[0].slug}`);

  return (
    <PlainShell viewer={viewer}>
      <div className="page-head">
        <div>
          <h1>{sites.length ? "Vos sites" : "Bienvenue"}</h1>
          {!sites.length && <p className="muted" style={{ marginTop: 10 }}>Pour commencer, reliez votre site à Simple Commerce. Cela prend quelques minutes, une seule fois.</p>}
        </div>
        <Link className="btn btn-primary" href="/sites/nouveau">
          Relier un site
        </Link>
      </div>
      {sites.length > 0 && (
        <ul className="lines site-rows">
          {sites.map((s) => (
            <li key={s.id}>
              <div className="line-main">
                <Link className="line-title" href={`/s/${s.slug}`}>
                  {s.name}
                </Link>
                <div className="muted small">
                  {connectorLabel(s.connector)} · {s.role === "owner" ? "propriétaire" : "collaborateur"}
                  {s.status === "suspended" && " · suspendu"}
                </div>
              </div>
              <Link className="btn btn-small" href={`/s/${s.slug}`}>
                Ouvrir
              </Link>
            </li>
          ))}
        </ul>
      )}
      {viewer.user.isSuperAdmin && !viewer.impersonationId && (
        <p className="muted" style={{ marginTop: 28 }}>
          Les sites de tous vos clients sont dans l&apos;<Link href="/admin">administration</Link>.
        </p>
      )}
    </PlainShell>
  );
}
