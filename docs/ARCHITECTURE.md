# Portail d'édition — Architecture et modèle de données

> Statut : **construit** (socle + tous les connecteurs). Nom : **Simple Commerce**.

## Décisions prises après validation

- **Enregistrer = en ligne**, avec en plus « Garder en brouillon » (table `drafts`) et une page Brouillons.
- **Les clients créent leur compte eux-mêmes** et relient leur site via un assistant ; ils en deviennent propriétaires.
  Ils peuvent inviter un collègue (rôle « modifier le contenu » ou « tout gérer »).
- **Photos** : envoyées directement là où vit le site (dépôt, Shopify, WordPress, serveur). Supabase Storage sert seulement
  de salle d'attente (bucket privé `staging`) entre l'envoi et l'enregistrement, ce qui permet un seul commit photo + texte.
- **Connecteurs** : Shopify, WordPress/WooCommerce, Webflow, GitHub, GitLab, Bitbucket, SFTP, FTP/FTPS, « API sur mesure ».
  Formats de contenu : JSON, YAML, Markdown (fichier unique ou dossier). Rubriques **détectées automatiquement**
  (ou décrites dans `simplecommerce.json`), puisque ce sont désormais les clients qui relient leurs sites.
- **Accès à la base** : le serveur utilise la clé service et vérifie les droits avant chaque opération (`lib/access.ts`) ;
  la RLS reste activée partout comme seconde barrière : un accès direct ne voit que ses sites, jamais les identifiants,
  et ne peut rien écrire (vérifié sur Postgres). Le navigateur n'a aucune clé Supabase.
- **Protection SSRF** : les adresses saisies par les clients (WordPress, SFTP, API) ne peuvent pas viser un réseau privé.
- **Mode démonstration** (`npm run demo`) : même code, stockage dans `.data/`, deux faux sites clients.

Le reste du document est la proposition initiale ; il reste juste sauf sur les points ci-dessus.

---

## 1. Vue d'ensemble

```
 Navigateur (client ou super-admin)
   │  formulaires, recadrage photo, aperçu — aucun secret, aucun appel externe
   ▼
 Next.js (App Router) sur Vercel — runtime Node
   ├─ Pages serveur + Server Actions        ← toute la logique métier
   ├─ Couche « accès » : qui suis-je, quel site, quel rôle (vérifié à CHAQUE action)
   ├─ Moteur de contenu : schéma du site → validation Zod → adaptateur
   ├─ Adaptateurs (server-only) ─┬─ GitHub   → API REST/Git Data GitHub
   │                             ├─ Shopify  → Admin API GraphQL
   │                             └─ SFTP     → ssh2 (OVH ou autre)
   └─ Coffre : chiffrement / déchiffrement des identifiants (AES-256-GCM)
   ▼
 Supabase
   ├─ Auth (lien magique + mot de passe, invitations)
   └─ Postgres + Row Level Security (isolation par site)
```

Principe directeur : **le navigateur ne parle qu'au portail, jamais aux services externes.** Les identifiants ne quittent la base que pour être déchiffrés en mémoire, côté serveur, le temps d'un appel.

## 2. Stack — validation de la proposition

Je garde **Next.js (App Router) + TypeScript + Supabase + Vercel**. C'est le bon choix ici :

| Besoin | Pourquoi ça colle |
|---|---|
| Isolation stricte entre clients | RLS Postgres : la règle est dans la base, pas seulement dans le code. Une URL manipulée tombe sur zéro ligne. |
| Invitations, lien magique, mots de passe | Fourni par Supabase Auth, pas de système maison à sécuriser. |
| Appels externes côté serveur | Server Actions / Route Handlers, modules marqués `server-only` (une importation côté client casse le build). |
| SFTP | Fonctionne sur le runtime Node de Vercel (`ssh2-sftp-client`). Pas sur le runtime Edge : on l'interdit pour ces routes. |
| Traitement d'images | `sharp`, supporté nativement sur Vercel. |

Compléments retenus :

