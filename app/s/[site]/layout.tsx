import Link from "next/link";
import { loadSite } from "@/lib/site-context";
import { getStore } from "@/lib/store";
import { AssistBar } from "@/components/assist-bar";
import { UserFoot } from "@/components/user-foot";
import { NavLinks } from "./nav-links";
import { MobileMenu } from "./mobile-menu";
import { Icon } from "@/components/icon";

export default async function SiteLayout({ children, params }: { children: React.ReactNode; params: Promise<{ site: string }> }) {
  const { site: slug } = await params;
  const { viewer, site, role, sections } = await loadSite(slug);
  const drafts = await getStore().listDrafts(site.id);
  const base = `/s/${site.slug}`;
  const publicHref = site.publicUrl;

  return (
    <>
      <AssistBar viewer={viewer} />
      <div className="shell">
        <aside className="sidebar">
          <div>
            <Link href={base} className="site-name" style={{ textDecoration: "none" }}>
              {site.name}
            </Link>
            <br />
            {publicHref && (
              <a className="site-link" href={publicHref} target="_blank" rel="noopener noreferrer">
                <Icon name="eye" /> Voir mon site
              </a>
            )}
          </div>
          <MobileMenu>
          <nav aria-label="Contenu du site">
            <div className="nav-title">Mon contenu</div>
            <NavLinks
              items={[
                { href: base, label: "Accueil du portail", icon: "home", exact: true },
                ...sections.map((s) => ({ href: `${base}/r/${s.key}`, label: s.label, icon: s.kind === "collection" ? "list" : "text" })),
              ]}
            />
          </nav>
          <nav aria-label="Suivi">
            <div className="nav-title">Suivi</div>
            <NavLinks
              items={[
                { href: `${base}/brouillons`, label: "Brouillons", icon: "draft", count: drafts.length ? String(drafts.length) : undefined },
                { href: `${base}/historique`, label: "Historique", icon: "clock" },
                ...(role !== "editor" ? [{ href: `${base}/reglages`, label: "Réglages", icon: "settings" }] : []),
              ]}
            />
          </nav>
          <UserFoot viewer={viewer} />
          </MobileMenu>
        </aside>
        <main className="main">{children}</main>
      </div>
    </>
  );
}
