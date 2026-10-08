import Link from "next/link";
import type { Metadata } from "next";
import { loadSection, publishDelayText } from "@/lib/site-context";
import { withAdapter } from "@/lib/sites";
import { getStore } from "@/lib/store";
import { userMessageFor, type Entry } from "@/lib/adapters/types";
import { imageSrc, plural, stripHtml } from "@/lib/ui/format";
import { visibilityField } from "@/lib/ui/visibility";
import { priceToNumber, formatPriceForDisplay } from "@/lib/content/price";
import { EntryEditor } from "@/components/editor/entry-editor";
import { Flash } from "./flash";
import { Reorder } from "./reorder";
import type { Field } from "@/lib/content/schema";
import { addLabel } from "@/lib/ui/words";

export async function generateMetadata({ params }: { params: Promise<{ site: string; section: string }> }): Promise<Metadata> {
  const { site, section } = await params;
  const ctx = await loadSection(site, section);
  return { title: ctx.section.label };
}

function subtitle(field: Field | undefined, value: unknown): string {
  if (!field || value === undefined || value === null || value === "") return "";
  if (field.type === "price") return formatPriceForDisplay(priceToNumber(field, value));
  if (field.type === "richtext") return stripHtml(String(value)).slice(0, 60);
  return String(value).slice(0, 60);
}

