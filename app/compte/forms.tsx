"use client";

import { useActionState } from "react";
import { submitKeepingValues } from "@/components/no-reset";
import { changePasswordAction, saveNameAction, type AccountState } from "./actions";

function Msg({ s }: { s: AccountState }) {
  return (
    <>
      {s.error && <div className="notice notice-error">{s.error}</div>}
      {s.info && <div className="notice notice-ok">{s.info}</div>}
    </>
  );
}

export function NameForm({ name }: { name: string }) {
  const [state, action, pending] = useActionState<AccountState, FormData>(saveNameAction, {});
  return (
    <form onSubmit={submitKeepingValues(action)} className="form">
      <Msg s={state} />
      <div className="field">
        <label htmlFor="fullName">Prénom et nom</label>
        <input id="fullName" name="fullName" type="text" defaultValue={name} autoComplete="name" />
      </div>
      <div className="actions">
        <button className="btn" type="submit" disabled={pending}>
          Enregistrer
        </button>
      </div>
    </form>
  );
}

export function PasswordForm() {
  const [state, action, pending] = useActionState<AccountState, FormData>(changePasswordAction, {});
  return (
    <form action={action} className="form">
      <Msg s={state} />
      <div className="field">
        <label htmlFor="password">Nouveau mot de passe</label>
        <input id="password" name="password" type="password" autoComplete="new-password" />
        <span className="help">10 caractères minimum, avec au moins un chiffre.</span>
      </div>
      <div className="field">
        <label htmlFor="confirm">Encore une fois</label>
        <input id="confirm" name="confirm" type="password" autoComplete="new-password" />
      </div>
      <div className="actions">
        <button className="btn" type="submit" disabled={pending}>
          Changer le mot de passe
        </button>
      </div>
    </form>
  );
}
