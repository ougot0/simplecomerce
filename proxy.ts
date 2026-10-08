import { NextResponse, type NextRequest } from "next/server";
import { createServerClient } from "@supabase/ssr";

/**
 * Rafraîchit la session Supabase à chaque requête (production uniquement).
 * Les vérifications de droits ne sont PAS faites ici mais dans chaque page et action (lib/access.ts).
 */
export async function proxy(request: NextRequest) {
  if (process.env.SIMPLECOMMERCE_MODE === "demo" || !process.env.SUPABASE_URL || !process.env.SUPABASE_ANON_KEY) {
    return NextResponse.next();
  }
  let response = NextResponse.next({ request });
  const remember = request.cookies.get("sc-remember")?.value !== "0";
  const supabase = createServerClient(process.env.SUPABASE_URL, process.env.SUPABASE_ANON_KEY, {
    cookies: {
      getAll: () => request.cookies.getAll(),
      setAll: (list) => {
        for (const { name, value } of list) request.cookies.set(name, value);
        response = NextResponse.next({ request });
        for (const { name, value, options } of list) {
          const base = { ...options, httpOnly: true, secure: true, sameSite: "lax" as const, path: "/" };
          if (remember) response.cookies.set(name, value, { ...base, maxAge: 60 * 60 * 24 * 30 });
          else {
            const { maxAge: _m, expires: _e, ...session } = base;
            void _m;
            void _e;
            response.cookies.set(name, value, session);
          }
        }
      },
    },
  });
  await supabase.auth.getUser();
  return response;
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico|demo-sites|api/media).*)"],
};
