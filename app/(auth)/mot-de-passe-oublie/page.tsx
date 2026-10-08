"use client";

import Link from "next/link";
import { useActionState } from "react";
import { forgotAction, type AuthFormState } from "../actions";
import { AuthFrame } from "../auth-frame";

export default function ForgotPage() {
  const [state, action, pending] = useActionState<AuthFormState, FormData>(forgotAction, {});
  return (
    <AuthFrame>
      <h2>Mot de passe oublié</h2>
      <p className="muted">Indiquez votre adresse : vous recevrez un lien pour vous connecter et choisir un nouveau mot de passe.</p>
      {state.info ? (
        <div className="notice notice-ok" role="status">
          <p>{state.info}</p>
          {state.demoLink && (
            <p>
              Démonstration : <a href={state.demoLink}>ouvrir le lien</a>
            </p>
          )}
        </div>
      ) : (
        <form action={action} className="form" noValidate>
          <div className={`field ${state.fieldErrors?.email ? "field-invalid" : ""}`}>
            <label htmlFor="email">Adresse e-mail</label>
            <input id="email" name="email" type="email" autoComplete="email" required />
            {state.fieldErrors?.email && <span className="error-text">{state.fieldErrors.email}</span>}
          </div>
          <div className="actions">
            <button className="btn btn-primary" type="submit" disabled={pending}>
              Envoyer le lien
            </button>
          </div>
        </form>
      )}
      <p style={{ marginTop: 24 }}>
        <Link href="/connexion">Retour à la connexion</Link>
      </p>
    </AuthFrame>
  );
}