- **Zod** : validation de tout ce qui entre (formulaires, schémas de contenu, réponses des API externes).
- **Chiffrement applicatif** (`node:crypto`, AES-256-GCM) plutôt que Supabase Vault. Raison : avec Vault, la clé et les données vivent au même endroit et toute requête SQL privilégiée peut lire en clair. Ici la clé maîtresse est une variable d'environnement Vercel, la base ne contient que du chiffré : une fuite de la base seule ne donne rien.
- **E-mails transactionnels** (invitations, liens magiques) via un SMTP personnalisé (Brevo ou Resend) branché sur Supabase, pour des e-mails en français à votre nom, pas « Supabase Auth ».
- Pas d'ORM lourd : client Supabase typé (types générés depuis le schéma SQL) + migrations SQL versionnées dans `supabase/migrations`.

Une limite à connaître : les fonctions Vercel refusent les corps de requête au-delà de **4,5 Mo**. On recadre et compresse donc les photos **dans le navigateur avant l'envoi** (une photo d'iPhone passe de 4–8 Mo à ~300 Ko), puis le serveur revérifie et réencode. Voir §7.

## 3. Rôles et accès

| Rôle | Portée | Peut |
|---|---|---|
| `super_admin` | tous les sites | tout : créer des sites, saisir des identifiants, définir les schémas, inviter, « se connecter en tant que », journal global |
| `owner` (client) | ses sites | modifier le contenu, annuler une modification, inviter un collègue (`editor`) sur son site |
| `editor` (client) | ses sites | modifier le contenu, annuler ses modifications |

Un utilisateur peut être membre de plusieurs sites (un architecte avec deux agences) ; l'interface n'affiche un sélecteur de site que dans ce cas.

### « Se connecter en tant que »

On **n'ouvre pas** une vraie session au nom du client (ça demanderait de fabriquer ses jetons et brouillerait l'historique). À la place :

1. Le super-admin choisit un client → une ligne `impersonations` est créée (durée 1 h).
2. Un cookie `httpOnly`, signé, porte l'identifiant de cette ligne.
3. Côté serveur, la couche d'accès calcule l'**utilisateur effectif** = le client, et ne montre que ses sites, exactement comme lui les voit.
4. Une bande permanente en haut de l'écran : « Vous agissez pour Marie Dupont — Pâtisserie Lune. Revenir à mon compte. »
5. Chaque modification est journalisée avec **les deux** : `actor_id` = vous, `on_behalf_of` = le client. Le client voit « Modifié par Alex (assistance) ».

## 4. Modèle de données

### Schéma

```
auth.users (Supabase)
   │ 1–1
profiles ───────────────┐
   │ n                  │
site_members  n ─── 1  sites ──1──1── site_credentials   (chiffré, invisible du client)
                         │
                         ├──1──n── content_schemas         (versions du schéma éditable)
                         ├──1──n── changes                 (historique + annulation)
                         ├──1──n── media                   (photos envoyées)
                         └──1──n── invitations
audit_events   (journal global : connexions, tests, impersonations, réglages)
impersonations
```

### Tables

**`profiles`** — prolonge `auth.users`.
| colonne | type | note |
|---|---|---|
| `id` | uuid PK → `auth.users.id` | |
| `full_name` | text | affiché dans l'historique |
| `email` | text | copie pour l'affichage admin |
| `is_super_admin` | boolean, défaut `false` | modifiable seulement en SQL / service role |
| `created_at` | timestamptz | |

**`sites`**
| colonne | type | note |
|---|---|---|
| `id` | uuid PK | |
| `slug` | text unique | `patisserie-lune`, utilisé dans les URL du portail |
| `name` | text | « Pâtisserie Lune » |
| `public_url` | text | lien « Voir mon site » |
| `connector` | enum `github` \| `shopify` \| `sftp` | extensible (`wordpress`, …) |
| `connector_config` | jsonb | **réglages non secrets uniquement**, validés par Zod selon le connecteur (voir ci-dessous) |
| `status` | enum `draft` \| `active` \| `suspended` | un site `draft` n'est pas visible du client |
| `last_check_at`, `last_check_ok` | timestamptz, boolean | résultat du dernier « Tester la connexion » |
| `created_at`, `updated_at` | timestamptz | |

`connector_config` par type :
- **github** : `{ owner, repo, branch, contentDir: "content", mediaDir: "public/images/portail", deployHint: "vercel" }`
- **shopify** : `{ shopDomain: "xxx.myshopify.com", apiVersion: "2026-07" }`
- **sftp** : `{ host, port, remoteRoot: "/www", contentPath: "content.json", mediaDir: "images/portail", mediaPublicBase: "https://site.fr/images/portail" }`

