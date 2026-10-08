# Relier un site qui a sa propre base de données

Pour les sites avec un serveur (PHP, Laravel, Symfony, Node, Django…), le site expose quelques adresses,
toutes protégées par l'en-tête `Authorization: Bearer <clé secrète>` et appelées uniquement par le serveur du portail.

| Méthode | Adresse | Réponse |
|---|---|---|
| GET | `/ping` | `{ "ok": true, "name": "Mon site" }` |
| GET | `/schema` | le schéma de contenu (voir PREPARER-UN-SITE.md, sans `source`) |
| GET | `/sections/{clé}` | liste : `{ "entries": [{ "id": "...", "data": {...} }] }` · bloc : `{ "data": {...} }` |
| GET | `/sections/{clé}/entries/{id}` | `{ "id": "...", "data": {...} }` ou 404 |
| POST | `/sections/{clé}/entries` | corps `{ data, author }` → `{ id, data }` |
| PUT | `/sections/{clé}/entries/{id}` | corps `{ data, expected, author }` → `{ data }` ; **409** si l'élément ne correspond plus à `expected` |
| DELETE | `/sections/{clé}/entries/{id}` | corps `{ expected, author }` ; 409 en cas de conflit |
| PUT | `/sections/{clé}/order` | corps `{ ids: [...] }` |
| PUT | `/sections/{clé}` | bloc : corps `{ data, expected, author }` → `{ data }` |
| GET | `/status` | `{ "ferme": false, "message": "", "reouverture": null }` (404 = jamais fermé) |
| PUT | `/status` | corps `{ ferme, message, reouverture, author }` : fermeture temporaire du site |
| POST | `/media` | formulaire `file` (WebP déjà vérifié) + `alt` → `{ "url": "/uploads/x.webp" }` |

`data` ne contient que les champs du schéma ; le site garde ses autres colonnes intactes.
Un exemple complet en un seul fichier, qui stocke tout dans un `content.json`, est dans `examples/simplecommerce-endpoint.php`
(utile aussi chez un hébergeur où le SFTP n'est pas disponible).
