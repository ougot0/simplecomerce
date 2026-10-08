"use client";

import { useEffect, useRef, useState } from "react";
import type { Field } from "@/lib/content/schema";
import { numberToStored, parseDecimal, priceToNumber } from "@/lib/content/price";
import { ImageInput, GalleryInput } from "./image-input";
import { addLabel } from "@/lib/ui/words";

export interface FieldContext {
  site: string;
  assetBase: string;
  errors: Record<string, string>;
}

type Data = Record<string, unknown>;

/** Un champ du formulaire, choisi selon son type. */
export function FieldInput({ field, value, onChange, path, ctx }: { field: Field; value: unknown; onChange: (v: unknown) => void; path: string; ctx: FieldContext }) {
  const id = `f-${path.replace(/[^\w-]/g, "-")}`;
  const error = ctx.errors[path];
  const label = (
    <label htmlFor={id}>
      {field.label} {field.required && <span className="optional">(obligatoire)</span>}
    </label>
  );
  const foot = (
    <>
      {field.help && <span className="help">{field.help}</span>}
      {error && <span className="error-text">{error}</span>}
    </>
  );
  const wrap = (body: React.ReactNode, withLabel = true) => (
    <div className={`field ${error ? "field-invalid" : ""}`}>
      {withLabel && label}
      {body}
      {foot}
    </div>
  );

  if (field.readOnly) {
    return (
      <div className="field">
        <span className="label">{field.label}</span>
        <span className="muted">{value === undefined || value === null || value === "" ? "—" : String(value)}</span>
      </div>
    );
  }

  switch (field.type) {
    case "text":
    case "url":
    case "email":
    case "phone": {
      const str = typeof value === "string" ? value : value === undefined || value === null ? "" : String(value);
      return wrap(
        <>
          <input
            id={id}
            type={field.type === "email" ? "email" : field.type === "url" ? "url" : field.type === "phone" ? "tel" : "text"}
            value={str}
            onChange={(e) => onChange(e.target.value)}
            aria-invalid={!!error}
            placeholder={field.type === "url" ? "https://…" : undefined}
          />
          {field.maxLength && <Counter value={str} max={field.maxLength} />}
        </>,
      );
    }
    case "textarea":
    case "markdown": {
      const str = typeof value === "string" ? value : "";
      return wrap(
        <>
          {field.type === "markdown" ? (
            <MarkdownInput id={id} value={str} onChange={onChange} />
          ) : (
            <textarea id={id} value={str} onChange={(e) => onChange(e.target.value)} aria-invalid={!!error} rows={Math.min(12, Math.max(4, Math.ceil(str.length / 70)))} />
          )}
          {field.maxLength && <Counter value={str} max={field.maxLength} />}
        </>,
      );
    }
    case "richtext":
      return wrap(<RichTextInput id={id} value={typeof value === "string" ? value : ""} onChange={onChange} />);
    case "price":
      return wrap(<PriceInput id={id} field={field} value={value} onChange={onChange} />);
    case "number": {
      return wrap(
        <input
          id={id}
          type="text"
          inputMode="decimal"
          style={{ maxWidth: 200 }}
          value={value === null || value === undefined ? "" : String(value)}
          onChange={(e) => {
            const t = e.target.value.trim();
            const n = parseDecimal(t);
            onChange(t === "" ? null : n ?? t);
          }}
        />,
      );
    }
    case "boolean":
      return (
        <div className={`field ${error ? "field-invalid" : ""}`}>
          <label className="checkbox">
            <input id={id} type="checkbox" checked={value === true} onChange={(e) => onChange(e.target.checked)} />
            <span>
              <strong>{field.label}</strong>
            </span>
          </label>
          {foot}
        </div>
      );
    case "select":
      return wrap(
        <select id={id} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(e.target.value)} style={{ maxWidth: 360 }}>
          <option value="">—</option>
          {(field.options ?? []).map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>,
      );
    case "date":
      return wrap(<input id={id} type="date" style={{ maxWidth: 220 }} value={typeof value === "string" ? value.slice(0, 10) : ""} onChange={(e) => onChange(e.target.value)} />);
    case "image":
      return wrap(<ImageInput id={id} field={field} value={typeof value === "string" ? value : ""} onChange={onChange} site={ctx.site} assetBase={ctx.assetBase} />, true);
    case "gallery":
      return wrap(<GalleryInput field={field} value={Array.isArray(value) ? (value as string[]) : []} onChange={onChange} site={ctx.site} assetBase={ctx.assetBase} />);
    case "list":
      return wrap(<ListInput id={id} value={Array.isArray(value) ? (value as string[]) : []} onChange={onChange} />);
    case "group": {
      const obj = value && typeof value === "object" && !Array.isArray(value) ? (value as Data) : {};
      return (
        <fieldset className="group">
          <legend>{field.label}</legend>
          {(field.fields ?? []).filter((f) => !f.hidden).map((sub) => (
            <FieldInput key={sub.key} field={sub} value={obj[sub.key]} onChange={(v) => onChange({ ...obj, [sub.key]: v })} path={`${path}.${sub.key}`} ctx={ctx} />
          ))}
          {foot}
        </fieldset>
      );
    }
    case "repeater":
      return <RepeaterInput field={field} value={Array.isArray(value) ? (value as Data[]) : []} onChange={onChange} path={path} ctx={ctx} />;
  }
}

