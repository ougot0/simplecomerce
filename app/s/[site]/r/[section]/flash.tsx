const MESSAGES: Record<string, string> = {
  brouillon: "Brouillon enregistré. Il n'est pas visible sur votre site tant que vous ne l'avez pas mis en ligne.",
  supprime: "Supprimé du site.",
  ordre: "Nouvel ordre enregistré.",
  annule: "Modification annulée.",
  jete: "Brouillon supprimé.",
};

export function Flash({ ok, delayText }: { ok?: string; delayText: string }) {
  if (!ok) return null;
  const text = ok === "publie" ? `Enregistré et mis en ligne. ${delayText}` : ok === "ordre" ? `${MESSAGES.ordre} ${delayText}` : MESSAGES[ok];
  if (!text) return null;
  return (
    <div className={`notice ${ok === "brouillon" ? "notice-warn" : "notice-ok"}`} role="status">
      <p>{text}</p>
    </div>
  );
}