**`site_credentials`** — les secrets, isolés dans leur propre table.
| colonne | type | note |
|---|---|---|
| `site_id` | uuid PK → `sites` | |
| `ciphertext` | bytea | JSON chiffré : `{ token }`, `{ accessToken }`, `{ username, password \| privateKey, passphrase? }`, `{ ovhAppKey, ovhAppSecret, ovhConsumerKey }` |
| `iv`, `auth_tag` | bytea | AES-256-GCM |
| `key_version` | int | permet de faire tourner la clé maîtresse sans tout casser |
| `fingerprint` | text | ex. `ghp_…a3F9` — les 4 derniers caractères, pour que l'admin reconnaisse le jeton sans le voir |
| `updated_at`, `updated_by` | | |

RLS activée **sans aucune policy** + `revoke all` pour `anon` et `authenticated` : seule la clé service (côté serveur) peut lire. Le formulaire admin affiche « Jeton enregistré (…a3F9) — Remplacer », jamais la valeur.

**`site_members`**
| colonne | type |
|---|---|
| `site_id` → `sites`, `user_id` → `profiles` | PK composite |
| `role` | enum `owner` \| `editor` |
| `created_at` | |

**`invitations`**
| colonne | type | note |
|---|---|---|
| `id` | uuid | |
| `site_id`, `email`, `role` | | |
| `invited_by` | uuid | |
| `accepted_at`, `expires_at` | timestamptz | l'envoi passe par `auth.admin.inviteUserByEmail` ; cette table garde la trace et le rôle à attribuer à l'arrivée |

**`content_schemas`** — ce que le client a le droit de modifier (voir §6).
| colonne | type | note |
|---|---|---|
| `id` | uuid | |
| `site_id` | uuid | |
| `version` | int | incrémenté à chaque enregistrement |
| `definition` | jsonb | validée par Zod au moment de l'enregistrement |
| `is_active` | boolean | une seule version active par site (index unique partiel) |
| `created_by`, `created_at` | | |

Pour Shopify, le schéma « produits » est fourni par l'adaptateur (titre, description, prix, variantes, stock, photos, collections) ; l'admin choisit seulement les champs exposés.

**`changes`** — l'historique, et la base de l'annulation.
| colonne | type | note |
|---|---|---|
| `id` | uuid | |
| `site_id` | uuid | |
| `actor_id` | uuid | qui a cliqué |
| `on_behalf_of` | uuid null | client concerné si impersonation |
| `action` | enum `create` \| `update` \| `delete` \| `reorder` \| `revert` | |
| `section_key` | text | clé du schéma : `produits`, `horaires`, `accueil` |
| `entry_id` | text null | identifiant de l'élément dans sa collection |
| `entry_label` | text | « Tarte au citron » — affiché tel quel dans l'historique |
| `before`, `after` | jsonb | instantanés de l'élément (pas du fichier entier) |
| `remote_ref` | text | sha du commit GitHub, id Shopify, empreinte du fichier SFTP |
| `status` | enum `pending` \| `applied` \| `failed` | |
| `error_message` | text null | message en clair pour vous, version simplifiée pour le client |
| `reverts_change_id` | uuid null | si c'est une annulation |
| `created_at` | timestamptz | |

**Annuler** = réappliquer `before` via l'adaptateur, ce qui crée un nouveau `change` d'action `revert`. Même mécanisme pour les trois connecteurs, et l'historique reste linéaire et honnête. Si l'élément a été modifié entre-temps (ailleurs, ou par quelqu'un d'autre), le portail prévient au lieu d'écraser.

**`media`**
| colonne | type |
|---|---|
| `id`, `site_id`, `uploaded_by`, `created_at` | |
| `remote_path`, `public_url` | où l'image vit sur le site |
| `width`, `height`, `bytes`, `mime` | |
| `alt` | texte alternatif (demandé au client, en français simple : « Que montre la photo ? ») |

**`audit_events`** — journal global (vous).
`id, at, actor_id, on_behalf_of, site_id null, kind, details jsonb`. `kind` : `login`, `connection_test`, `credentials_updated`, `schema_updated`, `member_invited`, `impersonation_started`, `impersonation_ended`, … Les `details` ne contiennent **jamais** de secret (le logger refuse les clés nommées `token`, `password`, `secret`, `key`).

**`impersonations`** — `id, admin_id, target_user_id, started_at, expires_at, ended_at`.

### Row Level Security (esquisse)

