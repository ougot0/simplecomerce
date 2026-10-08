"use client";

import { submitKeepingValues } from "@/components/no-reset";
import { useActionState, useState } from "react";
import { CONNECTORS, GROUP_LABELS, NOT_YET_SUPPORTED, type ConnectorDefinition } from "@/lib/adapters/catalog";
import { ConnectorFields, ReportView } from "@/components/connector-fields";
import { connectSiteAction, type ConnectState } from "../actions";

export function ConnectWizard({ demo, isAdmin }: { demo: boolean; isAdmin: boolean }) {
  const available = CONNECTORS.filter((c) => !c.demoOnly || demo);
  const [choice, setChoice] = useState<ConnectorDefinition | null>(null);
  const [state, action, pending] = useActionState<ConnectState, FormData>(connectSiteAction, {});

  if (!choice) {
    const groups = (["plateforme", "code", "hebergeur", "autre"] as const).map((g) => ({ g, items: available.filter((c) => c.group === g) }));
    return (
      <div className="two-cols">
        <div>
          <h2>Comment votre site est-il fait ?</h2>
          <p className="muted" style={{ marginTop: 8 }}>Si vous ne savez pas, demandez à la personne qui a créé votre site : la réponse tient en un mot.</p>
          {groups.map(({ g, items }) =>
            items.length ? (
              <div key={g}>
                <div className="choice-group-title">{GROUP_LABELS[g]}</div>
                <ul className="choice-list">
                  {items.map((c) => (
                    <li key={c.id}>
                      <label>
                        <input type="radio" name="connector-choice" onChange={() => setChoice(c)} />
                        <span>
                          <span className="choice-title">{c.label}</span>
                          <br />
                          <span className="muted small">{c.description}</span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null,
          )}
        </div>
        <aside className="card">
          <h3>Pas encore disponibles</h3>
          <ul className="lines small" style={{ marginTop: 10 }}>
            {NOT_YET_SUPPORTED.map((n) => (
              <li key={n.name}>
                <span>
                  <strong>{n.name}</strong>
                  <br />
                  <span className="muted">{n.reason}</span>
                </span>
              </li>
            ))}
          </ul>
          <p className="muted small" style={{ marginTop: 14 }}>
            Un site fait autrement peut presque toujours être relié avec « Mon site a sa propre base de données » ou en séparant son contenu dans un fichier.
          </p>
        </aside>
      </div>
    );
  }

  const err = state.fieldErrors ?? {};
  return (
    <div className="two-cols">
      <form onSubmit={submitKeepingValues(action)} className="form panel" noValidate>
        <input type="hidden" name="connector" value={choice.id} />
        <div>
          <button type="button" className="btn btn-quiet" style={{ padding: 0 }} onClick={() => setChoice(null)}>
            Changer de type de site
          </button>
          <h2 style={{ marginTop: 6 }}>{choice.label}</h2>
        </div>
        {state.error && <div className="notice notice-error" role="alert">{state.error}</div>}
        <div className={`field ${err.name ? "field-invalid" : ""}`}>
          <label htmlFor="name">Nom du site</label>
          <input id="name" name="name" type="text" placeholder="Pâtisserie Lune" required />
          <span className="help">Le nom de votre commerce, tel que vous voulez le voir ici.</span>
          {err.name && <span className="error-text">{err.name}</span>}
        </div>
        <div className={`field ${err.publicUrl ? "field-invalid" : ""}`}>
          <label htmlFor="publicUrl">Adresse de votre site</label>
          <input id="publicUrl" name="publicUrl" type="text" inputMode="url" placeholder="https://www.mon-site.fr" defaultValue={choice.id === "demo" ? "/demo-sites/patisserie-lune" : ""} required />
          {err.publicUrl && <span className="error-text">{err.publicUrl}</span>}
        </div>
        <ConnectorFields def={choice} errors={err} />
        {state.report && <ReportView report={state.report} />}
        {state.report && !state.report.ok && isAdmin && (
          <label className="checkbox">
            <input type="checkbox" name="force" />
            <span>Relier quand même (le contenu sera configuré plus tard)</span>
          </label>
        )}
        <div className="actions">
          <button className="btn" type="submit" name="intent" value="test" disabled={pending}>
            {pending ? "Vérification…" : "Tester la connexion"}
          </button>
          <button className="btn btn-primary" type="submit" name="intent" value="create" disabled={pending}>
            Relier mon site
          </button>
        </div>
        <p className="help">Vos accès sont chiffrés dès leur enregistrement. Ils ne sont plus jamais affichés, ni à vous ni à personne.</p>
      </form>
      <aside className="card">
        <h3>Où trouver ces informations</h3>
        <ol className="steps" style={{ marginTop: 12 }}>
          {choice.steps.map((s, i) => (
            <li key={i}>{s}</li>
          ))}
        </ol>
      </aside>
    </div>
  );
}
