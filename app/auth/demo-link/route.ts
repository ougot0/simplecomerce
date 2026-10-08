import { NextResponse, type NextRequest } from "next/server";
import { consumeDemoMagicLink } from "@/lib/auth";
import { appMode } from "@/lib/env";
import { safeNext } from "@/lib/ui/safe-next";

export async function GET(request: NextRequest) {
  if (appMode() !== "demo") return new NextResponse(null, { status: 404 });
  const ok = await consumeDemoMagicLink(request.nextUrl.searchParams.get("token") ?? "");
  const next = safeNext(request.nextUrl.searchParams.get("next"));
  return NextResponse.redirect(new URL(ok ? next : "/connexion?erreur=lien", request.url));
}
