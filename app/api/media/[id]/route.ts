import { NextResponse } from "next/server";
import { getViewer } from "@/lib/access";
import { getStore } from "@/lib/store";

/** Aperçu d'une photo pas encore envoyée sur le site. Réservé aux personnes qui ont accès au site. */
export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const notFound = new NextResponse("Introuvable", { status: 404 });
  if (!/^[0-9a-f-]{36}$/.test(id)) return notFound;
  const viewer = await getViewer();
  if (!viewer) return notFound;
  const store = getStore();
  const media = await store.getMedia(id);
  if (!media) return notFound;
  const isAdmin = viewer.user.isSuperAdmin && !viewer.impersonationId;
  if (!isAdmin && !(await store.getMembership(media.siteId, viewer.effective.id))) return notFound;
  const blob = await store.getMediaBlob(media.storagePath);
  if (!blob) return notFound;
  return new NextResponse(new Uint8Array(blob), {
    headers: {
      "Content-Type": media.mime,
      "Cache-Control": "private, max-age=3600",
      "Content-Security-Policy": "default-src 'none'",
      "X-Content-Type-Options": "nosniff",
    },
  });
}
