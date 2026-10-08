import { NextResponse, type NextRequest } from "next/server";
import { supabaseServer } from "@/lib/supabase/server";
import { safeNext } from "@/lib/ui/safe-next";
import { siteUrl } from "@/lib/env";

/** Retour des liens envoyés par e-mail (confirmation, lien magique, mot de passe oublié). */
export async function GET(request: NextRequest) {
  const code = request.nextUrl.searchParams.get("code");
  const next = safeNext(request.nextUrl.searchParams.get("next"));
  if (code) {
    const supabase = await supabaseServer();
    const { error } = await supabase.auth.exchangeCodeForSession(code);
    if (!error) return NextResponse.redirect(`${siteUrl()}${next}`);
  }
  return NextResponse.redirect(`${siteUrl()}/connexion?erreur=lien`);
}
