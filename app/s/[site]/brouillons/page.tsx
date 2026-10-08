import Link from "next/link";
import type { Metadata } from "next";
import { loadSite, publishDelayText } from "@/lib/site-context";
import { findSection } from "@/lib/content/schema";
import { getStore } from "@/lib/store";
import { formatWhen } from "@/lib/ui/format";
import { actorNames } from "@/lib/ui/people";
import { ActionButton } from "@/components/action-button";
import { discardDraftAction, publishDraftAction } from "../actions";
import { Flash } from "../r/[section]/flash";

export const metadata: Metadata = { title: "Brouillons" };

export default async function DraftsPage({ params, searchParams }: { params: Promise<{ site: string }>; searchParams: Promise<{ ok?: string }> }) {
  const { site: slug } = await params;
  const { ok } = await searchParams;
  const { site, schema } = await loadSite(slug);
  const drafts = await getStore().listDrafts(site.id);
  const names = await actorNames(drafts.map((d) => d.updatedBy));
  const base = `/s/${site.slug}`;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Brouillons</h1>
          <p className="muted" style={{ marginTop: 8, marginBottom: 0 }}>Préparés mais pas encore visibles sur votre site.</p>
        </div>
      </div>
      <Flash ok={ok} delayText={publishDelayText(site.connector)} />
      {drafts.length === 0 ? (
        <p className="muted">Aucun brouillon. Pour en créer un, utilisez « Garder en brouillon » dans un formulaire.</p>
      ) : (
        <ul className="lines">
          {drafts.map((d) => {
            const section = findSection(schema, d.sectionKey);
            const href = !section
              ? null
              : section.kind === "singleton"
                ? `${base}/r/${section.key}?brouillon=${d.id}`
                : d.entryId
                  ? `${base}/r/${section.key}/e/${encodeURIComponent(d.entryId)}?brouillon=${d.id}`
                  : `${base}/r/${section.key}/nouveau?brouillon=${d.id}`;
            return (
              <li key={d.id}>
                <div className="line-main">
                  {href ? (
                    <Link className="line-title" href={href}>
                      {d.label}
                    </Link>
                  ) : (
                    <span className="line-title">{d.label}</span>
                  )}
                  <div className="muted small">
                    {section?.label ?? "Rubrique supprimée"} · {d.entryId ? "modification" : "nouvel élément"} · {names.get(d.updatedBy) ?? "?"}, {formatWhen(d.updatedAt)}
                  </div>
                </div>
                <div className="actions">
                  <ActionButton action={publishDraftAction} fields={{ site: site.slug, draftId: d.id }} label="Mettre en ligne" pendingLabel="Mise en ligne…" className="btn btn-small btn-primary" />
                  <ActionButton action={discardDraftAction} fields={{ site: site.slug, draftId: d.id }} label="Jeter" className="btn btn-small btn-quiet" confirm={`Jeter le brouillon « ${d.label} » ?`} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
