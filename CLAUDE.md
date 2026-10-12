# Simple Commerce — mémoire du projet

> Ce fichier est la seule trace des décisions prises avec le propriétaire du projet. Lis-le en entier avant de toucher au code.
> Mets-le à jour à la fin de chaque session (section « Ce qui reste à faire » et « Journal »).

## 1. Le produit et la personne

**Simple Commerce** est un portail web où des commerçants et artisans **non techniques** mettent à jour eux-mêmes
le contenu de **leur propre site** (produits, prix, stocks, photos, textes, horaires, actualités, ouverture/fermeture),
sans toucher au code. Un seul portail, plusieurs sites ; chaque client ne voit que les siens. Un super-administrateur
voit tout, configure les sites, peut « agir pour » un client et consulte un journal d'activité.

- **Propriétaire du projet** : parle français, n'est pas développeur, s'exprime souvent à l'oral (messages dictés,
  parfois approximatifs : reformuler sa demande avant d'agir si elle est ambiguë). Il veut **pouvoir cliquer lui-même**
  pour tester (pas seulement des captures), et des explications **pas à pas, sans jargon**.
- **Il a un hébergement web OVH mutualisé** (offre « Hébergement web », PHP + MySQL seulement, pas de Node.js, pas de SSH
  garanti). Simple Commerce doit être hébergé **dessus**. Il achètera plus tard un nom de domaine pour Simple Commerce.
