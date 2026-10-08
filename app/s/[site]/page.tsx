import Link from "next/link";
import type { Metadata } from "next";
import { loadSite, publishDelayText } from "@/lib/site-context";
import { getStore } from "@/lib/store";
import { formatWhen, plural } from "@/lib/ui/format";
import { actorNames } from "@/lib/ui/people";
import { listOf } from "@/lib/ui/words";
import { withAdapter } from "@/lib/sites";

export const metadata: Metadata = { title: "Accueil" };

export default async function SiteHome({ params, searchParams }: { params: Promise<{ site: string }>; searchParams: Promise<{ bienvenue?: string }> }) {
  const { site: slug } = await params;
  const { bienvenue } = await searchParams;
  const { viewer, site, sections, role } = await loadSite(slug);
  const store = getStore();
  const [changes, drafts] = await Promise.all([store.listChanges(site.id, 8), store.listDrafts(site.id)]);
  const names = await actorNames(changes.map((c) => c.actorId).concat(changes.map((c) => c.onBehalfOf ?? "")));
  const lastBySection = new Map<string, string>();
  for (const c of [...changes].reverse()) if (c.status === "applied") lastBySection.set(c.sectionKey, c.createdAt);
  const closure = await withAdapter(site, async (a) => (a.getStatus ? a.getStatus() : null)).catch(() => null);
  const firstName = (viewer.effective.fullName || "").split(" ")[0];
  const base = `/s/${site.slug}`;

  return (
    <>
      <div className="page-head">
        <h1>{firstName ? `Bonjour ${firstName}.` : "Bonjour."}</h1>
      </div>

      {bienvenue && (
        <div className="notice notice-ok">
          <p>
            <strong>Votre site est relié.</strong> {sections.length ? `${plural(sections.length, "rubrique")} ${sections.length > 1 ? "sont modifiables" : "est modifiable"} ci-dessous.` : "Aucune rubrique modifiable n'a encore été trouvée."}
          </p>
          {!sections.length && <p>Le contenu du site doit d&apos;abord être séparé du code. Votre créateur de site peut s&apos;en charger : le guide « Préparer mon site » lui explique comment.</p>}
          {sections.length > 0 && role !== "editor" && <p>Vous pouvez renommer ou masquer des rubriques dans <Link href={`${base}/reglages`}>Réglages</Link>.</p>}
        </div>
      )}

      {closure?.closed && (
        <div className="notice notice-error">
          <p>
            <strong>Votre site est fermé temporairement.</strong> Vos visiteurs voient : « {closure.message} ».{" "}
            {role !== "editor" ? <Link href={`${base}/reglages`}>Rouvrir le site</Link> : "Le propriétaire du site peut le rouvrir."}
          </p>
        </div>
      )}

      {drafts.length > 0 && (
        <div className="notice notice-warn">
          <p>
            {plural(drafts.length, "brouillon")} en attente : {drafts.length > 1 ? "ils ne sont pas" : "il n'est pas"} encore visible{drafts.length > 1 ? "s" : ""} sur votre site.{" "}
            <Link href={`${base}/brouillons`}>Voir les brouillons</Link>
          </p>
        </div>
      )}

      <section>
        <h2 style={{ marginBottom: 14 }}>Que voulez-vous mettre à jour ?</h2>
        {sections.length === 0 ? (
          <p className="muted">Aucune rubrique pour le moment.</p>
        ) : (
          <ul className="lines">
            {sections.map((s) => (
              <li key={s.key}>
                <div className="line-main">
                  <Link className="line-title" href={`${base}/r/${s.key}`} style={{ fontSize: 20 }}>
                    {s.label}
                  </Link>
                  <div className="muted small">
                    {s.kind === "collection" ? listOf(s.label) : "Textes et informations"}
                    {lastBySection.has(s.key) && ` · modifié ${formatWhen(lastBySection.get(s.key)!)}`}
                  </div>
                </div>
                <Link className="btn btn-small" href={`${base}/r/${s.key}`}>
                  {s.kind === "collection" ? "Ouvrir" : "Modifier"}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="section-block">
        <h2>Dernières modifications</h2>
        {changes.length === 0 ? (
          <p className="muted">Rien pour l&apos;instant. Vos modifications apparaîtront ici. {publishDelayText(site.connector)}</p>
        ) : (
          <ul className="lines">
            {changes.map((c) => (
              <li key={c.id}>
                <div className="line-main">
                  <span className="line-title">{c.entryLabel}</span>{" "}
                  <span className="muted">
                    — {c.action === "create" ? "ajouté" : c.action === "delete" ? "supprimé" : c.action === "reorder" ? "ordre modifié" : c.action === "revert" ? "modification annulée" : "modifié"}
                  </span>
                  <div className="muted small">
                    {names.get(c.actorId) ?? "Quelqu'un"}
                    {c.onBehalfOf && ` (assistance pour ${names.get(c.onBehalfOf) ?? "le client"})`} · {formatWhen(c.createdAt)}
                  </div>
                </div>
                {c.status === "failed" ? <span className="tag tag-error">Échec</span> : c.status === "pending" ? <span className="tag tag-draft">En cours</span> : null}
              </li>
            ))}
          </ul>
        )}
        {changes.length > 0 && (
          <p style={{ marginTop: 12 }}>
            <Link href={`${base}/historique`}>Tout l&apos;historique</Link>
          </p>
        )}
      </section>
    </>
  );
}
