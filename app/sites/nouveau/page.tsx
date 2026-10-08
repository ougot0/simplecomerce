import type { Metadata } from "next";
import Link from "next/link";
import { requireViewer } from "@/lib/access";
import { appMode } from "@/lib/env";
import { PlainShell } from "@/components/plain-shell";
import { ConnectWizard } from "./wizard";

export const metadata: Metadata = { title: "Relier un site" };

export default async function NewSitePage() {
  const viewer = await requireViewer();
  return (
    <PlainShell viewer={viewer} wide>
      <div className="page-head">
        <div>
          <div className="crumbs">
            <Link href="/sites?liste=1">Mes sites</Link>
          </div>
          <h1>Relier un site</h1>
        </div>
      </div>
      <ConnectWizard demo={appMode() === "demo"} isAdmin={viewer.user.isSuperAdmin && !viewer.impersonationId} />
    </PlainShell>
  );
}