```sql
create function is_super_admin() returns boolean
  language sql stable security definer set search_path = public as $$
  select coalesce((select is_super_admin from profiles where id = auth.uid()), false)
$$;

create function is_site_member(p_site uuid) returns boolean
  language sql stable security definer set search_path = public as $$
  select exists (select 1 from site_members
                 where site_id = p_site and user_id = auth.uid())
$$;

-- sites : lecture si membre (et site actif) ou super-admin ; écriture super-admin seulement
create policy sites_read on sites for select
  using (is_super_admin() or (is_site_member(id) and status = 'active'));

-- changes, media, content_schemas : lecture si membre ou super-admin
create policy changes_read on changes for select
  using (is_super_admin() or is_site_member(site_id));

-- site_credentials : RLS activée, AUCUNE policy → inaccessible hors clé service
alter table site_credentials enable row level security;
revoke all on site_credentials from anon, authenticated;
```

**Défense en profondeur** : la RLS protège la lecture directe ; mais les écritures vers les sites passent par le serveur (qui utilise la clé service pour lire les identifiants). Donc **chaque Server Action commence par** `requireSiteAccess(siteSlug, 'editor')`, qui résout l'utilisateur effectif et vérifie l'appartenance en base. Aucune action ne fait confiance à un `siteId` venu du navigateur sans cette vérification. Des tests automatisés tentent explicitement l'accès croisé (client A → site B) sur chaque action.

## 5. Adaptateurs

Interface commune (TypeScript, `server-only`) :

```ts
interface SiteAdapter {
  readonly capabilities: {
    collections: boolean      // listes : produits, actualités…
    singletons: boolean       // blocs uniques : horaires, coordonnées, textes de page
    reorder: boolean
    variants: boolean         // Shopify
    inventory: boolean        // Shopify
  }

  testConnection(): Promise<ConnectionReport>   // { ok, checks: [{ label, ok, hint }] }

  listEntries(section: string): Promise<Entry[]>
  getEntry(section: string, id: string): Promise<Entry | null>
  createEntry(section: string, data: EntryData, ctx: ChangeContext): Promise<WriteResult>
  updateEntry(section: string, id: string, data: EntryData, ctx: ChangeContext): Promise<WriteResult>
  deleteEntry(section: string, id: string, ctx: ChangeContext): Promise<WriteResult>
  reorder(section: string, orderedIds: string[], ctx: ChangeContext): Promise<WriteResult>

  getSingleton(section: string): Promise<EntryData>
  updateSingleton(section: string, data: EntryData, ctx: ChangeContext): Promise<WriteResult>

  uploadImage(file: ProcessedImage, ctx: ChangeContext): Promise<{ publicUrl: string; remotePath: string }>
}
```

- `ChangeContext` porte l'auteur (pour le message de commit, par ex.) et la **version attendue** (sha / `updatedAt`) pour détecter les conflits.
- Un **registre** `adapters/index.ts` associe `connector` → fabrique. Ajouter WordPress plus tard = un fichier qui implémente l'interface + un schéma Zod de `connector_config` + un formulaire admin. Rien d'autre à toucher.
- Les adaptateurs ne voient jamais la base : on leur passe la config et les identifiants déchiffrés, ils renvoient des données.

### GitHub (sites sur mesure — priorité)

