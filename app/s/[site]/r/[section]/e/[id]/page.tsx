import Link from "next/link";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { loadSection } from "@/lib/site-context";
import { withAdapter } from "@/lib/sites";
import { getStore } from "@/lib/store";
import { userMessageFor, type Entry } from "@/lib/adapters/types";
import { EntryEditor } from "@/components/editor/entry-editor";

export const metadata: Metadata = { title: "Modifier" };

export default async function EditEntryPage({ params, searchParams }: { params: Promise<{ site: string; section: string; id: string }>; searchParams: Promise<{ brouillon?: string }> }) {
  const { site: slug, section: key, id: rawId } = await params;
  const { brouillon } = await searchParams;
  const id = decodeURIComponent(rawId);
  const { site, section } = await loadSection(slug, key);
  if (section.kind !== "collection") notFound();
  const base = `/s/${site.slug}/r/${section.key}`;

  const loaded = await withAdapter(site, async (a) => ({ entry: await a.getEntry(section, id), canDelete: a.capabilities(section).delete })).then(
    (r) => ({ ...r, error: null as string | null }),
    (err) => ({ entry: null as Entry | null, canDelete: false, error: userMessageFor(err) }),
  );
  const { canDelete, error } = loaded;
  const store = getStore();
  const draft = brouillon ? await store.getDraft(brouillon) : await store.findDraft(site.id, section.key, id);
  if (draft && (draft.siteId !== site.id || draft.entryId !== id)) notFound();
  const useDraft = !!draft && brouillon === draft.id;
  const found = loaded.entry;

  if (!found && !error) {
    return (
      <>
        <div className="page-head">
          <h1>Introuvable</h1>
        </div>
        <p>Cet élément n&apos;existe plus sur votre site. Il a peut-être été supprimé.</p>
        <Link href={base}>Retour à {section.label}</Link>
      </>
    );
  }

  return (
    <>
      <div className="page-head">
        <div>
          <div className="crumbs">
            <Link href={base}>{section.label}</Link>
          </div>
          <h1>{found ? String((section.titleField && found.data[section.titleField]) || section.itemLabel || "Élément") : "Modifier"}</h1>
        </div>
      </div>
      {error && <div className="notice notice-error">{error}</div>}
      {draft && !useDraft && (
        <div className="notice notice-warn">
          <p>
            Un brouillon de cet élément attend d&apos;être mis en ligne. <Link href={`?brouillon=${draft.id}`}>Reprendre le brouillon</Link>
          </p>
        </div>
      )}
      {found && (
        <EntryEditor
          key={useDraft ? draft!.id : "live"}
          site={site.slug}
          section={section}
          entryId={id}
          draftId={useDraft ? draft!.id : null}
          initial={useDraft ? draft!.data : found.data}
          expected={useDraft ? draft!.baseData : found.data}
          assetBase={site.publicUrl}
          canDelete={canDelete}
          cancelHref={base}
        />
      )}
    </>
  );
}
