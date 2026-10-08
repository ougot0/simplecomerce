import Link from "next/link";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { loadSection } from "@/lib/site-context";
import { withAdapter } from "@/lib/sites";
import { getStore } from "@/lib/store";
import { EntryEditor } from "@/components/editor/entry-editor";
import { addLabel } from "@/lib/ui/words";

export const metadata: Metadata = { title: "Ajouter" };

export default async function NewEntryPage({ params, searchParams }: { params: Promise<{ site: string; section: string }>; searchParams: Promise<{ brouillon?: string }> }) {
  const { site: slug, section: key } = await params;
  const { brouillon } = await searchParams;
  const { site, section } = await loadSection(slug, key);
  if (section.kind !== "collection") notFound();
  const draft = brouillon ? await getStore().getDraft(brouillon) : null;
  if (draft && (draft.siteId !== site.id || draft.sectionKey !== section.key || draft.entryId)) notFound();
  const template = draft ? draft.data : await withAdapter(site, async (a) => a.template?.(section) ?? {});
  const base = `/s/${site.slug}/r/${section.key}`;
  return (
    <>
      <div className="page-head">
        <div>
          <div className="crumbs">
            <Link href={base}>{section.label}</Link>
          </div>
          <h1>{draft ? draft.label : addLabel(section.itemLabel)}</h1>
        </div>
      </div>
      <EntryEditor site={site.slug} section={section} entryId={null} draftId={draft?.id ?? null} initial={template} expected={null} assetBase={site.publicUrl} canDelete={false} cancelHref={base} />
    </>
  );
}
