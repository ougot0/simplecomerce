"use client";

import { useActionState } from "react";
import type { EditorState } from "@/app/s/[site]/actions";

/** Petit formulaire d'un bouton, avec message d'erreur sur place. */
export function ActionButton({
  action,
  fields,
  label,
  pendingLabel,
  className = "btn btn-small",
  confirm,
}: {
  action: (prev: EditorState, form: FormData) => Promise<EditorState>;
  fields: Record<string, string>;
  label: string;
  pendingLabel?: string;
  className?: string;
  confirm?: string;
}) {
  const [state, dispatch, pending] = useActionState<EditorState, FormData>(action, {});
  return (
    <form
      action={dispatch}
      onSubmit={(e) => {
        if (confirm && !window.confirm(confirm)) e.preventDefault();
      }}
      style={{ display: "inline-grid", gap: 4, justifyItems: "end" }}
    >
      {Object.entries(fields).map(([k, v]) => (
        <input key={k} type="hidden" name={k} value={v} />
      ))}
      <button className={className} type="submit" disabled={pending}>
        {pending ? pendingLabel ?? "…" : label}
      </button>
      {state.error && <span className="error-text" style={{ maxWidth: 320, textAlign: "right" }}>{state.error}</span>}
    </form>
  );
}
