import Link from "next/link";
import { Icon } from "@/components/icon";

export function AuthFrame({ children }: { children: React.ReactNode }) {
  return (
    <div className="auth-page">
      <aside className="auth-side">
        <Link href="/" className="wordmark">
          Simple<span> </span>Commerce
        </Link>
        <div>
          <h1>Mettez à jour votre site vous-même.</h1>
          <p>Produits, prix, photos, horaires, actualités : vous modifiez ici, votre site suit.</p>
          <ul className="steps-list">
            <li>
              <Icon name="draft" /> Vous changez un texte, un prix ou une photo.
            </li>
            <li>
              <Icon name="check" /> Vous cliquez sur « Publier sur mon site ».
            </li>
            <li>
              <Icon name="undo" /> Une erreur ? Vous annulez en un clic.
            </li>
          </ul>
        </div>
        <p className="small">Une question ? Répondez simplement à l&apos;e-mail de votre créateur de site.</p>
      </aside>
      <main className="auth-main">{children}</main>
    </div>
  );
}