- **Authentification** : jeton *fine-grained* limité au seul dépôt, permission *Contents: read & write* (et *Metadata: read*). Plus tard, une **GitHub App** pourrait remplacer les jetons (installation en un clic, jetons courts, pas d'expiration à surveiller) — je la propose en évolution, pas en v1.
- **Lecture** : `GET /repos/{owner}/{repo}/contents/{path}` → contenu + `sha`.
- **Écriture** : via l'API Git Data (blob → tree → commit → mise à jour de la branche) pour qu'une modification qui touche **une photo et un fichier de contenu** fasse **un seul commit**, donc un seul redéploiement.
- **Message de commit** : `Portail : « Tarte au citron » modifiée par Marie Dupont` + trailer `Portail-Change: <id>`. L'auteur Git est celui du portail, le nom du client est dans le message.
- **Conflits** : si la branche a bougé depuis la lecture (vous avez poussé du code entre-temps), on relit, on réapplique la modification sur l'élément concerné uniquement et on recommit. Si le même élément a changé, on prévient.
- **Déploiement** : rien à faire, Vercel / Netlify / GitHub Pages / action OVH se déclenchent sur le push. Le portail peut afficher l'état (« Mise en ligne en cours… » → « En ligne ») en lisant les *commit statuses / deployments* GitHub quand ils existent ; sinon il affiche « Visible d'ici une à deux minutes ».
- **Test de connexion** : jeton valide → dépôt accessible → branche existe → droits d'écriture → `content/` présent et fichiers conformes au schéma.

### Shopify

- **Authentification** : application personnalisée créée dans l'admin de la boutique, jeton Admin API (`shpat_…`). Domaine validé strictement (`^[a-z0-9-]+\.myshopify\.com$`) pour qu'on ne puisse pas faire appeler une autre adresse par le serveur.
- **API** : GraphQL Admin, version figée dans `connector_config.apiVersion`. Produits, variantes, prix, stocks (`inventorySetQuantities`, nécessite l'emplacement), images (*staged uploads* puis rattachement au produit), collections.
- **Réordonnancement** : l'ordre se gère dans une collection manuelle (`collectionReorderProducts`) ; on le propose par collection.
- **Limites de débit** : respect du coût GraphQL renvoyé par Shopify, avec reprise automatique.
- **Annuler** : `before` contient l'état Shopify utile (titre, description, prix par variante, stock, ids d'images).
- **Test de connexion** : `shop { name }` + vérification des scopes accordés (`read_products`, `write_products`, `read_inventory`, `write_inventory`, `read_locations`, `write_files`).

### SFTP (OVH ou autre)

- **Protocole** : SFTP (port 22) recommandé ; FTP simple refusé (identifiants en clair sur le réseau). FTPS envisageable si un hébergeur n'a que ça.
- **Fonctionnement** : télécharger `content.json` → modifier l'élément → écrire dans `content.json.tmp` → renommer (écriture atomique : le site ne lit jamais un fichier à moitié écrit). Une copie `content.backup-<date>.json` est gardée côté serveur (les 10 dernières).
- **Conflits** : empreinte SHA-256 du fichier lu, comparée juste avant l'écriture.
- **Images** : envoyées dans `mediaDir`, nom unique (`tarte-citron-8f3a.webp`).
- **Chemins** : tout chemin est résolu et doit rester sous `remoteRoot` (pas de `../`).
- **Clés API OVH** (optionnelles) : lecture seule des infos d'hébergement (offre, expiration du domaine) affichées à l'admin.
- **Test de connexion** : hôte joignable → authentification → droits lecture/écriture sur le dossier → `content.json` lisible et conforme au schéma.
- À vérifier au cas par cas : certains hébergements filtrent les connexions SFTP par IP ; Vercel n'a pas d'IP fixe. Si c'est bloquant pour un site, on passera ses appels par une petite fonction à IP fixe (option payante Vercel ou petit relais), pas avant.

## 6. Schéma de contenu (sites GitHub et SFTP)

Le schéma décrit **ce que le client voit et peut modifier**, rien de plus. Il est enregistré dans le portail (versionné), édité par vous ; on pourra l'importer depuis un fichier `content/_portail.json` du dépôt pour l'écrire au même moment que le site.

```jsonc
{
  "sections": [
    {
      "key": "produits",
      "label": "Nos gâteaux",
      "kind": "collection",                 // une liste d'éléments
      "file": "produits.json",              // relatif à contentDir
      "itemLabel": "gâteau",                // « Ajouter un gâteau »
      "titleField": "nom",
      "orderable": true,
      "fields": [
        { "key": "nom",   "label": "Nom",   "type": "text",     "required": true, "maxLength": 60 },
        { "key": "prix",  "label": "Prix",  "type": "price",    "currency": "EUR" },
        { "key": "photo", "label": "Photo", "type": "image",    "aspect": "4:3", "maxWidth": 1600 },
        { "key": "description", "label": "Description", "type": "richtext", "marks": ["bold", "italic", "link"], "maxLength": 400 },
        { "key": "disponible",  "label": "En vitrine",  "type": "boolean" }
      ]
    },
    {
      "key": "horaires",
      "label": "Horaires d'ouverture",
      "kind": "singleton",                  // un bloc unique
      "file": "horaires.json",
      "fields": [{ "key": "semaine", "label": "Horaires", "type": "opening-hours" },
                 { "key": "note", "label": "Message (fermeture exceptionnelle…)", "type": "text", "maxLength": 140 }]
    }
  ]
}
```

Types de champs v1 : `text`, `textarea`, `richtext` (gras / italique / lien seulement — pas de titres ni de couleurs, pour ne pas casser la mise en page), `price`, `number`, `boolean`, `select`, `date`, `image`, `gallery`, `url`, `email`, `phone`, `address`, `opening-hours`.

Les contraintes (`maxLength`, `aspect`, `required`) servent à la fois à l'interface et à la validation serveur : un client ne peut pas enregistrer un titre de 300 caractères qui déborderait de sa carte produit.

## 7. Photos

1. **Navigateur** : choix du fichier (sur iPhone, iOS convertit déjà le HEIC en JPEG) → recadrage au format imposé par le schéma (`react-easy-crop`) → redimensionnement (largeur max du schéma) et compression via `canvas` → fichier WebP/JPEG d'environ 150–400 Ko.
2. **Serveur** : refuse au-delà de 4 Mo ; vérifie le **vrai** type par les octets d'en-tête (pas l'extension ni le type annoncé) — JPEG, PNG, WebP seulement ; refuse SVG (peut contenir du script) ; décode avec `sharp` (limite de pixels contre les images piégées), retire les métadonnées EXIF (localisation GPS des photos de téléphone !), réencode en WebP.
3. **Adaptateur** : envoie l'image au bon endroit (dépôt, Shopify, dossier SFTP), renvoie l'URL publique.

