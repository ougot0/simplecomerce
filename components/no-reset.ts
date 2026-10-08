"use client";

import { startTransition, type FormEvent } from "react";

/**
 * Envoie un formulaire à une action sans le vider ensuite (React vide les formulaires
 * après une action, ce qui ferait perdre la saisie après un simple « Tester la connexion »).
 */
export function submitKeepingValues(dispatch: (data: FormData) => void) {
  return (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const submitter = (event.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
    const data = new FormData(event.currentTarget, submitter);
    startTransition(() => dispatch(data));
  };
}
