"use client";

import { useActionState } from "react";
import { acceptInvitationAction } from "./actions";

export function AcceptButton({ token }: { token: string }) {
  const [state, action, pending] = useActionState(acceptInvitationAction, {});
  return (
    <form action={action} style={{ marginTop: 16 }}>
      <input type="hidden" name="token" value={token} />
      {state.error && <div className="notice notice-error">{state.error}</div>}
      <button className="btn btn-primary" type="submit" disabled={pending}>
        Accepter l&apos;invitation
      </button>
    </form>
  );
}
