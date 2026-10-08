# Simple Commerce

Portail où vos clients mettent à jour leur site eux-mêmes : produits, prix, photos, textes, horaires, actualités.
Un seul portail, plusieurs sites ; chaque client ne voit que les siens.

Deux versions, mêmes fonctions :

- **`portail/` — version PHP + MySQL, pour un hébergement web mutualisé (OVHcloud et autres).** C'est celle à mettre en ligne
  sur votre hébergement OVH : guide pas à pas dans [`portail/README.md`](portail/README.md).
- La racine du dépôt — version Next.js + Supabase, pour Vercel (décrite ci-dessous).

## Essayer tout de suite (démonstration, sans aucun compte)

```bash
npm install
npm run demo        # http://localhost:3000
```

Comptes de démonstration : `marie@patisserie-lune.fr` / `tarte-citron-2026` (cliente),
`paul@atelier-brun.fr` / `maison-bois-2026` (client), `alex@simplecommerce.demo` / `atelier-demo-2026` (administrateur).
Les deux faux sites clients sont visibles sur `/demo-sites/patisserie-lune` et `/demo-sites/atelier-brun` : chaque modification y apparaît.
Pour repartir de zéro : supprimer le dossier `.data/` (et remettre `demo/` avec `git checkout demo && git clean -fd demo`).

## Mise en production (Supabase + Vercel)

1. **Supabase** : créer un nouveau projet. Dans *SQL Editor*, exécuter `supabase/migrations/20261008000000_init.sql`.
2. *Authentication → URL Configuration* : Site URL = l'adresse du portail ; ajouter `https://<portail>/auth/callback` aux Redirect URLs.
3. *Authentication → Emails* : brancher un SMTP à votre nom (Brevo, Resend…) et traduire les modèles d'e-mails en français.
4. **Vercel** : importer le dépôt, renseigner les variables de `.env.example`, déployer.
5. Créer votre compte sur le portail, puis vous donner le rôle administrateur dans Supabase (SQL Editor) :
   `update profiles set is_super_admin = true where email = 'vous@domaine.fr';`

## Ce que fait le portail

- Inscription, connexion (mot de passe ou lien par e-mail), « Rester connecté ».
- Un client relie son site une fois (assistant guidé) ; il le retrouve à chaque connexion.
- Types de sites : Shopify, WordPress / WooCommerce, Webflow, dépôt GitHub / GitLab / Bitbucket (Next.js, Astro, Hugo, Jekyll, Eleventy, HTML…),
  hébergeur en SFTP ou FTP (OVH, o2switch, Hostinger…), site avec sa propre base de données (« API sur mesure »).
  Contenu en JSON, YAML ou Markdown, dans un fichier unique ou un dossier de fichiers.
- Rubriques détectées automatiquement, renommables et masquables.
- Enregistrer = en ligne ; ou « Garder en brouillon » puis mettre en ligne plus tard.
- Photos recadrées et allégées dans le navigateur, revérifiées sur le serveur (métadonnées GPS retirées).
- Historique avec annulation, équipe (inviter un collègue), administration, mode « agir pour ce client », journal.

## Documentation

- `docs/ARCHITECTURE.md` — architecture, modèle de données, sécurité.
- `docs/PREPARER-UN-SITE.md` — séparer le contenu du code dans vos sites existants.
- `docs/API-SUR-MESURE.md` — relier un site qui a sa propre base de données (exemple PHP dans `examples/`).

## Développement

```bash
npm test          # tests (chiffrement, détection, moteur de fichiers, validation, photos…)
npm run lint
npm run typecheck
```
