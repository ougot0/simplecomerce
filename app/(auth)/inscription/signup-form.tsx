"use client";

import { submitKeepingValues } from "@/components/no-reset";
import Link from "next/link";
import { useActionState } from "react";
import { signupAction, type AuthFormState } from "../actions";

export function SignupForm({ next, email }: { next: string; email?: string }) {
  const [state, action, pending] = useActionState<AuthFormState, FormData>(signupAction, {});
  if (state.info) {
    return (
      <div className="notice notice-ok" role="status">
        <p>{state.info}</p>
      </div>
    );
  }
  const err = state.fieldErrors ?? {};
  return (
    <form onSubmit={submitKeepingValues(action)} className="form" noValidate>
      <input type="hidden" name="next" value={next} />
      {state.error && <div className="notice notice-error" role="alert">{state.error}</div>}
      <div className={`field ${err.fullName ? "field-invalid" : ""}`}>
        <label htmlFor="fullName">Prénom et nom</label>
        <input id="fullName" name="fullName" type="text" autoComplete="name" required defaultValue={state.values?.fullName} />
        <span className="help">Affiché dans l&apos;historique des modifications.</span>
        {err.fullName && <span className="error-text">{err.fullName}</span>}
      </div>
      <div className={`field ${err.email ? "field-invalid" : ""}`}>
        <label htmlFor="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autoComplete="email" required defaultValue={state.values?.email ?? email} />
        {err.email && <span className="error-text">{err.email}</span>}
      </div>
      <div className={`field ${err.password ? "field-invalid" : ""}`}>
        <label htmlFor="password">Mot de passe</label>
        <input id="password" name="password" type="password" autoComplete="new-password" required minLength={10} />
        <span className="help">10 caractères minimum, avec au moins un chiffre.</span>
        {err.password && <span className="error-text">{err.password}</span>}
      </div>
      <label className="checkbox">
        <input type="checkbox" name="remember" defaultChecked />
        <span>Rester connecté</span>
      </label>
      <div className="actions">
        <button className="btn btn-primary" type="submit" disabled={pending}>
          {pending ? "Création…" : "Créer mon compte"}
        </button>
      </div>
      <p>
        Déjà un compte ? <Link href={`/connexion${next !== "/sites" ? `?next=${encodeURIComponent(next)}` : ""}`}>Se connecter</Link>
      </p>
    </form>
  );
}
