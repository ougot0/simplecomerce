import { redirect } from "next/navigation";
import { getViewer } from "@/lib/access";

export default async function Home() {
  const viewer = await getViewer();
  redirect(viewer ? "/sites" : "/connexion");
}
