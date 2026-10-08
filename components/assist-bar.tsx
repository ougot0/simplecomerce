import { stopAssistAction } from "@/app/admin/actions";
import type { Viewer } from "@/lib/access";

export function AssistBar({ viewer }: { viewer: Viewer }) {
  if (!viewer.impersonationId) return null;
  return (
    <form action={stopAssistAction} className="assist-bar">
      <span>
        Vous agissez pour {viewer.effective.fullName || viewer.effective.email}. Chaque modification est enregistrée à votre nom.
      </span>
      <button className="btn btn-small" type="submit">
        Revenir à mon compte
      </button>
    </form>
  );
}