## 8. Sécurité — récapitulatif

- Identifiants chiffrés AES-256-GCM, clé maîtresse en variable d'environnement Vercel (`CREDENTIALS_KEY_V1`), jamais en base ni dans le dépôt ; rotation possible via `key_version`.
- Table des identifiants inaccessible hors clé service ; modules adaptateurs et coffre marqués `server-only` ; la clé service Supabase n'existe que côté serveur.
- Logger avec masquage automatique (`token`, `password`, `secret`, `authorization`, `privateKey`) ; les erreurs des API externes sont nettoyées avant d'être journalisées ou affichées.
- Isolation : RLS + vérification d'accès explicite dans chaque action + tests d'accès croisé.
- Entrées validées par Zod ; contenu riche nettoyé (liste blanche de balises) avant écriture sur le site.
- Pas de requête vers une adresse choisie par un client (anti-SSRF) : seuls vous saisissez hôtes et domaines, et ils sont validés.
- En-têtes : CSP stricte, `frame-ancestors 'none'`, cookies `httpOnly` / `Secure` / `SameSite=Lax`.
- Limitation des tentatives de connexion (Supabase) et des actions d'écriture par utilisateur.
- Impersonation limitée dans le temps, visible, journalisée.

## 9. Organisation du code

```
app/
  (auth)/connexion, invitation, mot-de-passe
  (client)/[site]/                    accueil du site
  (client)/[site]/produits/…          collection (générique, pilotée par le schéma)
  (client)/[site]/pages/[section]     blocs uniques
  (client)/[site]/historique
  (admin)/admin/sites, sites/nouveau, sites/[id]/{connexion,schema,membres}
  (admin)/admin/journal
lib/
  access/        utilisateur effectif, requireSiteAccess, impersonation
  vault/         chiffrement des identifiants (server-only)
  adapters/      types.ts, index.ts (registre), github/, shopify/, sftp/
  content/       schéma (Zod), validation, moteur de modifications + annulation
  images/        vérification et réencodage
  supabase/      clients (navigateur / serveur / service), types générés
components/      formulaires de champs (un composant par type), UI
supabase/migrations/
docs/
```

Les écrans « Produits » et « Pages » sont **génériques** : ils se construisent à partir du schéma. Un nouveau site ne demande aucun nouvel écran, seulement un schéma.

## 10. Adapter vos sites existants (aperçu — guide complet à l'étape GitHub)

Le contrat entre un site et le portail est volontairement simple :

1. Tout ce qui est modifiable sort du code et va dans `content/*.json` (un fichier par section : `produits.json`, `horaires.json`, `accueil.json`…).
2. Le site **importe** ces fichiers au build (`import produits from "@/content/produits.json"`) — aucun appel au portail, le site reste autonome si le portail est arrêté.
3. Les collections sont des tableaux d'objets avec un champ `id` stable ; l'ordre du tableau = l'ordre d'affichage.
4. Les images envoyées par le portail vont dans `public/images/portail/` ; les composants doivent accepter une URL d'image quelconque.
5. Le texte riche arrive en HTML limité (gras, italique, liens) et s'affiche via un composant qui ne laisse passer que ces balises.

