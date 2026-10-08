import Link from "next/link";
import type { Viewer } from "@/lib/access";
import { AssistBar } from "./assist-bar";

/** Mise en page des écrans hors d'un site : liste des sites, compte, administration. */
export function PlainShell({ viewer, children, wide = false }: { viewer: Viewer; children: React.ReactNode; wide?: boolean }) {
  return (
    <>
      <AssistBar viewer={viewer} />
      <header className="topbar">
        <Link href="/sites" className="wordmark">
          Simple<span> </span>Commerce
        </Link>
        <nav className="topbar-nav" aria-label="Compte">
          <Link href="/sites">Mes sites</Link>
          {viewer.user.isSuperAdmin && !viewer.impersonationId && <Link href="/admin">Administration</Link>}
          <Link href="/compte">{viewer.effective.fullName || "Mon compte"}</Link>
          <form action="/deconnexion" method="post">
            <button type="submit" className="btn btn-quiet" style={{ minHeight: 0 }}>
              Se déconnecter
            </button>
          </form>
        </nav>
      </header>
      <main className={`plain-main ${wide ? "plain-wide" : ""}`}>{children}</main>
    </>
  );
}