function Counter({ value, max }: { value: string; max: number }) {
  const over = value.length > max;
  return (
    <span className={`counter ${over ? "over" : ""}`} aria-live="polite">
      {value.length} / {max}
    </span>
  );
}

function PriceInput({ id, field, value, onChange }: { id: string; field: Field; value: unknown; onChange: (v: unknown) => void }) {
  const toText = (v: unknown) => {
    const n = priceToNumber(field, v);
    return n === null ? "" : n.toLocaleString("fr-FR", { minimumFractionDigits: Number.isInteger(n) ? 0 : 2, maximumFractionDigits: 2, useGrouping: false });
  };
  const [text, setText] = useState(() => toText(value));
  return (
    <div className="input-suffix price-input">
      <input
        id={id}
        type="text"
        inputMode="decimal"
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          const n = e.target.value.trim() === "" ? null : parseDecimal(e.target.value);
          if (e.target.value.trim() === "" || n !== null) onChange(numberToStored(field, n));
        }}
        onBlur={() => setText(toText(value))}
      />
      <span>€</span>
    </div>
  );
}

function ListInput({ id, value, onChange }: { id: string; value: string[]; onChange: (v: unknown) => void }) {
  return (
    <div style={{ display: "grid", gap: 8 }} id={id}>
      {value.map((item, i) => (
        <div key={i} style={{ display: "flex", gap: 8 }}>
          <input type="text" value={item} onChange={(e) => onChange(value.map((v, j) => (j === i ? e.target.value : v)))} aria-label={`Ligne ${i + 1}`} />
          <button type="button" className="btn btn-small" onClick={() => onChange(value.filter((_, j) => j !== i))}>
            Retirer
          </button>
        </div>
      ))}
      <div>
        <button type="button" className="btn btn-small" onClick={() => onChange([...value, ""])}>
          Ajouter une ligne
        </button>
      </div>
    </div>
  );
}

