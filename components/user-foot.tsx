import Link from "next/link";
import type { Viewer } from "@/lib/access";

export function UserFoot({ viewer }: { viewer: Viewer }) {
  return (
    <div className="sidebar-foot">
      <span>
        <strong>{viewer.effective.fullName || viewer.effective.email}</strong>
      </span>
      <Link href="/sites">Mes sites</Link>
      <Link href="/compte">Mon compte</Link>
      {viewer.user.isSuperAdmin && !viewer.impersonationId && <Link href="/admin">Administration</Link>}
      <form action="/deconnexion" method="post">
        <button type="submit" className="btn btn-quiet" style={{ padding: 0, minHeight: 0 }}>
          Se déconnecter
        </button>
      </form>
      <Link href="/sites" className="wordmark small" style={{ marginTop: 10 }}>
        Simple<span> </span>Commerce
      </Link>
    </div>
  );
}
