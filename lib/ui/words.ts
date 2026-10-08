/** Petits accords du français pour les libellés générés à partir des rubriques. */

const FEMININE = new Set([
  "actualité", "création", "réalisation", "prestation", "page", "photo", "ligne", "collection", "catégorie", "recette",
  "formule", "boisson", "entrée", "salade", "pizza", "tarte", "boutique", "offre", "question", "image", "œuvre", "oeuvre",
  "chambre", "activité", "visite", "déclinaison", "carte", "annonce", "vidéo", "référence", "équipe", "personne", "news",
]);

const VOWEL = /^[aeiouyhâàéèêëîïôöûüœ]/i;

export function article(noun: string): string {
  const word = noun.trim().toLowerCase();
  const first = word.split(/\s+/)[0];
  return FEMININE.has(first) ? "une" : "un";
}

/** « Ajouter un gâteau », « Ajouter une actualité ». */
export function addLabel(itemLabel: string | undefined): string {
  if (!itemLabel) return "Ajouter";
  return `Ajouter ${article(itemLabel)} ${itemLabel}`;
}

/** « Liste de gâteaux », « Liste d'actualités ». */
export function listOf(label: string): string {
  const lower = label.toLowerCase();
  return VOWEL.test(lower) ? `Liste d'${lower}` : `Liste de ${lower}`;
}