- **Ses futurs clients ne sont PAS forcément chez OVH** (il l'a répété) : le portail doit relier des sites hébergés
  n'importe où (o2switch, Hostinger, IONOS, Infomaniak, LWS, PlanetHoster, Shopify, WordPress, Webflow, GitHub…).
- **Kumao / Coumao** (dépôt `ougot0/coumao`, boutique de sacs au crochet) est **un simple exemple de vrai site** qu'il
  possède pour tester. **Ça n'a aucun rapport avec Simple Commerce** : ne jamais mettre « Kumao » dans le portail,
  ses textes, ses exemples ou ses explications. Il l'a demandé explicitement.
- Ce qu'il veut à terme : que **n'importe quel site** soit modifiable « comme Shopify » (produits, stocks, prix, ouvert/fermé),
  quelle que soit la façon dont il est construit. Voir « Ce qui reste à faire ».

## 2. Où est le code

Dépôt GitHub : `ougot0/simplecomerce` (une seule « m », c'est le vrai nom). Branche de travail : `claude/brave-feynman-zya4u2` (avant : `claude/magical-hopper-xjxczz`).
**Il y a deux versions du portail dans le dépôt** :

| Dossier | Version | Statut |
|---|---|---|
| `portail/` | **PHP 8.1+ / MySQL** pour hébergement mutualisé | **La version à utiliser et à faire évoluer.** C'est celle qui va sur OVH. |
| racine (`app/`, `lib/`, `components/`, `supabase/`…) | Next.js 16 + Supabase + Vercel | Première version, complète mais **mise de côté** (OVH mutualisé ne fait pas tourner Node.js). Ne pas la supprimer sans l'accord du propriétaire (une tentative de suppression a été refusée). Ne plus y ajouter de fonctions. |

### Structure de `portail/`

```
portail/
├── public/                 ← SEUL dossier exposé sur Internet (racine du multisite OVH)
│   ├── index.php           ← point d'entrée unique (+ routeur du serveur PHP intégré pour le dev)
│   ├── .htaccess           ← tout vers index.php ; https forcé à décommenter
│   ├── .ovhconfig          ← PHP 8.3 (copie aussi à la racine de portail/)
│   └── assets/             ← app.css, app.js, icon.svg, fonts/ (Figtree en woff2, servie localement)
├── src/
│   ├── bootstrap.php       ← constantes SC_ROOT/SC_STORAGE, autoload, helpers.php, Config::load, erreurs → exceptions
│   ├── helpers.php         ← fonctions de gabarit : e(), csrf_field(), icon() (icônes SVG au trait), tag(), image_src(), show_value()…
│   ├── Config.php          ← lit config.php ; mode démo si SC_DEMO=1
│   ├── Db.php, Schema.php  ← PDO (MySQL en prod, SQLite en démo) ; tables sc_* ; Schema::install()
│   ├── Repo.php            ← toutes les lectures/écritures en base (aucun contrôle de droits ici)
│   ├── Http/               ← App (routes + en-têtes de sécurité + CSRF), Req, Response, View, Halt
│   ├── Controllers/        ← Auth, Sites (assistant « Relier un site »), Site, Editor, Settings, Admin, Misc, Install
│   ├── Services/           ← Access (droits, isolation, assistance), Auth, Sites (ouvrir un connecteur), Changes
│   │                          (publier/annuler/fermer + historique), Media (photos), Scheduler, Notifier, Demo
│   ├── Content/            ← moteur de contenu : ContentSchema, Infer (détection auto), Formats (JSON/YAML/Markdown/JS),
│   │                          Values (validation), FormInput (formulaire HTML → valeurs typées), Price, Paths
│   ├── Adapters/           ← connecteurs : Shopify, WordPress(+Woo), Webflow, CustomApi ; Files/ = FileSiteAdapter +
│   │                          backends GitHub, GitLab, Bitbucket, SFTP (phpseclib), FTP/FTPS, Local (démo)
│   └── Support/            ← Vault (chiffrement), Session, Net (anti-SSRF), Http (cURL), Html (nettoyage), Text, Log, Mailer
├── templates/              ← gabarits PHP (layout/ site|plain|auth|bare, site/, sites/, auth/, admin/, partials/)
├── bin/cron.php            ← publication des brouillons programmés (tâche planifiée OVH)
├── storage/                ← sessions, photos en attente, journal app.log, base SQLite de démo (interdit d'accès, ignoré par git)
├── demo/sites/             ← modèles des 2 faux sites de démo (copiés dans storage/demo-sites au premier lancement)
├── vendor/                 ← dépendances Composer COMMITÉES (OVH n'a pas Composer) : phpseclib 3, symfony/yaml 6.4
├── tests/run.php           ← lanceur de tests sans dépendance
├── config.php              ← créé par l'installation, JAMAIS commité (.gitignore)
└── README.md               ← guide de mise en ligne OVH pas à pas (tenu à jour)
```

Autres fichiers utiles : `docs/PREPARER-UN-SITE.md` (comment rendre un site modifiable), `docs/API-SUR-MESURE.md`
(contrat de l'API pour les sites avec base de données), `docs/ARCHITECTURE.md` (version Next.js),
`examples/simplecommerce-endpoint.php` (exemple d'API sur mesure).

## 3. Technologies

- **PHP 8.1 minimum** (testé en 8.3), extensions : pdo_mysql, sodium, curl, gd (WebP), mbstring, intl, ftp.
- **MySQL/MariaDB** en production (OVH), **SQLite** en démonstration.
- **Composer** (autoload PSR-4 `SimpleCommerce\` → `src/`), `vendor/` commité, `"platform": {"php": "8.1.0"}`.
  Après `composer install`, supprimer les dossiers `.git`, `tests`, `Tests`, `.github` de `vendor/` (31 Mo → 4 Mo).
- Pas de framework, pas de bundler : CSS et JS écrits à la main dans `public/assets/`, sans bibliothèque externe.
- Le JS est une **amélioration** : les formulaires fonctionnent sans lui (noms de champs `data[cle][ligne][sous-cle]`
  reconstruits côté serveur par `FormInput::coerce`). JS indispensable seulement pour : envoi/recadrage de photo,
  ajout/déplacement de lignes, texte enrichi, test de connexion sans recharger.

## 4. Charte graphique et ton

- **Couleurs (demandées par le propriétaire : « beige, noir, blanc, couleurs classiques, moderne, compréhensible »)** :
  fond beige clair `#f4efe6`, cartes blanches `#ffffff`, beige `#ebe3d5` / `#d9ccb6`, lignes `#e4dccf`,
  encre `#141210`, texte secondaire `#4b453e`, discret `#8b8377`. **Menu latéral noir**, boutons principaux noirs (pilules).
  **Seule couleur hors palette : rouge sombre `#a12d1f`** (fond `#fbeeec`), réservé aux erreurs et aux suppressions.
  Variables CSS dans `:root` en tête de `public/assets/app.css`.
- **Pastilles d'état** : « En ligne » noir, « Brouillon »/« Programmé » beige, « Masqué » gris bordé, « Échec » rouge.
- **Police : Figtree** (400/500/600/700), servie depuis le portail (pas de Google Fonts : RGPD + CSP `font-src 'self'`).
- **Icônes** : SVG au trait (`icon()` dans `helpers.php`), **jamais d'emoji** dans l'interface.
- **À éviter absolument** (direction artistique initiale, pour ne pas ressembler à un site généré par IA) :
  dégradés violets, polices Inter / Geist / Space Grotesk, petit label en MAJUSCULES au-dessus d'un grand titre centré,
  animations superflues, faux témoignages, emojis. Les cartes blanches avec ombre légère sont acceptées
  (elles font partie du style beige/noir/blanc validé).
- **Ton** : français simple, vouvoiement, phrases courtes, **aucun jargon** (jamais « JSON », « commit », « API »,
  « token », « dépôt », « schéma »… dans ce que voit un client ; un administrateur peut voir « Schéma de contenu »).
  Les boutons disent ce qu'ils font : « Publier sur mon site », « Enregistrer sans publier », « Relier mon site »,
  « Tester la connexion ». Les erreurs disent quoi faire. Pluriels et articles accordés (`Text::plural`, `Text::addLabel`).

## 5. Décisions prises ensemble (et pourquoi)

1. **Enregistrer = publier directement** sur le site du client, avec en plus **« Enregistrer sans publier »** (brouillon)
   et **« Programmer… »** (publication à une date/heure). Demande explicite.
2. **Les clients créent leur compte eux-mêmes** et retrouvent leur site déjà relié à chaque connexion (accès chiffrés
   stockés une fois). **« Rester connecté »** (jeton 30 jours haché en base). **Connexion par lien e-mail** possible.
3. **Un client peut inviter un collègue** (rôle « Modifier le contenu » ou « Tout gérer »), invitation liée à l'adresse
   e-mail, valable 14 jours, lien affiché si l'e-mail ne part pas.
4. **Nom : « Simple Commerce »** (pas d'autre nom, pas « Kumao »).
5. **Photos stockées sur le site du client lui-même** (le plus simple) : en attente dans `storage/media` côté portail,
   puis envoyées **dans la même écriture que le contenu** (`sc-media:<uuid>` remplacé par le chemin final).
   Réencodage WebP qualité 82, redimensionnement, rotation EXIF, métadonnées/GPS retirés, 8 Mo max, JPEG/PNG/WebP seulement.
6. **« Fermer le site temporairement »** (demande explicite) : fichier `simplecommerce-statut.json`
   `{ferme, message, reouverture}` écrit dans le dossier de contenu ; GET/PUT `/status` pour l'API sur mesure ;
   pour Shopify/WordPress/Webflow, le portail explique où cliquer dans leur administration (pas d'API pour ça).
7. **Hébergement sur OVH mutualisé ⇒ réécriture en PHP** (la version Next.js ne peut pas y tourner). Installation
   **par FTP + page web d'installation** (pas de terminal). Dossier racine du multisite = `simplecommerce/public`.
8. **« Les clients ne sont pas tous chez OVH »** ⇒ préréglages d'hébergeurs (OVH, o2switch, Hostinger, IONOS, Infomaniak,
   LWS, PlanetHoster, autre) qui pré-remplissent le formulaire SFTP/FTP et disent où trouver les accès.
9. **(Révisé le 12/10/2026) Plus de page d'installation** : le propriétaire l'a trouvée incompréhensible (« trop
   d'informations, le mec ne sait pas où les trouver »). Au premier passage, `InstallController::autoInstall()` met le
   site en service seul : base **SQLite** `storage/simplecommerce.sqlite` (sans WAL : disques réseau), clés générées,
   `config.php` écrit, inscriptions **ouvertes** (`signup_open=1`). L'accueil `/` affiche **Créer un compte** (e-mail +
   mot de passe + « Rester connecté », plus de champ nom). **Le premier compte créé devient administrateur** et arrive sur
   `/admin`. Le formulaire MySQL ne reste qu'en secours si SQLite manque. Bouton admin « Rendre le portail privé » inchangé.
10. **Mode démonstration** (`SC_DEMO=1`) avec 2 faux sites (Pâtisserie Lune en `content.json`, Atelier Brun en
    Markdown/YAML) et 3 comptes. Le propriétaire a été **déçu de voir de faux produits** en reliant un vrai site dans
    le prototype : toujours expliquer clairement ce qui est démo et ce qui est réel. En production, aucune donnée de démo.
11. **Les prix sont réécrits dans le format du site** : nombre, centimes ou texte (« 4,50 € »). Un prix rond reste rond
    (« 49 € » et non « 49,00 € ») sauf si le site écrit toujours les centimes (`priceFormat.fixed`).
12. **Format « JS »** ajouté au moteur : fichiers `globalThis.NOM = { …JSON… };` (sites statiques qui chargent leurs
    données par une balise `<script>`). Seul le JSON est réécrit, le commentaire et la déclaration sont conservés.
13. **Modifications conservatrices** : seul l'élément modifié change dans le fichier (style, indentation, objets vides,
    dates YAML, ordre conservés) ; champs cachés et inconnus conservés ; **conflit optimiste** (empreinte du contenu
    affiché, refus si le site a changé entre-temps) ; écriture atomique (fichier temporaire + renommage) puis `chmod 0644`.
14. **Historique + annulation** de chaque modification (opération inverse), y compris l'ordre et la fermeture.
15. **Publication programmée** : `bin/cron.php` (tâche planifiée OVH) + secours au plus 1×/minute pendant les visites.
    Si le contenu a changé depuis le brouillon, la publication est refusée, la date retirée et l'auteur prévenu par e-mail.
16. **Options ajoutées à la demande « plus d'options »** : recherche dans les listes, Masquer/Afficher en un clic,
    Dupliquer (copie masquée), bibliothèque « Mes photos », sauvegarde téléchargeable (JSON), avis e-mail aux autres
    membres (max 1 / 30 min / site), page d'aide, statistiques dans l'administration, aperçu en direct dans l'éditeur.
17. **Prototype cliquable** (artifact claude.ai `https://claude.ai/artifact/AhpE7mGFoipZ8NCsQPoWPc`, version 3,
    données dans le localStorage) : il **montre l'ancien parcours**, il n'a **pas** les options de la version PHP.
    Le vrai test se fait sur l'installation OVH.

## 6. Sécurité — règles non négociables (demande initiale)

- **Identifiants des sites chiffrés en base** (`Vault` : sodium XChaCha20-Poly1305, l'identifiant du site sert de donnée
  associée — un secret recopié sur un autre site ne se déchiffre pas), **jamais renvoyés au navigateur** (seule une
  empreinte « …a3F9 » est affichée), **jamais dans le code, les logs** (`Log::redact`) ni le dépôt.
  Clé dans `config.php` hors du dossier public. **Perdre `config.php` = perdre tous les accès enregistrés.**
- **Isolation stricte entre clients** : chaque page et action passe par `Access::site()` / `Access::section()` ;
  un site non autorisé répond **404** (pas 403, pour ne pas révéler qu'il existe). Photos en attente servies
  uniquement aux membres du site. Testé : un client ne peut ni voir ni écrire sur le site d'un autre.
- **Appels externes uniquement côté serveur** (cURL, sans suivre les redirections). **Anti-SSRF** : adresses privées et
  locales refusées (`Net::assertPublicHost/Url`), https obligatoire, pas d'identifiants dans l'URL. Exceptions :
  `SC_ALLOW_PRIVATE=1` en démo, ou `'allow_private_hosts' => true` dans `config.php` (tests/intranet, désactivé par défaut).
  SFTP : empreinte du serveur mémorisée à la première connexion puis vérifiée.
- **Fichiers envoyés validés** : vrai type par le contenu (finfo + getimagesize), pas de SVG, poids et pixels limités, réencodés.
- CSRF sur tous les POST (jeton de session, aussi en en-tête `X-CSRF-Token`), cookies HttpOnly + SameSite=Lax (+ Secure
  en https), CSP stricte (`script-src 'self'`, aucun script ou style en ligne, aucun domaine tiers), limitation des
  tentatives de connexion (8/15 min par e-mail, 30 par IP), texte enrichi nettoyé par liste blanche (p, br, strong, em, a, ul, ol, li).

## 7. Ce qu'il ne faut surtout pas modifier / casser

- **Ne jamais commiter `config.php`**, ni `storage/` (sauf `.htaccess` et `.gitignore`), ni de vrais identifiants.
- **Ne pas changer le format de chiffrement** (`Vault`, préfixe `v1:`, donnée associée = id du site) sans migration :
  tous les accès enregistrés deviendraient illisibles.
- **Ne pas renommer les tables `sc_*` ni leurs colonnes** sans migration (bases déjà installées chez OVH).
- **Ne pas déplacer la logique hors de `public/`** : seul `public/` doit être exposé. Garder `.htaccess` de `portail/`
  (bloque `src`, `storage`, `config.php`… si le multisite pointe au mauvais endroit) et `storage/.htaccess`.
- **Garder `vendor/` commité** (OVH sans Composer) et compatible PHP 8.1.
- **Ne pas ajouter de dépendance Node.js / build** à la version PHP, ni de CDN / Google Fonts (CSP + RGPD).
- **Ne pas mettre `php_value` dans `.htaccess`** : erreur 500 sur OVH mutualisé.
- **Ne pas affaiblir** l'isolation (404), le CSRF, l'anti-SSRF, la validation des photos, le masquage des secrets.
- **Ne pas réécrire tout un fichier de contenu** du client : toujours passer par `Formats::parse/serialize` qui conserve le style.
- **Ne pas supprimer la version Next.js** sans accord explicite du propriétaire.
- **Aucun « Kumao/Coumao »** dans le portail.

## 8. Mettre en ligne (OVH) — résumé (détails dans `portail/README.md`)

1. Construire l'archive : `git archive HEAD portail | tar -x -C <dossier>`, renommer `portail` → `simplecommerce`,
   retirer `tests/`, zipper. (C'est ce qui a été donné au propriétaire : `simplecommerce-ovh.zip`.)
2. OVH → Bases de données → créer une base MySQL (noter serveur `xxx.mysql.db`, nom, utilisateur, mot de passe).
3. FileZilla (FTP, port 21, accès dans l'onglet FTP-SSH) → envoyer le dossier `simplecommerce/` **à côté de `www/`**.
4. OVH → Multisite → ajouter `portail.<domaine>` avec **dossier racine `simplecommerce/public`**, cocher SSL.
5. Ouvrir l'adresse → page d'installation (vérifie PHP/extensions/droits, demande la base et le compte admin,
   génère `key`, `secret`, `cron_key`, écrit `config.php`). **À faire tout de suite** (page ouverte à tous tant que non faite).
   Garder une copie de `config.php`.
6. OVH → Plus → Tâches planifiées → `simplecommerce/bin/cron.php`, PHP 8.3, toutes les heures.
7. Plus tard : acheter le domaine (Noms de domaine → Commander), l'ajouter au multisite (même dossier),
   changer `'url'` dans `config.php`.
8. Mise à jour : renvoyer `src/`, `templates/`, `public/`, `vendor/`, `bin/` ; ne jamais écraser `config.php` ni `storage/`.

**Nom de domaine acheté (12/10/2026) : `simplecommercess.com`** (deux « s » à la fin), SSL Let's Encrypt actif.
**Attention, disposition réelle chez lui** : le contenu de l'archive a été envoyé DANS `/home/coumaoq/simplecommerce/public/`
(un étage trop bas). Plutôt que de déplacer, le multisite pointe sur **`simplecommerce/public/public`** (sûr : `src/`,
`config.php`… restent hors du web). Donc chez lui : racine du portail = `simplecommerce/public/`, tâche planifiée =
`simplecommerce/public/bin/cron.php`, mises à jour à renvoyer dans `simplecommerce/public/`. Autres sites sur le même
hébergement (ne pas y toucher) : `www`, `ougot`, `aminteriorss`.

**Statut au 12/10/2026 : installation en cours sur l'OVH du propriétaire (envoi par WinSCP)** (il a reçu l'archive et les étapes ; il ne
nous donne pas ses mots de passe — ne pas les lui demander dans la conversation).

## 9. Travailler sur le projet

- Démo locale : `cd portail && SC_DEMO=1 SC_ALLOW_PRIVATE=1 php -S 127.0.0.1:8080 -t public public/index.php`
  → http://127.0.0.1:8080. Comptes : `marie@patisserie-lune.fr` / `tarte-citron-2026`, `paul@atelier-brun.fr` /
  `maison-bois-2026`, `alex@simplecommerce.demo` / `atelier-demo-2026` (admin). Effacer `storage/` remet tout à zéro.
- Tests : `php portail/tests/run.php` (61 vérifications : sécurité, coffre, photos, formulaires, formats, moteur de
  contenu sur les sites de démo, annulation). `php -l` sur chaque fichier modifié.
- Tests navigateur : Playwright via `node_modules/playwright-core` du dépôt, Chromium `/opt/pw-browsers/chromium`.
- Tests réels faits pendant la session (à refaire si on touche aux connecteurs) : MariaDB + installation web ;
  SFTP (sshd) et FTP (vsftpd) locaux ; **WordPress 6.8 + WooCommerce 10.2** locaux en https (stunnel + certificat
  auto-signé, `wp.test` dans /etc/hosts, `NO_PROXY=wp.test`, `'allow_private_hosts' => true`) : lecture des produits,
  prix, stock, photo, masquer, création — tout vérifié dans WooCommerce. **Shopify et Webflow jamais testés en réel.**
- Pièges : `pkill -f motif` tue aussi le shell si le motif apparaît dans la commande → tuer par PID ;
  le conteneur peut redémarrer (MariaDB et serveurs à relancer) ; wordpress.org est bloqué par le proxy
  (WordPress récupéré par `git clone https://github.com/WordPress/WordPress --branch 6.8.3`).
- Git : commits en français, descriptifs, terminés par les lignes d'attribution demandées par l'environnement.
  Pas d'identifiant de modèle dans les fichiers ou commits. **Pas de pull request sans demande explicite.**
  Pousser sur la branche indiquée par l'environnement de la session (dernière : `claude/brave-feynman-zya4u2`).

## 10. Ce qui reste à faire

1. **Accompagner l'installation sur l'OVH du propriétaire** (étapes section 8) et le premier vrai site relié ;
   lui demander des captures d'écran en cas de souci.
2. **« Comme Shopify, pour n'importe quel site »** — **première version construite le 12/10/2026** : type de site `catalogue`
   (« Gérer mes produits ici, puis les afficher sur mon site »), contenu gardé dans la table `sc_files` (`DbBackend`, créée à la
   demande), ligne à coller `<script src="…/catalogue/{publicKey}.js">` (widget `public/assets/catalogue-widget.js`, Shadow DOM,
   routes publiques sans session dans `App::handle`, `CatalogueController`). Reste à faire : paiement (Stripe), variantes, catégories.
   Description d'origine :
   un **catalogue hébergé par Simple Commerce** (produits, stocks, prix, ouvert/fermé gardés dans la base du portail)
   + **une ligne à coller une fois** dans le site du client (script public qui affiche les produits, comme le bouton
   d'achat Shopify/Snipcart). Ainsi même un site codé à la main devient modifiable sans préparation. À concevoir :
   route publique lecture seule + CORS, cache, gabarit d'affichage sobre et personnalisable, gestion des stocks,
   éventuellement paiement Stripe. Aujourd'hui, un site « fait main » dont les produits sont dans le code doit être
   **préparé une fois** (contenu sorti dans un fichier de données) — c'est ce qui a été fait pour l'exemple Kumao.
3. Tester **Shopify** et **Webflow** avec de vrais comptes (vérifiés le 12/10/2026 contre des imitations fidèles de leurs API
   seulement). Shopify : depuis le 01/01/2026 plus de jeton `shpat_` pour les nouvelles applications → le portail accepte
   **Client ID + Client secret** (application du Dev Dashboard, jeton 24 h obtenu à chaque connexion) ; API `2026-07`
   (`ShopifyAdapter::API_VERSION`, à relever chaque année : `inventorySetQuantities` exige `@idempotent` depuis 2026-04).
4. Mettre à jour le **prototype cliquable** (artifact) avec le design et les options de la version PHP, si le propriétaire le veut.
5. Brancher le **nom de domaine** `simplecommercess.com` (acheté le 12/10/2026) dans le multisite OVH.
6. Pistes : connecteurs Wix, Squarespace, PrestaShop (listés « pas encore disponibles ») ; envoi d'e-mails par SMTP
   si `mail()` d'OVH arrive en indésirables ; décider du sort de la version Next.js (la garder ou la supprimer — au propriétaire).

## 11. L'exemple Kumao (hors projet, pour mémoire)

Le propriétaire possède le site **Coumao** (dépôt `ougot0/coumao`, site statique + PHP Stripe chez OVH). Pour tester,
une branche **`simplecommerce`** a été poussée dans ce dépôt (non fusionnée, rien en ligne) : produits déplacés dans
`contenu/catalogue.js`, prix du paiement lus dans ce même fichier (`catalogue.php`), cases « disponible »/« nouveaute »,
fermeture via `contenu/simplecommerce-statut.json`, `contenu/simplecommerce.json` pour les libellés. Ne la fusionner
ou la supprimer que sur demande. Encore une fois : **aucun lien avec le portail Simple Commerce**.

## 12. Journal

- 08/10/2026 : version Next.js complète (vitest, docs, démo) ; style beige/noir/blanc ; prototype cliquable v3.
- 08–09/10/2026 : réécriture PHP/MySQL dans `portail/` (connecteurs, éditeur, options, installation, tests, guide OVH).
- 10/10/2026 : format JS, prix ronds conservés, portail privé par défaut, test réel WordPress/WooCommerce, archive OVH.
- 11/10/2026 : création de ce fichier.
- 12/10/2026 (nuit) : catalogue hébergé + ligne à coller (pour tous les sites, sans préparation). Nouvel élément visible par défaut.
- 12/10/2026 (soir) : mise en service automatique SQLite + inscription e-mail/mot de passe (décision 9 révisée).
- 12/10/2026 : vérification complète avant mise en ligne. Testés en réel : installation web (PHP intégré et **Apache** avec
  les `.htaccess`, y compris dossier racine mal réglé), SFTP, FTPS, WordPress+WooCommerce, API sur mesure ; GitHub et
  Shopify contre des imitations de leurs API. Corrections : Shopify (Client ID/secret, API 2026-07, mise en vente des
  nouveaux produits), droits des dossiers photos (0755), mode passif FTP, passage automatique en https, port MySQL
  `serveur:port`, accords (« 0 site relié »…), textes Webflow/Bitbucket. Guide de test : `docs/TESTER-LE-PORTAIL.md`.
