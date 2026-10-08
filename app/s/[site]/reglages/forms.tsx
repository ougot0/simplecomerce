"use client";

import { useActionState, useState } from "react";
import type { ConnectorDefinition } from "@/lib/adapters/catalog";
import type { Section } from "@/lib/content/schema";
import { ConnectorFields, ReportView } from "@/components/connector-fields";
import { submitKeepingValues } from "@/components/no-reset";
import { updateConnectionAction, type ConnectState } from "@/app/sites/actions";
import {
  closureAction,
  deleteSiteAction,
  inviteAction,
  rediscoverAction,
  saveSchemaJsonAction,
  saveSectionsAction,
  type SettingsState,
} from "./actions";

function Messages({ state }: { state: SettingsState }) {
  return (
    <>
      {state.error && <div className="notice notice-error" role="alert">{state.error}</div>}
      {state.info && (
        <div className="notice notice-ok" role="status">
          <p>{state.info}</p>
          {state.link && (
            <p>
              <input type="text" readOnly value={state.link} onFocus={(e) => e.currentTarget.select()} aria-label="Lien d'invitation" className="code" />
            </p>
          )}
        </div>
      )}
    </>
  );
}

export function ConnectionForm({
  site,
  def,
  name,
  publicUrl,
  config,
  fingerprints,
}: {
  site: string;
  def: ConnectorDefinition;
  name: string;
  publicUrl: string;
  config: Record<string, unknown>;
  fingerprints: Record<string, string>;
}) {
  const [state, action, pending] = useActionState<ConnectState, FormData>(updateConnectionAction, {});
  const err = state.fieldErrors ?? {};
  // Les champs dérivés (owner/repo…) ne sont pas dans le formulaire : on réaffiche la saisie d'origine.
  return (
    <form onSubmit={submitKeepingValues(action)} className="form panel" noValidate>
      <input type="hidden" name="site" value={site} />
      {state.error && <div className="notice notice-error">{state.error}</div>}
      {state.saved && !state.report?.ok && <div className="notice notice-warn">Enregistré, mais la connexion ne fonctionne pas encore.</div>}
      {state.saved && state.report?.ok && <div className="notice notice-ok">Enregistré. La connexion fonctionne.</div>}
      <div className={`field ${err.name ? "field-invalid" : ""}`}>
        <label htmlFor="name">Nom du site</label>
        <input id="name" name="name" type="text" defaultValue={name} />
        {err.name && <span className="error-text">{err.name}</span>}
      </div>
      <div className={`field ${err.publicUrl ? "field-invalid" : ""}`}>
        <label htmlFor="publicUrl">Adresse du site</label>
        <input id="publicUrl" name="publicUrl" type="text" defaultValue={publicUrl} />
        {err.publicUrl && <span className="error-text">{err.publicUrl}</span>}
      </div>
      <ConnectorFields def={def} values={config} fingerprints={fingerprints} errors={err} />
      {state.report && !state.saved && <ReportView report={state.report} />}
      {state.report && state.saved && !state.report.ok && <ReportView report={state.report} />}
      <div className="actions">
        <button className="btn" type="submit" name="intent" value="test" disabled={pending}>
          {pending ? "Vérification…" : "Tester la connexion"}
        </button>
        <button className="btn btn-primary" type="submit" name="intent" value="save" disabled={pending}>
          Enregistrer
        </button>
      </div>
    </form>
  );
}

export function InviteForm({ site }: { site: string }) {
  const [state, action, pending] = useActionState<SettingsState, FormData>(inviteAction, {});
  return (
    <form onSubmit={submitKeepingValues(action)} className="form panel" noValidate>
      <input type="hidden" name="site" value={site} />
      <Messages state={state} />
      <div className="field">
        <label htmlFor="invite-email">Adresse e-mail de la personne</label>
        <input id="invite-email" name="email" type="email" autoComplete="off" />
      </div>
      <fieldset className="field">
        <legend>Ce qu&apos;elle pourra faire</legend>
        <label className="checkbox">
          <input type="radio" name="role" value="editor" defaultChecked />
          <span>
            Modifier le contenu
            <br />
            <span className="help">Produits, textes, photos, brouillons, historique.</span>
          </span>
        </label>
        <label className="checkbox">
          <input type="radio" name="role" value="owner" />
          <span>
            Tout gérer
            <br />
            <span className="help">En plus : les accès au site et l&apos;équipe.</span>
          </span>
        </label>
      </fieldset>
      <div className="actions">
        <button className="btn btn-primary" type="submit" disabled={pending}>
          Inviter
        </button>
      </div>
    </form>
  );
}

export function SmallAction({ action, fields, label, confirm }: { action: (p: SettingsState, f: FormData) => Promise<SettingsState>; fields: Record<string, string>; label: string; confirm?: string }) {
  const [state, dispatch, pending] = useActionState<SettingsState, FormData>(action, {});
  return (
    <form action={dispatch} onSubmit={(e) => confirm && !window.confirm(confirm) && e.preventDefault()} style={{ display: "inline-grid", justifyItems: "end", gap: 4 }}>
      {Object.entries(fields).map(([k, v]) => (
        <input key={k} type="hidden" name={k} value={v} />
      ))}
      <button type="submit" className="btn btn-small btn-quiet" disabled={pending}>
        {label}
      </button>
      {state.error && <span className="error-text">{state.error}</span>}
      {state.info && <span className="small muted">{state.info}</span>}
    </form>
  );
}

