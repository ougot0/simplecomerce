"use client";

import { submitKeepingValues } from "@/components/no-reset";
import Link from "next/link";
import { useActionState } from "react";
import { loginAction, type AuthFormState } from "../actions";

export function LoginForm({ next, demo }: { next: string; demo: boolean }) {
  const [state, action, pending] = useActionState<AuthFormState, FormData>(loginAction, {});
  return (
    <form onSubmit={submitKeepingValues(action)} className="form" noValidate>
      <input type="hidden" name="next" value={next} />
      {state.error && <div className="notice notice-error" role="alert">{state.error}</div>}
      {state.info && (
        <div className="notice notice-ok" role="status">
          <p>{state.info}</p>
          {state.demoLink && (
            <p>
              Démonstration : aucun e-mail n&apos;est envoyé. <a href={state.demoLink}>Ouvrir le lien de connexion</a>
            </p>
          )}
        </div>
      )}
      <div className={`field ${state.fieldErrors?.email ? "field-invalid" : ""}`}>
        <label htmlFor="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autoComplete="email" required defaultValue={state.values?.email} aria-invalid={!!state.fieldErrors?.email} />
        {state.fieldErrors?.email && <span className="error-text">{state.fieldErrors.email}</span>}
      </div>
      <div className={`field ${state.fieldErrors?.password ? "field-invalid" : ""}`}>
        <label htmlFor="password">Mot de passe</label>
        <input id="password" name="password" type="password" autoComplete="current-password" />
        {state.fieldErrors?.password && <span className="error-text">{state.fieldErrors.password}</span>}
        <Link href="/mot-de-passe-oublie" className="small">
          Mot de passe oublié ?
        </Link>
      </div>
      <label className="checkbox">
        <input type="checkbox" name="remember" defaultChecked />
        <span>
          Rester connecté
          <br />
          <span className="help">Décochez sur un ordinateur partagé.</span>
        </span>
      </label>
      <div className="actions">
        <button className="btn btn-primary" type="submit" name="intent" value="password" disabled={pending}>
          {pending ? "Connexion…" : "Se connecter"}
        </button>
      </div>
      <div className="divider">ou</div>
      <div>
        <button className="btn" type="submit" name="intent" value="magic" disabled={pending}>
          Recevoir un lien de connexion par e-mail
        </button>
        <p className="help" style={{ marginTop: 6 }}>Pas besoin de mot de passe : un clic dans l&apos;e-mail suffit.</p>
      </div>
      <p>
        Pas encore de compte ? <Link href={`/inscription${next !== "/sites" ? `?next=${encodeURIComponent(next)}` : ""}`}>Créer un compte</Link>
      </p>
      {demo && (
        <div className="notice small">
          <p>
            <strong>Comptes de démonstration</strong>
          </p>
          <p>
            Cliente : marie@patisserie-lune.fr / tarte-citron-2026
            <br />
            Client : paul@atelier-brun.fr / maison-bois-2026
            <br />
            Administrateur : alex@simplecommerce.demo / atelier-demo-2026
          </p>
        </div>
      )}
    </form>
  );
}
