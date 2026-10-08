"use client";

import type { ConnectorDefinition } from "@/lib/adapters/catalog";

/** Champs d'un type de site. Les secrets déjà enregistrés ne sont jamais renvoyés : on affiche leur empreinte. */
export function ConnectorFields({
  def,
  values = {},
  fingerprints = {},
  errors = {},
}: {
  def: ConnectorDefinition;
  values?: Record<string, unknown>;
  fingerprints?: Record<string, string>;
  errors?: Record<string, string>;
}) {
  const render = (f: ConnectorDefinition["fields"][number]) => {
    const id = `c_${f.name}`;
    const err = errors[id];
    const saved = f.secret ? fingerprints[f.name] : undefined;
    const value = f.secret ? undefined : String(values[f.name] ?? f.default ?? "");
    return (
      <div key={f.name} className={`field ${err ? "field-invalid" : ""}`}>
        <label htmlFor={id}>
          {f.label} {!f.required && <span className="optional">(facultatif)</span>}
        </label>
        {f.type === "select" ? (
          <select id={id} name={id} defaultValue={value}>
            {f.options?.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        ) : f.type === "textarea" ? (
          <textarea id={id} name={id} className="code" style={{ minHeight: 120 }} placeholder={saved ? `Enregistrée (${saved}) — laisser vide pour la garder` : f.placeholder} autoComplete="off" spellCheck={false} />
        ) : (
          <input
            id={id}
            name={id}
            type={f.type === "password" ? "password" : f.type === "number" ? "number" : f.type === "url" ? "url" : "text"}
            defaultValue={value}
            placeholder={saved ? `Enregistré (${saved}) — laisser vide pour le garder` : f.placeholder}
            autoComplete={f.secret ? "new-password" : "off"}
            spellCheck={false}
          />
        )}
        {f.help && <span className="help">{f.help}</span>}
        {err && <span className="error-text">{err}</span>}
      </div>
    );
  };
  const basic = def.fields.filter((f) => !f.advanced);
  const advanced = def.fields.filter((f) => f.advanced);
  return (
    <>
      {basic.map(render)}
      {advanced.length > 0 && (
        <details className="advanced">
          <summary>Réglages avancés (à laisser tels quels en général)</summary>
          <div className="form">{advanced.map(render)}</div>
        </details>
      )}
    </>
  );
}

export function ReportView({ report }: { report: { ok: boolean; checks: { label: string; ok: boolean; hint?: string }[] } }) {
  return (
    <div className={`notice ${report.ok ? "notice-ok" : "notice-error"}`} role="status">
      <p>
        <strong>{report.ok ? "La connexion fonctionne." : "La connexion ne fonctionne pas encore."}</strong>
      </p>
      <ul className="checks">
        {report.checks.map((c, i) => (
          <li key={i}>
            <span className={`tag ${c.ok ? "tag-live" : "tag-error"}`}>{c.ok ? "OK" : "À revoir"}</span>
            <span>
              {c.label}
              {c.hint && <span className="help" style={{ display: "block" }}>{c.hint}</span>}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
