# Préparer un site pour Simple Commerce

Le principe : **tout ce que le client doit pouvoir modifier sort du code et va dans des fichiers de contenu.**
Le site lit ces fichiers ; le portail les modifie. Le site ne dépend jamais du portail : s'il s'arrête, le site continue.

## 1. Où mettre le contenu

Le portail cherche automatiquement, dans cet ordre : `content/`, `src/content/`, `data/`, `src/data/`, `_data/`, `src/_data/`, `contenu/`…
Pour un site chez un hébergeur sans étape de construction (PHP, HTML + JavaScript), un fichier `content.json` à la racine suffit.

Formats acceptés :

| Forme | Exemple | Devient |
|---|---|---|
| Fichier JSON/YAML contenant une liste d'objets | `content/produits.json` = `[ {...}, {...} ]` | une liste « Produits » |
| Fichier JSON/YAML contenant un objet | `content/contact.yml` | un bloc « Contact » |
| Objet contenant des listes | `content.json` = `{ "gateaux": [...], "infos": {...} }` | une rubrique par clé |
| Dossier de fichiers Markdown | `src/content/projets/*.md` (Astro, Hugo, Jekyll, Eleventy) | une liste, un fichier par élément |

## 2. Règles simples

- Donnez à chaque élément d'une liste un champ `id` (ou `slug`) stable : `{ "id": 3, "nom": "Paris-Brest", ... }`.
- L'ordre du tableau = l'ordre d'affichage. Pour un dossier Markdown, ajoutez un champ `ordre: 1` dans l'en-tête.
- Photos : chemin ou adresse complète (`"/images/tarte.webp"`). Les nouvelles photos arrivent dans `public/images/simplecommerce/`
  (ou `images/simplecommerce/` chez un hébergeur) et sont référencées par `/images/simplecommerce/nom.webp`.
- Prix : nombre (`4.5`), centimes (`450`) ou texte (`"4,50 €"`) — le portail réécrit dans le même format.
- Texte enrichi : le portail n'écrit que `<p> <strong> <em> <a> <ul> <li>`. Affichez-le avec un composant qui n'accepte que ces balises.
- Noms de clés en français ou en anglais : `nom`, `prix`, `photo`, `description`, `disponible` sont reconnus et bien libellés.

## 3. Exemple (Next.js)

```ts
// avant : const produits = [{ nom: "Tarte", prix: 4.5 }, ...] écrit dans la page
import produits from "@/content/produits.json";
```

Le site se reconstruit automatiquement à chaque modification (Vercel, Netlify, GitHub Pages ou action de déploiement vers OVH).

## 4. Choisir exactement les champs modifiables (facultatif)

Sans rien faire, le portail détecte les champs. Pour verrouiller la mise en page (longueurs maximales, format des photos,
champs en lecture seule), ajoutez `content/simplecommerce.json` : il a priorité sur la détection.

```json
{
  "version": 1,
  "sections": [
    {
      "key": "produits", "label": "Nos gâteaux", "kind": "collection", "itemLabel": "gâteau",
      "titleField": "nom", "imageField": "photo", "subtitleField": "prix", "idField": "id",
      "source": { "type": "file", "file": "produits.json" },
      "fields": [
        { "key": "nom", "label": "Nom", "type": "text", "required": true, "maxLength": 60 },
        { "key": "photo", "label": "Photo", "type": "image", "aspect": "4:3", "maxWidth": 1600 },
        { "key": "prix", "label": "Prix", "type": "price", "priceFormat": { "store": "number" } },
        { "key": "description", "label": "Description", "type": "richtext", "maxLength": 400 },
        { "key": "disponible", "label": "En vitrine", "type": "boolean" },
        { "key": "id", "label": "Référence", "type": "text", "hidden": true }
      ]
    }
  ]
}
```

Types de champs : `text`, `textarea`, `richtext`, `markdown`, `price`, `number`, `boolean`, `select`, `date`, `image`, `gallery`,
`url`, `email`, `phone`, `list`, `group`, `repeater` (lignes répétées : horaires, déclinaisons…).
L'administrateur peut aussi éditer ce schéma directement dans *Réglages → Schéma de contenu*.

## 5. Fermeture temporaire (congés, travaux)

Depuis *Réglages → Fermer le site temporairement*, le client écrit un message et une date de réouverture.
Le portail écrit alors `simplecommerce-statut.json` dans le dossier de contenu :

```json
{ "ferme": true, "message": "Nous sommes en congés.", "reouverture": "2026-11-02" }
```

Le site doit lire ce fichier et, si `ferme` vaut `true`, afficher le message à la place de son contenu.
Exemple Next.js (layout racine) :

```tsx
import statut from "@/content/simplecommerce-statut.json"; // créez-le avec { "ferme": false } au départ
if (statut.ferme) return <html lang="fr"><body><main><h1>Fermé temporairement</h1><p>{statut.message}</p></main></body></html>;
```

Site statique chez un hébergeur : un petit script en tête de page qui fait `fetch("/simplecommerce-statut.json")`
et remplace le contenu si `ferme` est vrai. Pour Shopify, WordPress et Webflow, le portail explique où activer
la fermeture dans leur propre administration (ces plateformes ne permettent pas de le faire par leur API).

## 6. Prompt type à donner à Claude Code dans un dépôt existant

> Sépare le contenu modifiable de ce site du code. Déplace dans `content/` (un fichier JSON par rubrique : produits, actualités,
> horaires, coordonnées, textes de la page d'accueil…) tous les textes, prix, listes et chemins de photos écrits en dur.
> Chaque élément de liste reçoit un champ `id` stable. Les composants importent ces fichiers au build. Le texte enrichi est
> limité à p/strong/em/a/ul/li. Ne change pas le rendu visuel. Puis crée `content/simplecommerce.json` décrivant les champs
> modifiables (voir docs/PREPARER-UN-SITE.md de Simple Commerce), avec des `maxLength` adaptés à la mise en page.
> Enfin, crée `content/simplecommerce-statut.json` avec `{ "ferme": false }` et fais en sorte que le site affiche
> le message de fermeture quand `ferme` vaut `true`.
