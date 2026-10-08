"use client";

import { useActionState, useState } from "react";
import { reorderAction, type EditorState } from "../../actions";

interface Row {
  id: string;
  title: string;
  thumb: string | null;
}

export function Reorder({ site, section, rows, cancelHref }: { site: string; section: string; rows: Row[]; cancelHref: string }) {
  const [order, setOrder] = useState(rows);
  const [state, action, pending] = useActionState<EditorState, FormData>(reorderAction, {});
  const move = (i: number, delta: number) => {
    const j = i + delta;
    if (j < 0 || j >= order.length) return;
    const next = [...order];
    [next[i], next[j]] = [next[j], next[i]];
    setOrder(next);
  };
  const changed = order.some((r, i) => r.id !== rows[i].id);
  return (
    <form action={action}>
      <input type="hidden" name="site" value={site} />
      <input type="hidden" name="section" value={section} />
      <input type="hidden" name="ids" value={JSON.stringify(order.map((r) => r.id))} />
      {state.error && <div className="notice notice-error" role="alert">{state.error}</div>}
      <p className="muted">L&apos;ordre ici est l&apos;ordre d&apos;affichage sur votre site.</p>
      <table className="ledger">
        <tbody>
          {order.map((r, i) => (
            <tr key={r.id}>
              <td className="shrink num faint">{i + 1}</td>
              <td className="shrink">{r.thumb ? <img className="thumb" src={r.thumb} alt="" /> : <span className="thumb thumb-empty" />}</td>
              <td className="title-cell">
                <strong>{r.title}</strong>
              </td>
              <td className="shrink">
                <div className="actions" style={{ gap: 4 }}>
                  <button type="button" className="btn btn-small" onClick={() => move(i, -1)} disabled={i === 0} aria-label={`Monter ${r.title}`}>
                    Monter
                  </button>
                  <button type="button" className="btn btn-small" onClick={() => move(i, 1)} disabled={i === order.length - 1} aria-label={`Descendre ${r.title}`}>
                    Descendre
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="savebar">
        <button className="btn btn-primary" type="submit" disabled={!changed || pending}>
          {pending ? "Enregistrement…" : "Enregistrer cet ordre"}
        </button>
        <a className="btn btn-quiet" href={cancelHref}>
          Annuler
        </a>
      </div>
    </form>
  );
}
