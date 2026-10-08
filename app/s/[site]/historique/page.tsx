import type { Metadata } from "next";
import { loadSite } from "@/lib/site-context";
import { findSection, type Section } from "@/lib/content/schema";
import { inverseOperation } from "@/lib/changes";
import { getStore, type Change } from "@/lib/store";
import { formatWhen, stripHtml } from "@/lib/ui/format";
import { actorNames } from "@/lib/ui/people";
import { formatPriceForDisplay, priceToNumber } from "@/lib/content/price";
import { ActionButton } from "@/components/action-button";
import { revertAction } from "../actions";

export const metadata: Metadata = { title: "Historique" };

const VERB: Record<Change["action"], string> = {
  create: "Ajout",
  update: "Modification",
  delete: "Suppression",
  reorder: "Nouvel ordre",
  revert: "Annulation",
};

function show(section: Section | undefined, key: string, value: unknown): string {
  const field = section?.fields.find((f) => f.key === key);
  if (value === undefined || value === null || value === "") return "(vide)";
  if (field?.type === "price") return formatPriceForDisplay(priceToNumber(field, value));
  if (field?.type === "boolean") return value ? "oui" : "non";
  if (field?.type === "image") return String(value).startsWith("sc-media:") ? "nouvelle photo" : String(value).split("/").pop() ?? "photo";
  if (Array.isArray(value)) return `${value.length} élément${value.length > 1 ? "s" : ""}`;
  if (typeof value === "object") return "…";
  const text = field?.type === "richtext" ? stripHtml(String(value)) : String(value);
  return text.length > 80 ? `${text.slice(0, 80)}…` : text;
}

/** Ce qui a changé, champ par champ, en clair. */
function Diff({ change, section }: { change: Change; section: Section | undefined }) {
  if (!change.before || !change.after) return null;
  const keys = (section?.fields ?? []).filter((f) => !f.hidden).map((f) => f.key);
  const changed = keys.filter((k) => JSON.stringify(change.before![k] ?? "") !== JSON.stringify(change.after![k] ?? ""));
  if (changed.length === 0) return null;
  return (
    <div className="diff">
      {changed.slice(0, 6).map((k) => (
        <div key={k}>
          <span className="muted">{section?.fields.find((f) => f.key === k)?.label ?? k} : </span>
          <del>{show(section, k, change.before![k])}</del> → <ins>{show(section, k, change.after![k])}</ins>
        </div>
      ))}
    </div>
  );
}

export default async function HistoryPage({ params, searchParams }: { params: Promise<{ site: string }>; searchParams: Promise<{ ok?: string }> }) {
  const { site: slug } = await params;
  const { ok } = await searchParams;
  const { site, schema } = await loadSite(slug);
  const changes = await getStore().listChanges(site.id, 100);
  const names = await actorNames(changes.flatMap((c) => [c.actorId, c.onBehalfOf]));

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Historique</h1>
          <p className="page-intro">Qui a modifié quoi, et quand. Une modification peut être annulée tant que le contenu n&apos;a pas changé depuis.</p>
        </div>
      </div>
      {ok === "annule" && <div className="notice notice-ok">Modification annulée.</div>}
      {changes.length === 0 ? (
        <p className="muted">Aucune modification pour l&apos;instant.</p>
      ) : (
        <ul className="lines">
          {changes.map((c) => {
            const section = findSection(schema, c.sectionKey);
            const canRevert = !!section && !!inverseOperation(c, section);
            return (
              <li key={c.id}>
                <div className="line-main">
                  <span className="line-title">
                    {VERB[c.action]} — {c.entryLabel}
                  </span>
                  <div className="muted small">
                    {section?.label ?? (c.sectionKey === "_fermeture" ? "Fermeture du site" : c.sectionKey)} · {names.get(c.actorId) ?? "?"}
                    {c.onBehalfOf && ` (assistance pour ${names.get(c.onBehalfOf) ?? "le client"})`} · {formatWhen(c.createdAt)}
                  </div>
                  <Diff change={c} section={section} />
                  {c.status === "failed" && <div className="error-text">Échec : {c.errorMessage}</div>}
                </div>
                <div className="actions">
                  {c.status === "pending" && <span className="tag tag-draft">En cours</span>}
                  {c.status === "failed" && <span className="tag tag-error">Non appliquée</span>}
                  {c.revertedByChangeId && <span className="tag tag-off">Annulée</span>}
                  {canRevert && (
                    <ActionButton
                      action={revertAction}
                      fields={{ site: site.slug, changeId: c.id }}
                      label="Annuler cette modification"
                      pendingLabel="Annulation…"
                      confirm={`Annuler « ${VERB[c.action].toLowerCase()} — ${c.entryLabel} » ? Le site reviendra à l'état d'avant.`}
                    />
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