export default async function SectionPage({
  params,
  searchParams,
}: {
  params: Promise<{ site: string; section: string }>;
  searchParams: Promise<{ ok?: string; ordre?: string; brouillon?: string }>;
}) {
  const { site: slug, section: key } = await params;
  const query = await searchParams;
  const { site, section, role } = await loadSection(slug, key);
  const store = getStore();
  const drafts = (await store.listDrafts(site.id)).filter((d) => d.sectionKey === section.key);
  const base = `/s/${site.slug}/r/${section.key}`;
  const delay = publishDelayText(site.connector);

  // ---------- Bloc unique (horaires, coordonnées, page d'accueil…) : le formulaire directement.
  if (section.kind === "singleton") {
    let data: Record<string, unknown> | null = null;
    let error: string | null = null;
    try {
      data = await withAdapter(site, (a) => a.getSingleton(section));
    } catch (err) {
      error = userMessageFor(err);
    }
    const draft = drafts[0];
    const useDraft = draft && query.brouillon === draft.id;
    return (
      <>
        <div className="page-head">
          <h1>{section.label}</h1>
        </div>
        <Flash ok={query.ok} delayText={delay} />
        {error && <div className="notice notice-error">{error}</div>}
        {draft && !useDraft && (
          <div className="notice notice-warn">
            <p>
              Un brouillon de cette rubrique attend d&apos;être mis en ligne. <Link href={`${base}?brouillon=${draft.id}`}>Reprendre le brouillon</Link>
            </p>
          </div>
        )}
        {data && (
          <EntryEditor
            key={useDraft ? draft.id : "live"}
            site={site.slug}
            section={section}
            entryId={null}
            draftId={useDraft ? draft.id : null}
            initial={useDraft ? draft.data : data}
            expected={useDraft ? draft.baseData : data}
            assetBase={site.publicUrl}
            canDelete={false}
            cancelHref={`/s/${site.slug}`}
          />
        )}
      </>
    );
  }

  // ---------- Liste (produits, actualités…)
  const { entries, error, canReorder, canCreate } = await withAdapter(site, async (adapter) => {
    const caps = adapter.capabilities(section);
    return { entries: await adapter.listEntries(section), error: null as string | null, canReorder: caps.reorder, canCreate: caps.create };
  }).catch((err) => ({ entries: [] as Entry[], error: userMessageFor(err), canReorder: false, canCreate: false }));
  const titleKey = section.titleField;
  const imageKey = section.imageField;
  const subField = section.fields.find((f) => f.key === section.subtitleField);
  const visKey = visibilityField(section);
  const thumbOf = (e: Entry) => {
    const v = imageKey ? e.data[imageKey] : undefined;
    return imageSrc(Array.isArray(v) ? v[0] : v, site.publicUrl);
  };
  const titleOf = (e: Entry) => String((titleKey && e.data[titleKey]) || `${section.itemLabel ?? "Élément"} sans titre`);
  const draftFor = new Map(drafts.filter((d) => d.entryId).map((d) => [d.entryId!, d]));
  const newDrafts = drafts.filter((d) => !d.entryId);
  const itemLabel = section.itemLabel ?? "élément";

  if (query.ordre && canReorder) {
    return (
      <>
        <div className="page-head">
          <div>
            <div className="crumbs">
              <Link href={base}>{section.label}</Link>
            </div>
            <h1>Changer l&apos;ordre</h1>
          </div>
        </div>
        <Reorder site={site.slug} section={section.key} rows={entries.map((e) => ({ id: e.id, title: titleOf(e), thumb: thumbOf(e) }))} cancelHref={base} />
      </>
    );
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{section.label}</h1>
          {!error && <p className="muted" style={{ marginTop: 8, marginBottom: 0 }}>{plural(entries.length, itemLabel)} sur votre site.</p>}
        </div>
        <div className="actions">
          {canReorder && entries.length > 1 && (
            <Link className="btn" href={`${base}?ordre=1`}>
              Changer l&apos;ordre
            </Link>
          )}
          {canCreate && (
            <Link className="btn btn-primary" href={`${base}/nouveau`}>
              {addLabel(section.itemLabel)}
            </Link>
          )}
        </div>
      </div>
      <Flash ok={query.ok} delayText={delay} />
      {error && <div className="notice notice-error">{error}</div>}

      {newDrafts.length > 0 && (
        <div className="notice notice-warn">
          <p>
            Pas encore en ligne :{" "}
            {newDrafts.map((d, i) => (
              <span key={d.id}>
                {i > 0 && ", "}
                <Link href={`${base}/nouveau?brouillon=${d.id}`}>{d.label}</Link>
              </span>
            ))}
          </p>
        </div>
      )}

      {!error && entries.length === 0 ? (
        <p className="muted">Aucun élément pour l&apos;instant.</p>
      ) : (
        !error && (
          <table className="ledger">
            <thead>
              <tr>
                {imageKey && <th className="shrink"><span className="visually-hidden">Photo</span></th>}
                <th>Nom</th>
                {subField && <th className="num hide-small">{subField.label}</th>}
                <th className="shrink">État</th>
                <th className="shrink"><span className="visually-hidden">Action</span></th>
              </tr>
            </thead>
            <tbody>
              {entries.map((e) => {
                const thumb = thumbOf(e);
                const draft = draftFor.get(e.id);
                const hidden = visKey ? e.data[visKey] === false : false;
                const href = `${base}/e/${encodeURIComponent(e.id)}`;
                return (
                  <tr key={e.id}>
                    {imageKey && (
                      <td className="shrink">
                        {thumb ? <img className="thumb" src={thumb} alt="" /> : <span className="thumb thumb-empty">pas de photo</span>}
                      </td>
                    )}
                    <td className="title-cell">
                      <Link href={href}>{titleOf(e)}</Link>
                    </td>
                    {subField && <td className="num hide-small">{subtitle(subField, e.data[subField.key])}</td>}
                    <td className="shrink">
                      {draft ? (
                        <span className="tag tag-draft">Brouillon</span>
                      ) : hidden ? (
                        <span className="tag tag-off">Masqué</span>
                      ) : (
                        <span className="tag tag-live">En ligne</span>
                      )}
                    </td>
                    <td className="shrink">
                      <Link className="btn btn-small" href={draft ? `${href}?brouillon=${draft.id}` : href}>
                        Modifier
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )
      )}
      {role === "admin" && (
        <p className="faint small" style={{ marginTop: 18 }}>
          Vue administrateur.
        </p>
      )}
    </>
  );
}
