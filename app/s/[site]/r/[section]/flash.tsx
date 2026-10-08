const MESSAGES: Record<string, string> = {
  brouillon: "Enregistré en brouillon. Ce n'est pas encore visible sur votre site : publiez-le depuis « Brouillons » quand vous êtes prêt.",
  supprime: "Supprimé du site.",
  ordre: "Nouvel ordre enregistré.",
  annule: "Modification annulée.",
  jete: "Brouillon supprimé.",
};

export function Flash({ ok, delayText }: { ok?: string; delayText: string }) {
  if (!ok) return null;
  const text = ok === "publie" ? `Publié sur votre site. ${delayText}` : ok === "ordre" ? `${MESSAGES.ordre} ${delayText}` : MESSAGES[ok];
  if (!text) return null;
  return (
    <div className={`notice ${ok === "brouillon" ? "notice-warn" : "notice-ok"}`} role="status">
      <p>{text}</p>
    </div>
  );
}
