import { NextResponse, type NextRequest } from "next/server";
import { signOut } from "@/lib/auth";
import { stopImpersonation } from "@/lib/access";

export async function POST(request: NextRequest) {
  await stopImpersonation();
  await signOut();
  return NextResponse.redirect(new URL("/connexion", request.url), { status: 303 });
}