function RepeaterInput({ field, value, onChange, path, ctx }: { field: Field; value: Data[]; onChange: (v: unknown) => void; path: string; ctx: FieldContext }) {
  const subs = (field.fields ?? []).filter((f) => !f.hidden);
  const move = (i: number, d: number) => {
    const j = i + d;
    if (j < 0 || j >= value.length) return;
    const next = [...value];
    [next[i], next[j]] = [next[j], next[i]];
    onChange(next);
  };
  const empty = () => Object.fromEntries(subs.map((f) => [f.key, f.type === "boolean" ? false : f.type === "repeater" || f.type === "list" || f.type === "gallery" ? [] : ""]));
  return (
    <fieldset className="field">
      <legend style={{ marginBottom: 8 }}>{field.label}</legend>
      {field.help && <span className="help">{field.help}</span>}
      <div className="repeater">
        {value.map((row, i) => (
          <div className="repeater-row" key={i}>
            <div className="repeater-row-fields">
              {subs.map((sub) => (
                <FieldInput key={sub.key} field={sub} value={row[sub.key]} onChange={(v) => onChange(value.map((r, j) => (j === i ? { ...r, [sub.key]: v } : r)))} path={`${path}.${i}.${sub.key}`} ctx={ctx} />
              ))}
            </div>
            {!field.fixedRows && (
              <div className="row-actions">
                <button type="button" className="btn btn-small btn-quiet" onClick={() => move(i, -1)} disabled={i === 0}>
                  Monter
                </button>
                <button type="button" className="btn btn-small btn-quiet" onClick={() => move(i, 1)} disabled={i === value.length - 1}>
                  Descendre
                </button>
                <button type="button" className="btn btn-small btn-quiet" onClick={() => onChange(value.filter((_, j) => j !== i))}>
                  Retirer
                </button>
              </div>
            )}
          </div>
        ))}
      </div>
      {!field.fixedRows && (
        <div style={{ marginTop: 10 }}>
          <button type="button" className="btn btn-small" onClick={() => onChange([...value, empty()])}>
            {field.itemLabel ? addLabel(field.itemLabel) : "Ajouter une ligne"}
          </button>
        </div>
      )}
      {ctx.errors[path] && <span className="error-text">{ctx.errors[path]}</span>}
    </fieldset>
  );
}

/** Texte enrichi volontairement limité : gras, italique, lien, liste. */
function RichTextInput({ id, value, onChange }: { id: string; value: string; onChange: (v: unknown) => void }) {
  const ref = useRef<HTMLDivElement>(null);
  const initial = useRef(value);
  useEffect(() => {
    if (ref.current) ref.current.innerHTML = initial.current;
  }, []);
  const emit = () => onChange(ref.current?.innerHTML ?? "");
  const cmd = (name: string, arg?: string) => {
    ref.current?.focus();
    document.execCommand(name, false, arg);
    emit();
  };
  return (
    <div className="richtext">
      <div className="toolbar" role="toolbar" aria-label="Mise en forme">
        <button type="button" onClick={() => cmd("bold")} title="Gras" aria-label="Gras">
          <strong>G</strong>
        </button>
        <button type="button" onClick={() => cmd("italic")} title="Italique" aria-label="Italique">
          <em>I</em>
        </button>
        <button type="button" onClick={() => cmd("insertUnorderedList")} title="Liste à puces" aria-label="Liste à puces">
          Liste
        </button>
        <button
          type="button"
          onClick={() => {
            const url = window.prompt("Adresse du lien (par exemple https://www.instagram.com/…)");
            if (url && /^(https?:\/\/|mailto:|tel:)/i.test(url.trim())) cmd("createLink", url.trim());
          }}
          title="Lien"
        >
          Lien
        </button>
        <button type="button" onClick={() => cmd("unlink")} title="Retirer le lien">
          Retirer le lien
        </button>
      </div>
      <div
        id={id}
        ref={ref}
        className="richtext-area"
        contentEditable
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        onInput={emit}
        onBlur={emit}
        onPaste={(e) => {
          // Collage en texte simple : rien de Word ou d'un autre site ne passe.
          e.preventDefault();
          document.execCommand("insertText", false, e.clipboardData.getData("text/plain"));
          emit();
        }}
      />
    </div>
  );
}

function MarkdownInput({ id, value, onChange }: { id: string; value: string; onChange: (v: unknown) => void }) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const wrapSel = (mark: string) => {
    const el = ref.current;
    if (!el) return;
    const { selectionStart: s, selectionEnd: e } = el;
    const next = `${value.slice(0, s)}${mark}${value.slice(s, e) || "texte"}${mark}${value.slice(e)}`;
    onChange(next);
  };
  return (
    <div className="richtext">
      <div className="toolbar" role="toolbar" aria-label="Mise en forme">
        <button type="button" onClick={() => wrapSel("**")} aria-label="Gras">
          <strong>G</strong>
        </button>
        <button type="button" onClick={() => wrapSel("*")} aria-label="Italique">
          <em>I</em>
        </button>
      </div>
      <textarea id={id} ref={ref} className="richtext-area" style={{ border: 0 }} value={value} onChange={(e) => onChange(e.target.value)} rows={10} />
    </div>
  );
}
