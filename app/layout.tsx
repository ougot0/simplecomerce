import type { Metadata, Viewport } from "next";
import "@fontsource/figtree/400.css";
import "@fontsource/figtree/500.css";
import "@fontsource/figtree/600.css";
import "@fontsource/figtree/700.css";
import "./globals.css";
import { appMode } from "@/lib/env";
import { ensureDemoSeed } from "@/lib/demo-seed";

export const metadata: Metadata = {
  title: { default: "Simple Commerce", template: "%s — Simple Commerce" },
  description: "Mettez à jour votre site vous-même : produits, prix, photos, textes.",
  robots: { index: false, follow: false },
};

export const viewport: Viewport = { width: "device-width", initialScale: 1, themeColor: "#f4efe6" };

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const demo = appMode() === "demo";
  if (demo) await ensureDemoSeed();
  return (
    <html lang="fr">
      <body>
        {demo && (
          <div className="demo-bar">
            Mode démonstration : rien n&apos;est envoyé sur Internet, les données sont dans le dossier .data/
          </div>
        )}
        {children}
      </body>
    </html>
  );
}