export function SectionsForm({ site, sections }: { site: string; sections: Section[] }) {
  const [state, action, pending] = useActionState<SettingsState, FormData>(saveSectionsAction, {});
  const [rstate, raction, rpending] = useActionState<SettingsState, FormData>(rediscoverAction, {});
  return (
    <div className="form" style={{ maxWidth: 760 }}>
      <form onSubmit={submitKeepingValues(action)} className="form" style={{ maxWidth: "none" }}>
        <input type="hidden" name="site" value={site} />
        <Messages state={state} />
        {sections.length === 0 ? (
          <p className="muted">Aucune rubrique.</p>
        ) : (
          <div className="ledger-wrap">
          <table className="ledger">
            <thead>
              <tr>
                <th>Nom affiché</th>
                <th className="shrink">Visible</th>
                <th className="shrink hide-small">Type</th>
              </tr>
            </thead>
            <tbody>
              {sections.map((s) => (
                <tr key={s.key}>
                  <td>
                    <input type="text" name={`label_${s.key}`} defaultValue={s.label} aria-label={`Nom de la rubrique ${s.label}`} />
                  </td>
                  <td className="shrink">
                    <label className="checkbox">
                      <input type="checkbox" name={`visible_${s.key}`} defaultChecked={!s.hidden} />
                      <span className="visually-hidden">Visible</span>
                    </label>
                  </td>
                  <td className="shrink hide-small muted small">{s.kind === "collection" ? "Liste" : "Bloc"}</td>
                </tr>
              ))}
            </tbody>
          </table>
</div>
        )}
        <div className="actions">
          <button className="btn btn-primary" type="submit" disabled={pending}>
            Enregistrer les rubriques
          </button>
        </div>
      </form>
      <form onSubmit={submitKeepingValues(raction)}>
        <input type="hidden" name="site" value={site} />
        <Messages state={rstate} />
        <button className="btn" type="submit" disabled={rpending}>
          {rpending ? "Lecture du site…" : "Relire le contenu du site"}
        </button>
        <p className="help" style={{ marginTop: 6 }}>À faire si votre créateur de site a ajouté de nouvelles rubriques. Vos noms et choix de visibilité sont conservés.</p>
      </form>
    </div>
  );
}

export function SchemaJsonForm({ site, schema }: { site: string; schema: string }) {
  const [state, action, pending] = useActionState<SettingsState, FormData>(saveSchemaJsonAction, {});
  const [value, setValue] = useState(schema);
  return (
    <form onSubmit={submitKeepingValues(action)} className="form" style={{ maxWidth: 900 }}>
      <input type="hidden" name="site" value={site} />
      <Messages state={state} />
      <textarea className="code" name="schema" value={value} onChange={(e) => setValue(e.target.value)} spellCheck={false} aria-label="Schéma de contenu" />
      <div className="actions">
        <button className="btn btn-primary" type="submit" disabled={pending}>
          Enregistrer le schéma
        </button>
        <span className="help">Chaque enregistrement crée une nouvelle version.</span>
      </div>
    </form>
  );
}

export function ClosureForm({ site, closed, message, reopenOn, delayText }: { site: string; closed: boolean; message: string; reopenOn: string | null; delayText: string }) {
  const [state, action, pending] = useActionState<SettingsState, FormData>(closureAction, {});
  return (
    <form onSubmit={submitKeepingValues(action)} className="form panel">
      <input type="hidden" name="site" value={site} />
      <Messages state={state} />
      {closed ? (
        <>
          <div className="notice notice-warn">
            <p>
              <strong>Votre site est fermé.</strong> Vos visiteurs voient : « {message} »{reopenOn && ` — réouverture le ${new Date(reopenOn).toLocaleDateString("fr-FR", { day: "numeric", month: "long" })}`}.
            </p>
          </div>
          <div className="actions">
            <button className="btn btn-primary" type="submit" name="intent" value="open" disabled={pending}>
              {pending ? "Réouverture…" : "Rouvrir le site"}
            </button>
          </div>
        </>
      ) : (
        <>
          <div className="field">
            <label htmlFor="closure-message">Message pour vos visiteurs</label>
            <textarea id="closure-message" name="message" maxLength={300} rows={3} defaultValue={message || "Nous sommes en congés. Merci de votre patience, à très bientôt !"} />
          </div>
          <div className="field">
            <label htmlFor="closure-date">
              Date de réouverture <span className="optional">(facultatif)</span>
            </label>
            <input id="closure-date" name="reopenOn" type="date" style={{ maxWidth: 220 }} defaultValue={reopenOn ?? ""} />
          </div>
          <div className="actions">
            <button className="btn btn-danger" type="submit" name="intent" value="close" disabled={pending}>
              {pending ? "Fermeture…" : "Fermer le site temporairement"}
            </button>
          </div>
          <p className="help">Vos produits et vos textes ne sont pas effacés. {delayText}</p>
        </>
      )}
    </form>
  );
}

export function DeleteSiteForm({ site, name }: { site: string; name: string }) {
  const [state, action, pending] = useActionState<SettingsState, FormData>(deleteSiteAction, {});
  return (
    <form action={action} className="form panel">
      <input type="hidden" name="site" value={site} />
      <Messages state={state} />
      <p>
        Retire ce site de Simple Commerce et efface ses accès enregistrés. <strong>Votre site lui-même n&apos;est pas touché</strong> : il reste en ligne, tel quel.
      </p>
      <div className="field">
        <label htmlFor="confirm-name">Pour confirmer, recopiez le nom du site : {name}</label>
        <input id="confirm-name" name="confirm" type="text" autoComplete="off" />
      </div>
      <div className="actions">
        <button className="btn btn-danger" type="submit" disabled={pending}>
          Retirer le site
        </button>
      </div>
    </form>
  );
}
