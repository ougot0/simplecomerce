"use client";

import { useState } from "react";
import { usePathname } from "next/navigation";

/** Sur téléphone, le menu du site se replie derrière un bouton pour laisser la place au contenu. */
export function MobileMenu({ children }: { children: React.ReactNode }) {
  const path = usePathname();
  // Ouvert seulement pour la page où on l'a ouvert : changer de page le referme.
  const [openOn, setOpenOn] = useState<string | null>(null);
  const open = openOn === path;
  const setOpen = (fn: (o: boolean) => boolean) => setOpenOn(fn(open) ? path : null);
  return (
    <>
      <button type="button" className="btn btn-small menu-toggle" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        {open ? "Fermer le menu" : "Menu"}
      </button>
      <div className={`sidebar-body ${open ? "is-open" : ""}`}>{children}</div>
    </>
  );
}