Pour les sites faits avec Claude Code, je fournirai un **prompt de migration type** à lancer dans chaque dépôt : il repère les textes et listes en dur, les déplace dans `content/`, et génère le schéma correspondant à importer dans le portail.

## 11. Direction artistique du portail

Idée unique, tenue partout : **le registre d'atelier.** Le portail ressemble à un cahier de commandes bien tenu, pas à un tableau de bord SaaS.

- **Composition** : les listes sont des lignes réglées (filets fins, colonnes alignées comme un registre), pas des cartes. Une colonne de navigation étroite à gauche avec le nom du site écrit en grand, le contenu en pleine largeur à droite. Beaucoup de lignes, peu de boîtes.
- **Typographie** : *Atkinson Hyperlegible Next* pour toute l'interface — conçue pour la lisibilité, pour des gens qui n'ont ni le temps ni l'envie de déchiffrer, et peu vue dans les interfaces générées. Chiffres tabulaires pour les prix et les heures. Les titres de section en plus grand corps, même police, graisse forte : la hiérarchie vient de la taille et de l'espace, pas de décorations.
- **Couleurs** : fond papier écru légèrement chaud (pas de blanc pur — on travaille « sur papier »), encre brun très foncé pour le texte, **un seul** accent : rouge tampon (vermillon terne) réservé aux actions principales et à ce qui demande attention. Un vert olive discret pour l'état « en ligne ». Pas de dégradés.
- **Formes** : angles quasi droits (2 px), bordures de 1 px plutôt que des ombres. Les étiquettes d'état ressemblent à des étiquettes de prix : texte encadré, pas de pastilles colorées.
- **Mouvement** : quasi absent. Une confirmation d'enregistrement qui apparaît sans rebond (« Enregistré — visible sur votre site d'ici une minute »). Rien qui bouge au survol, à part le curseur.
- **Vocabulaire** : « Enregistrer », « Mettre en ligne », « Annuler cette modification », « Photo », « Vos gâteaux » (le libellé vient du schéma, donc des mots du métier du client). Jamais : JSON, commit, API, déploiement, synchronisation.
- Mode sombre : non prioritaire (outil de jour, en boutique) ; les couleurs seront définies en variables pour l'ajouter sans refonte.

## 12. Plan de construction

1. **Socle** — projet Next.js, Supabase (migrations, RLS, fonctions d'accès), connexion / lien magique / invitation, rôles, multi-sites, admin : créer un site et un client, impersonation, journal, coffre chiffré. Tests d'isolation.
2. **Adaptateur GitHub** — schéma de contenu + éditeur admin, écrans génériques collection / bloc, photos, historique + annulation, test de connexion, guide de migration de vos sites + prompt type. Mise en production avec un premier site réel.
3. **Adaptateur Shopify** — produits, variantes, prix, stocks, photos, collections.
4. **Adaptateur SFTP / OVH** — lecture/écriture atomique, images, clés OVH optionnelles.
5. Plus tard : GitHub App, WordPress, brouillons/aperçu sur le vrai site.

## 13. Questions à trancher avant de coder

1. **Enregistrer = en ligne ?** Je propose que chaque « Enregistrer » publie directement (le plus simple pour vos clients, un clic = c'est fait), avec l'aperçu dans le formulaire. L'alternative est un mode brouillon + bouton « Mettre en ligne » qui regroupe plusieurs modifications (moins de redéploiements, vrai aperçu possible, mais risque d'oubli). Votre préférence ?
2. **Images des sites GitHub** dans le dépôt (simple, le site reste autonome — mon choix par défaut) ou dans un stockage externe (Supabase Storage / Cloudinary, dépôt plus léger) ?
3. **Connexion client** : lien magique seul, mot de passe seul, ou les deux au choix du client (mon choix par défaut : les deux) ?
4. **Le client `owner` peut-il inviter un collègue** lui-même, ou est-ce réservé à vous ?
5. **Projet Supabase et domaine** : avez-vous déjà un compte Supabase / Vercel et un domaine pour le portail (ex. `atelier.votre-domaine.fr`) ? Et un service d'envoi d'e-mails ?
6. **Nom** : « Atelier » vous convient-il comme nom de travail ?
