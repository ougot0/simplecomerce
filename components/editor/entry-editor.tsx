"use client";

import Link from "next/link";
import { useActionState, useMemo, useState } from "react";
import type { Field, Section } from "@/lib/content/schema";
import { formatPriceForDisplay, priceToNumber } from "@/lib/content/price";
import { imageSrc, stripHtml } from "@/lib/ui/format";
import { submitKeepingValues } from "@/components/no-reset";
import { deleteEntryAction, saveEntryAction, type EditorState } from "@/app/s/[site]/actions";
import { FieldInput, type FieldContext } from "./fields";

type Data = Record<string, unknown>;

/** Repère la position d'origine des lignes répétées pour conserver leurs données cachées. */
function withRowIndexes(fields: Field[], data: Data): Data {
  const out: Data = { ...data };
  for (const f of fields) {
    if (f.type === "repeater" && Array.isArray(data[f.key])) {
      out[f.key] = (data[f.key] as Data[]).map((row, i) => ({ ...withRowIndexes(f.fields ?? [], row ?? {}), __index: i }));
    } else if (f.type === "group" && data[f.key] && typeof data[f.key] === "object") {
      out[f.key] = withRowIndexes(f.fields ?? [], data[f.key] as Data);
    }
  }
  return out;
}

function Preview({ section, values, assetBase }: { section: Section; values: Data; assetBase: string }) {
  const title = section.titleField ? values[section.titleField] : undefined;
  const imgValue = section.imageField ? values[section.imageField] : undefined;
  const img = imageSrc(Array.isArray(imgValue) ? imgValue[0] : imgValue, assetBase);
  const subField = section.fields.find((f) => f.key === section.subtitleField) ?? section.fields.find((f) => f.type === "price" && !f.hidden);
  let sub = subField ? values[subField.key] : undefined;
  if (subField?.type === "price") sub = formatPriceForDisplay(priceToNumber(subField, sub));
  const textField = section.fields.find((f) => !f.hidden && ["textarea", "richtext", "markdown"].includes(f.type));
  const text = textField ? stripHtml(String(values[textField.key] ?? "")).slice(0, 260) : "";
  return (
    <aside className="preview" aria-label="Aperçu">
      <div className="preview-label">Aperçu approximatif — l&apos;apparence exacte dépend de votre site</div>
      <div className="preview-body">
        {section.imageField && (img ? <img src={img} alt="" /> : <div className="thumb-empty" style={{ aspectRatio: "4 / 3", background: "var(--paper-sunk)", marginBottom: 14 }}>Aucune photo</div>)}
        <div className="p-title">{String(title || (section.kind === "singleton" ? section.label : "Sans titre"))}</div>
        {sub ? <div className="p-sub">{String(sub)}</div> : null}
        {text && <div className="p-text">{text}</div>}
      </div>
    </aside>
  );
}

export function EntryEditor({
  site,
  section,
  entryId,
  draftId,
  initial,
  expected,
  assetBase,
  canDelete,
  cancelHref,
}: {
  site: string;
  section: Section;
  entryId: string | null;
  draftId: string | null;
  initial: Data;
  expected: Data | null;
  assetBase: string;
  canDelete: boolean;
  cancelHref: string;
}) {
  const [values, setValues] = useState<Data>(() => withRowIndexes(section.fields, initial));
  const [dirty, setDirty] = useState(false);
  const [state, action, pending] = useActionState<EditorState, FormData>(saveEntryAction, {});
  const [delState, delAction, deleting] = useActionState<EditorState, FormData>(deleteEntryAction, {});
  const [confirmDelete, setConfirmDelete] = useState(false);
  const fields = useMemo(() => section.fields.filter((f) => !f.hidden), [section]);
  const ctx: FieldContext = { site, assetBase, errors: state.fieldErrors ?? {} };
  const showPreview = section.kind === "collection" && (section.imageField || section.titleField);
  const itemLabel = section.itemLabel ?? "élément";
  const title = section.titleField ? String(values[section.titleField] ?? "") : "";

  const form = (
    <form onSubmit={submitKeepingValues(action)} className="form panel" noValidate>
      <input type="hidden" name="site" value={site} />
      <input type="hidden" name="section" value={section.key} />
      <input type="hidden" name="entryId" value={entryId ?? ""} />
      <input type="hidden" name="draftId" value={draftId ?? ""} />
      <input type="hidden" name="payload" value={JSON.stringify(values)} />
      <input type="hidden" name="expected" value={expected ? JSON.stringify(expected) : ""} />

      {draftId && (
        <div className="notice notice-warn">
          <p>Vous modifiez un brouillon : il n&apos;est pas encore visible sur votre site.</p>
        </div>
      )}
      {(state.error || delState.error) && (
        <div className="notice notice-error" role="alert">
          <p>{state.error ?? delState.error}</p>
          {(state.conflict || delState.conflict) && (
            <p>
              <a href="">Recharger la page</a>
            </p>
          )}
        </div>
      )}

      {fields.map((f) => (
        <FieldInput
          key={f.key}
          field={f}
          value={values[f.key]}
          path={f.key}
          ctx={ctx}
          onChange={(v) => {
            setValues((prev) => ({ ...prev, [f.key]: v }));
            setDirty(true);
          }}
        />
      ))}

      <div className="savebar">
        <button className="btn btn-primary" type="submit" name="mode" value="publish" disabled={pending}>
          {pending ? "Publication…" : "Publier sur mon site"}
        </button>
        <button className="btn" type="submit" name="mode" value="draft" disabled={pending}>
          Enregistrer sans publier
        </button>
        <Link
          className="btn btn-quiet"
          href={cancelHref}
          onClick={(e) => {
            if (dirty && !window.confirm("Quitter sans enregistrer vos modifications ?")) e.preventDefault();
          }}
        >
          Annuler
        </Link>
        <span className="spacer" />
        {canDelete && entryId && !confirmDelete && (
          <button type="button" className="btn btn-danger btn-small" onClick={() => setConfirmDelete(true)}>
            Supprimer…
          </button>
        )}
      </div>
    </form>
  );

  return (
    <>
      <div className={showPreview ? "editor" : undefined}>
        {form}
        {showPreview && <Preview section={section} values={values} assetBase={assetBase} />}
      </div>
      {confirmDelete && entryId && (
        <form action={delAction} className="notice notice-error" style={{ marginTop: 20, maxWidth: 640 }}>
          <input type="hidden" name="site" value={site} />
          <input type="hidden" name="section" value={section.key} />
          <input type="hidden" name="entryId" value={entryId} />
          <input type="hidden" name="expected" value={expected ? JSON.stringify(expected) : ""} />
          <p>
            <strong>Supprimer « {title || itemLabel} » de votre site ?</strong> Vous pourrez l&apos;annuler depuis l&apos;historique.
          </p>
          <div className="actions">
            <button type="submit" className="btn btn-danger" disabled={deleting}>
              {deleting ? "Suppression…" : "Oui, supprimer"}
            </button>
            <button type="button" className="btn btn-quiet" onClick={() => setConfirmDelete(false)}>
              Non, garder
            </button>
          </div>
        </form>
      )}
    </>
  );
}
