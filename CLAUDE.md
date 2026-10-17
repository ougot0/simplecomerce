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
│   ├── Controllers/        ← Auth, Sites (assistant « Relier un site »), Site, Editor, Settings, Admin, Misc, Install,
│   │                          Catalogue + Public (adresses publiques des sites clients), Shop (commandes/avis/stats), Billing
│   ├── Services/           ← Access (droits, isolation, assistance), Auth, Sites (ouvrir un connecteur), Changes
│   │                          (publier/annuler/fermer + historique), Media (photos), Scheduler, Notifier, Demo,
│   │                          Backups, Shop (stock bas), Waitlist, Orders, Reviews, Stats, Hours (horaires/congés), Billing (Stripe)
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

**Application iPhone** : dossier `application-iphone/` à la racine (hors du zip OVH) — Swift/UIKit + WKWebView qui ouvre
`https://simplecommercess.com` (`Reglages.swift`), projet décrit par `project.yml` (XcodeGen, `.xcodeproj` non commité),
construit par GitHub Actions (`.github/workflows/application-iphone.yml`, Mac `macos-15`, Xcode le plus récent = 26.x) :
aperçu simulateur + photo à chaque push, envoi TestFlight via « Run workflow » + secrets `APPLE_TEAM_ID`, `APPLE_CLE_ID`,
`APPLE_EMETTEUR_ID`, `APPLE_CLE_P8`. Guide pas à pas : `application-iphone/README.md` ; textes App Store :
`FICHE-APP-STORE.md` ; captures 1320×2868 : `captures/`. Bundle ID `com.simplecommercess.app`.

Autres fichiers utiles : `docs/PREPARER-UN-SITE.md` (comment rendre un site modifiable), `docs/API-SUR-MESURE.md`
(contrat de l'API pour les sites avec base de données), `docs/ARCHITECTURE.md` (version Next.js),
`examples/simplecommerce-endpoint.php` (exemple d'API sur mesure).

## 3. Technologies

- **PHP 8.1 minimum** (testé en 8.3), extensions : pdo_mysql, sodium, curl, gd (WebP), mbstring, intl, ftp.
- **MySQL/MariaDB** en production (OVH), **SQLite** en démonstration.
- **Composer** (autoload PSR-4 `SimpleCommerce\` → `src/`), `vendor/` commité, `"platform": {"php": "8.1.0"}`.
  Depuis la version G : `anthropic-ai/sdk` (bibliothèque officielle Claude) + `guzzlehttp/guzzle` (plugin `php-http/discovery` désactivé).
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
- **Logo** : `public/assets/logo-*.png` (carré noir arrondi, « SC » crème + flèche de souris), fourni par le propriétaire.
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
   stockés une fois). **« Rester connecté »** (jeton haché en base, 400 jours renouvelés à chaque visite = sans limite, demandé le 12/10/2026). **Connexion par lien e-mail** possible.
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
   `config.php` écrit, inscriptions **ouvertes** (`signup_open=1`). L'accueil `/` affiche **Créer un compte** au tout premier passage, ensuite une page de présentation publique (avec « Créer un compte ») (e-mail +
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
18. **Onze idées choisies le 13/10/2026** (« 4 5 6 7 8 10 13 15 16 20 21 ») : pastilles et produits mis en avant,
    « Prévenez-moi », alerte de stock bas, commandes, modification en lot, horaires et congés programmés, application
    téléphone, statistiques sans Google, avis clients, abonnements Stripe, sauvegardes automatiques. Choix faits :
    **commandes sans paiement en ligne** (le commerçant règle avec son client ; prix et stock relus côté serveur, stock
    retiré à la commande, remis si annulée) ; avis **relus avant affichage** ; statistiques **sans cookie ni IP** ;
    horaires/avis/statistiques = **une ligne à coller** (`/horaires|avis|p/{publicKey}.js`), le compteur pouvant être
    **ajouté automatiquement** à toutes les pages des sites reliés par fichiers ; abonnements **éteints par défaut**
    (Administration → Abonnements), essai gratuit puis lecture seule, les **propriétaires** paient, les actions
    automatiques (commandes, congés) ne sont jamais bloquées ; sauvegardes de la nuit dans `storage/backups/`.

19. **Application iPhone, App Store seulement** (13/10/2026 : « je veux que sur Apple », pas de Play Store, pas
    d'installation par Safari). Gratuit impossible : compte Apple Developer 99 €/an (payé le 30/09/2026, commande
    W1829815798 ; bloquée par des noms différents « Dev Dev » / « Yotam Marec » / « Alon Marec » ; **débloquée le
    14/10/2026 vers 17 h** après un message au support : e-mail App Store Connect « Hello Alon Marec », compte au nom d'Alon Marec). Choix : application native légère autour du portail (pas
    Capacitor, pas de Node), construite sur GitHub (pas besoin de Mac). **Dans l'application, aucun paiement ni lien vers
    le paiement** (règle App Store 3.1.1) : le portail reconnaît `SimpleCommerceApp/` dans l'identité du navigateur
    (`Req::inApp()`, affichage seulement, jamais un droit). **Suppression du compte** dans « Mon compte » (exigence
    App Store 5.1.1(v)) : `AccountDeletion`.

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
- **Ne pas mettre `RewriteCond %{ENV:REDIRECT_STATUS}` dans `.htaccess`** : chez OVH (PHP lancé par redirection) la variable
  est déjà remplie, la règle ne s'applique plus → toutes les pages en erreur 500 (arrivé le 13/10/2026).
- **Ne pas affaiblir** l'isolation (404), le CSRF, l'anti-SSRF, la validation des photos, le masquage des secrets.
- **Ne pas réécrire tout un fichier de contenu** du client : toujours passer par `Formats::parse/serialize` qui conserve le style.
- **Ne pas supprimer la version Next.js** sans accord explicite du propriétaire.
- **Aucun « Kumao/Coumao »** dans le portail.
- **Ne pas remettre de bouton, de prix ou de lien de paiement visible dans l'application iPhone** (`Req::inApp()`) : refus Apple.

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
8. Mise à jour : depuis la version E, remplacer tout le dossier du portail est sans risque : `config.php` et `storage/`
   sont dans le dossier voisin `simplecommerce-donnees/` (à ne jamais supprimer ni écraser).

**Nom de domaine acheté (12/10/2026) : `simplecommercess.com`** (deux « s » à la fin), SSL Let's Encrypt actif.
**Attention, disposition réelle chez lui** : le contenu de l'archive a été envoyé DANS `/home/coumaoq/simplecommerce/public/`
(un étage trop bas). Plutôt que de déplacer, le multisite pointe sur **`simplecommerce/public/public`** (sûr : `src/`,
`config.php`… restent hors du web). Donc chez lui : racine du portail = `simplecommerce/public/`, tâche planifiée =
`simplecommerce/public/bin/cron.php`, mises à jour à renvoyer dans `simplecommerce/public/`. Autres sites sur le même
hébergement (ne pas y toucher) : `www`, `ougot`, `aminteriorss`.

**Statut au 13/10/2026 (soir) : version G installée chez lui, version H envoyée (photos), version I (onglet Rendez-vous automatique), version J (menus comme celui de Coumao), version K (filtres Soldes/Nouveautés/catégories), version L (sitemap lisible par Google), version M (Options du portail), version N (Studio /studio)** (il voit Administration → Rédaction automatique ; clé Claude
pas encore créée). Avant : **installation en cours sur l'OVH du propriétaire (envoi par WinSCP)** (il a reçu l'archive et les étapes ; il ne
nous donne pas ses mots de passe — ne pas les lui demander dans la conversation).

## 9. Travailler sur le projet

- Démo locale : `cd portail && SC_DEMO=1 SC_ALLOW_PRIVATE=1 php -S 127.0.0.1:8080 -t public public/index.php`
  → http://127.0.0.1:8080. Comptes : `marie@patisserie-lune.fr` / `tarte-citron-2026`, `paul@atelier-brun.fr` /
  `maison-bois-2026`, `alex@simplecommerce.demo` / `atelier-demo-2026` (admin). Effacer `storage/` remet tout à zéro.
- Tests : `php portail/tests/run.php` (96 vérifications : sécurité, coffre, photos, formulaires, formats, moteur de
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

0. **Application iPhone** : (a) ~~débloquer le compte développeur~~ **fait le 14/10/2026** (accès App Store Connect reçu) ; (b) envoyer chez OVH la version du portail avec suppression de compte et
   `inApp` ; (c) compte de démonstration pour Apple ; (d) étapes A à D du `application-iphone/README.md` (Bundle ID,
   app dans App Store Connect, clé API → secrets GitHub, Run workflow « Envoyer à Apple », soumission). **Premier envoi réussi le 14/10/2026** (construction n° 5,
   « Upload succeeded ») : secrets en place, App ID et app créés, contrat accepté. Envoi = `actions_run_trigger`
   (run_workflow, `application-iphone.yml`, ref = branche, `envoyer_a_apple: true`) ; les étiquettes git sont refusées
   par le proxy. Archive non signée puis signature App Store à l'export (sinon « no devices »). TestFlight : groupe interne
   « Moi » (studioyotam@gmail.com invité), groupe externe « amies » avec la version 1.0 (1) **en attente de vérification
   bêta** (14/10, ~18 h) → ensuite lien public pour un ami. Reste :
   fiche App Store, statut de commerçant UE, compte de démonstration, soumission. Ensuite : notifications « nouvelle commande » (APNs), liens
   universels (`apple-app-site-association`, Team ID nécessaire).
1. **Accompagner l'installation sur l'OVH du propriétaire** (étapes section 8) et le premier vrai site relié ;
   lui demander des captures d'écran en cas de souci.
2. **Tester en réel les fonctions du 13/10/2026** : abonnements avec un vrai compte Stripe (jamais testé contre Stripe :
   seulement signature des avis et logique), e-mails de commande/avis depuis OVH, compteur de visites sur un vrai site.
   Le propriétaire n'a pas encore envoyé ces fichiers chez OVH. Version G (13/10, soir) jamais testée contre les vrais
   services : Claude (rédaction, traduction — clé à créer sur platform.claude.com), Brevo, rendez-vous sur un vrai site.
3. **« Comme Shopify, pour n'importe quel site »** — **première version construite le 12/10/2026** : type de site `catalogue`
   (« Gérer mes produits ici, puis les afficher sur mon site »), contenu gardé dans la table `sc_files` (`DbBackend`, créée à la
   demande), ligne à coller `<script src="…/catalogue/{publicKey}.js">` (widget `public/assets/catalogue-widget.js`, Shadow DOM,
   routes publiques sans session dans `App::handle`, `CatalogueController`). Panier/commandes, pastilles, « Prévenez-moi »
   ajoutés le 13/10. Reste à faire : paiement en ligne des commandes, variantes, catégories.
   Description d'origine :
   un **catalogue hébergé par Simple Commerce** (produits, stocks, prix, ouvert/fermé gardés dans la base du portail)
   + **une ligne à coller une fois** dans le site du client (script public qui affiche les produits, comme le bouton
   d'achat Shopify/Snipcart). Ainsi même un site codé à la main devient modifiable sans préparation. À concevoir :
   route publique lecture seule + CORS, cache, gabarit d'affichage sobre et personnalisable, gestion des stocks,
   éventuellement paiement Stripe. Aujourd'hui, un site « fait main » dont les produits sont dans le code doit être
   **préparé une fois** (contenu sorti dans un fichier de données) — c'est ce qui a été fait pour l'exemple Kumao.
4. Tester **Shopify** et **Webflow** avec de vrais comptes (vérifiés le 12/10/2026 contre des imitations fidèles de leurs API
   seulement). Shopify : depuis le 01/01/2026 plus de jeton `shpat_` pour les nouvelles applications → le portail accepte
   **Client ID + Client secret** (application du Dev Dashboard, jeton 24 h obtenu à chaque connexion) ; API `2026-07`
   (`ShopifyAdapter::API_VERSION`, à relever chaque année : `inventorySetQuantities` exige `@idempotent` depuis 2026-04).
5. Mettre à jour le **prototype cliquable** (artifact) avec le design et les options de la version PHP, si le propriétaire le veut.
6. Brancher le **nom de domaine** `simplecommercess.com` (acheté le 12/10/2026) dans le multisite OVH.
7. Pistes : connecteurs Wix, Squarespace, PrestaShop (listés « pas encore disponibles ») ; envoi d'e-mails par SMTP
   si `mail()` d'OVH arrive en indésirables ; décider du sort de la version Next.js (la garder ou la supprimer — au propriétaire).

## 11. L'exemple Kumao (hors projet, pour mémoire)

Le propriétaire possède le site **Coumao** (dépôt `ougot0/coumao`, site statique + PHP Stripe chez OVH). Pour tester,
une branche **`simplecommerce`** a été poussée dans ce dépôt (non fusionnée, rien en ligne) : produits déplacés dans
`contenu/catalogue.js`, prix du paiement lus dans ce même fichier (`catalogue.php`), cases « disponible »/« nouveaute »,
fermeture via `contenu/simplecommerce-statut.json`, `contenu/simplecommerce.json` pour les libellés. Ne la fusionner
ou la supprimer que sur demande. Encore une fois : **aucun lien avec le portail Simple Commerce**.

## 12. Journal

- 17/10/2026 : projet GitHub `lennyoliel/ALYA-3D` (privé, pas lisible par nous) : « aucune page ni fichier de contenu ».
  Probablement un site fabriqué (React/Vite). **Version R** : listes de données lues aussi dans les `.mjs/.jsx/.ts/.tsx`
  (`export const produits: Produit[] = [...]`, annotations TypeScript tolérées) ; si rien n'est trouvé et qu'il y a un
  `package.json`, le message explique que le site est « fabriqué » et propose le catalogue hébergé. Les photos importées
  (`image: photo1`) ne sont pas lisibles : préparation nécessaire.
- 16/10/2026 (3) : réponse envoyée à Apple (vidéo sur Drive, 310 Mo, trop lourde pour la pièce jointe → lien public dans
  le message + même texte dans « Remarques ») puis « Mettre à jour la vérification » → version 1.0 de nouveau **en attente
  de vérification**. Rappels : un compte neuf est « en pause » tant qu'il n'est pas abonné (Administration → Abonnements →
  Offrir pour un compte de test) ; zip trop long pour l'extraction Windows → extraire dans `C:\sc` ou 7-Zip.
- 16/10/2026 (2) : **version Q** — à sa demande (« tant que t'as pas validé ton mail, t'as pas accès au site, change
  ça ») : on peut **relier un site sans avoir confirmé son adresse** (`SitesController::verifiedOrPage` et
  `auth/verify-needed.php` supprimés). Le bandeau « Confirmez votre adresse » reste ; la protection contre un compte créé
  avec l'adresse d'un autre reste (lien de connexion, « Reprendre ce compte »).
- 16/10/2026 : Apple demande une vidéo (Directive 2.1, « informations requises », pas un refus) ; texte de réponse en
  anglais donné. En filmant, il a trouvé un vrai défaut : inscription dans l'application → le lien de confirmation s'ouvre
  dans Safari (autre session) → l'ancien code croyait à un compte créé avec l'adresse d'un autre : mot de passe annulé,
  application déconnectée. **Version P** : `Auth::consumeVerification` confirme seulement (mot de passe et appareils gardés) ;
  ouvert ailleurs, page `auth/verified.php` « Retournez dans l'application » + bouton replié « Ce n'est pas vous qui avez
  créé ce compte ? → Reprendre ce compte » (jeton `claim` 7 jours, POST `/verifier/reprendre/{jeton}`, `Auth::claimAccount` =
  ancien comportement). Le lien de connexion par e-mail garde sa protection. Testé (curl, 2 sessions) + 105 vérifications.
- 15/10/2026 : **version O** — demande du propriétaire : « pour tout ce genre de trucs, ajoute-moi des boutons » (pas de
  ligne à coller). `Services\SiteTabs` (générique, une entrée par page dans `KINDS`) + `partials/site-tab.php` + route
  POST `/s/{slug}/onglet` (`ShopController::tabAction`, propriétaire seulement) : boutons « Ajouter l'onglet « Avis » »
  (page Avis clients) et « Ajouter l'onglet « Horaires » » (Réglages → Horaires), ligne à coller repliée en secours.
  Fermer les avis retire aussi l'onglet. Les pages ajoutées par le portail (rendez-vous/avis/horaires.html) reçoivent les
  onglets des autres ; retrait = site remis exactement comme avant (testé). Ces pages sont exclues de la détection.
  Rappel donné : les changements du portail (zip OVH) ne passent **pas** par Apple ; seules les fonctions propres au
  téléphone (Face ID, notifications, pubs) demandent une nouvelle version vérifiée. TestFlight 1.0 (1) **approuvé** le 15/10.
- 15/10/2026 (2) : il veut « un lien à moi, pas Claude, pour modifier Simple Commerce » sans que ça change en direct chez
  les clients. **Version N : Studio** (`/studio`, administrateur seul, lien en haut de l'Administration ; `Services\Studio`,
  `StudioController`, gabarit `studio.php`) : nom affiché (wordmark, titre), 4 couleurs (variables CSS servies par
  `/theme.css?v=…`, les autres teintes calculées), bandeau « message pour tous les clients », options (`Features` lit
  désormais `Studio::current()['off']`). Tout est d'abord un **brouillon** (meta `studio_draft`) visible seulement par
  l'administrateur (session `studio_apercu`, aperçu dans un cadre à droite) ; « Publier pour tous » → `studio_live`
  (+ `features_off`). « Effacer le brouillon », « Remettre les couleurs d'origine ». Testé au navigateur (client ne voit rien
  avant publication). Pour une vraie nouvelle fonction, il faut toujours passer par la conversation.
- 15/10/2026 : demande « un accès pour modifier Simple Commerce moi-même (retirer/ajouter des options) ». **Version M** :
  **Administration → Options du portail** (`/admin/options`, `Services\Features`, meta `features_off`) : 8 interrupteurs
  pour tous les clients (rendez-vous, avis, statistiques, version anglaise, « Écrire pour moi », aperçu téléphone,
  parrainage, « Besoin d'aide ? »). Éteinte = menus cachés + pages et adresses publiques en 404 (`Features::need`),
  rien d'effacé. Pour l'apparence ou une nouvelle option : il le demande dans la conversation. Testé au navigateur.
- 14/10/2026 : **Google Search Console** faite avec lui (propriété « Domaine » simplecommercess.com, validée par un
  enregistrement TXT `google-site-verification=…` dans la Zone DNS OVH, sous-domaine `@` — **ne jamais le supprimer**).
  La page d'accueil est indexée. Le sitemap était refusé (« Impossible de lire le sitemap ») car `robots.txt` interdisait
  tout sauf `/` et `/assets/` : **version L** ajoute `Allow: /sitemap.xml` (+ pages légales). Conseils donnés pour monter
  sur « Simple Commerce » (nom déjà pris par d'autres sociétés) : fiche Google Business, réseaux sociaux, liens depuis les
  sites clients, viser des recherches plus précises.
- 13/10/2026 (nuit, 8) : **version K**. (1) Décocher « Ouvrir la prise de rendez-vous » retire aussi l'onglet et sa page
  (avant : l'onglet restait et le bouton « Retirer » disparaissait) ; bouton « Refaire la page Rendez-vous ». (2) Menus
  remplis par le JavaScript du site (Coumao : Soldes, Nouveautés, Sacs… fabriqués depuis les produits) : la page créée
  garde le contenu d'origine **caché** (`<div hidden data-sc-page-originale>`) pour que le site fabrique ses onglets, et un
  petit script renvoie les clics sur ces onglets vers `index.html#collection`. (3) Demande « accès à Soldes et Nouveautés,
  pour tous les sites » : dans chaque liste, **filtres** (`?case=` pour chaque case à cocher non « visibilité », `?categorie=`
  pour une liste de choix de 2 à 10 valeurs), pastilles beiges sur les fiches, et dans « Modifier plusieurs » :
  « Mettre en « X » » / « Retirer de « X » » (`oui:`/`non:` + clé dans `EditorController::bulk`). Générique : marche pour
  tout site dont les produits ont des cases (JSON, JS, HTML, WooCommerce…). Testé sur une copie de Coumao dans la démo
  (`storage/demo-sites/cmtest`).
- 13/10/2026 (nuit, 7) : chez lui, sur Coumao : « Le menu de votre site n'a pas été trouvé ». Le menu de Coumao n'a que
  2 liens écrits (Accueil `#top`, Contact `#contact`) + `<div id="tabs">` rempli par JavaScript. **Version J** : menus
  `<nav>` sans liste acceptés dès 2 liens ; modèle d'onglet = le lien le plus simple (sans classe) ; sur la page créée, les
  liens du menu vers une ancre absente (`#top`) deviennent `index.html#top` et la marque `active` est retirée. Vérifié sur
  une copie du site Coumao (dépôt public `ougot0/coumao`, lu seulement). Rappel : le zip ne contient JAMAIS
  `simplecommerce-donnees` (créé par le portail sur le serveur ; l'inclure écraserait les comptes).
- 13/10/2026 (nuit, 6) : le propriétaire refuse la « ligne à coller » pour les rendez-vous (« je veux pas que la personne
  ait à demander à son développeur ») : veut un **onglet ajouté tout seul** dans le menu du site. **Version I** :
  bouton « Ajouter l'onglet « Rendez-vous » à mon site » (sites reliés par fichiers, propriétaire) → `Content\HtmlTab`
  ajoute `<li data-sc-onglet="rdv">` dans chaque menu d'en-tête/nav (copie d'un élément du menu, sans marque « page en
  cours », placé avant « Contact »), jamais dans le bas de page ; `FileSiteAdapter::setTabPage` crée `rendez-vous.html`
  à partir de la page contact (sinon l'accueil) : même en-tête/menu/pied, contenu principal remplacé, meta generator ;
  tout en une écriture. « Retirer l'onglet » remet le site exactement comme avant (testé). `rendez-vous.html` exclu de la
  détection. La ligne à coller reste en option repliée. Idée réutilisable pour d'autres pages (avis, catalogue…). 101 vérifications.
- 13/10/2026 (nuit, 5) : chez lui, site Ougot relié de nouveau (`ougot-3`) : produits et prix lus, mais **photos cassées**
  dans le portail (le portail les affiche depuis l'adresse publique du site ; adresse probablement en http — bloquée par la
  CSP `img-src https:` — ou incomplète). Correction **version H** : si une photo ne s'affiche pas, `app.js` la redemande à
  `/s/{slug}/fichier?chemin=…` (`SiteController::file`) qui la lit sur l'hébergement avec les accès enregistrés
  (connecteurs fichiers seulement, images jpg/png/gif/webp/avif vérifiées, cache 1 jour dans `storage/apercu/`).
  Lui conseiller aussi de mettre l'adresse exacte en https dans Réglages.
- 13/10/2026 (nuit, 4) : **application iPhone** (décision 19). Conseils : App Store payant (99 €/an), Play Store
  écarté par lui. Compte Apple « en attente » : diagnostic (nom ≠ pièce d'identité, facture « Dev Dev »), appel Apple
  conseillé (developer.apple.com/contact → Program Enrollment → Phone, ou 0800 046 046). Portail : `Req::inApp`,
  abonnement/parrainage cachés dans l'app, `/` → `/connexion` dans l'app, **Supprimer mon compte** (mot de passe +
  « SUPPRIMER », Stripe arrêté d'abord, sites seuls retirés, sites partagés transmis, seul admin protégé). Application
  `application-iphone/` construite avec succès par GitHub Actions (Xcode 26.3). Captures App Store faites dans la démo.
  96 vérifications.
- 13/10/2026 (nuit, 3) : **douze nouvelles fonctions** (« 8 9 12 13 15 17 20 21 22 24 25 26 »), version **G** :
  bon de commande / facture imprimable (n° de facture annuel, mentions du commerçant dans Réglages → Boutique) ;
  aperçu « Sur téléphone » ; **prise de rendez-vous** (`Bookings`, table `sc_bookings`, base v5, ligne `/rdv/{clé}.js`,
  annulation par lien e-mail) ; résumé du lundi par e-mail (`Digest`) ; **parrainage** (1 mois offert, `Referral`, crédit
  Stripe) ; **tableau des revenus** (Administration → Revenus, lu chez Stripe) ; bouton « Besoin d'aide ? » (formulaire →
  simplecommercess@gmail.com) ; rappel avant la fin de l'année gratuite ; **vérification de l'adresse e-mail** (sites
  reliables seulement après confirmation, l'admin peut confirmer à la main) ; **e-mails via Brevo** (Administration →
  E-mails, clé chiffrée, repli sur `mail()`) ; **« Écrire pour moi »** (`Writer`, Claude `claude-opus-5-5` par la
  bibliothèque officielle, clé dans Administration → Rédaction automatique, 40/jour/personne) ; **version anglaise**
  (`Translation` + `HtmlTranslate` : pages `x-en.html` à côté des pages françaises, bouton English/Français, mémoire de
  traduction dans `storage/traductions/`, retrait sans trace ; pages `-en.html` exclues de la détection). Tout testé dans
  la démo contre des imitations (Claude, Stripe, Brevo). 88 vérifications.
- 13/10/2026 (nuit, 2) : **audit de sécurité complet** (le propriétaire voulait le plugin « Security Guidance », pas activable
  dans une session en cours : audit fait par 3 agents en lecture seule, puis corrections vérifiées). Corrigé :
  commandes publiques (lignes du même produit additionnées, verrou par boutique `storage/commande-*.lock`, 40/jour, nom
  saisi retiré de l'e-mail client), limites par IPv6 /64 (`Req::ipBucket`), plafonds « Prévenez-moi » (300/produit) et
  statistiques (300 entrées/jour) ; mots de passe enregistrés d'un site **non réutilisés si l'hôte/l'identifiant change**
  (Réglages → Connexion) ; comptes créés sans preuve d'e-mail marqués `unverified:<id>` (sc_meta) → au 1er lien de
  connexion, ancien mot de passe annulé et appareils déconnectés ; changement de mot de passe = mot de passe actuel
  demandé (sauf 30 min après un lien e-mail) + autres appareils déconnectés (`pwv` en session, jetons `remember` supprimés) ;
  `SC_DATA` ne revient jamais à l'ancien emplacement une fois `config.php` déménagé ; export CSV des commandes réservé
  au propriétaire ; **SSRF** : IPv6 cachant une IPv4 refusées (`Net::isPrivateIp` normalisé), IP vérifiée imposée à cURL
  (`CURLOPT_RESOLVE`) et aux connexions FTP/SFTP ; **pages de détail** recopiées seulement si `.html` sans `<?` (injection
  PHP possible avant) ; photos : expression ancrée, sans guillemets/parenthèses/espaces (valeur inchangée tolérée) ;
  textes simples : balises `<x` refusées ; images max 25 Mpx ; `Log::redact` + `rk_`, `whsec_`, `nfp_`. 81 vérifications.
  Restent (faibles, connus) : 1er inscrit = admin (décision 9), FTPS sans vérification du certificat (préférer SFTP). Version F.
- 13/10/2026 (nuit) : **les comptes disparaissaient à chaque envoi du zip** (il remplace tout le dossier `public/` chez OVH,
  qui contenait `config.php` et `storage/`). Désormais **données hors du dossier du portail** : `SC_DATA` =
  `dirname(SC_ROOT)/simplecommerce-donnees` (chez lui `/home/coumaoq/simplecommerce/simplecommerce-donnees/`), avec
  `config.php` et `storage/` (base SQLite, sessions, photos, sauvegardes) ; déménagement automatique au 1er passage
  (`sc_data_dir()` dans bootstrap, chemin SQLite recalé dans `Config::load`) ; démo et `SC_DATA` (variable) inchangés.
  Testé : ancienne install → déménagement → dossier portail remplacé en entier → comptes conservés. **Pages légales**
  publiques (`LegalController`, `templates/legal/` : mentions légales, CGU, CGV, confidentialité ; infos éditeur dans
  Administration → Informations légales, « [à compléter] » sinon ; OVH et Stripe déjà cités), liens en bas des pages,
  case « J'accepte les conditions » obligatoire à l'inscription (acceptation notée dans le journal). **Première année
  gratuite** : `trialDays` 365 (migration base v4 qui passe l'ancien 7 → 365), textes via `Billing::freeSentence()`.
  Version E. ⚠ Ne JAMAIS supprimer `simplecommerce-donnees`. Vérification sécurité demandée (« je mets ma clé Stripe ») :
  admin 404 pour un client, CSRF 419, faux webhook 400, fichiers sensibles 404 — OK. Corrigé : code promo à 100 % devinable
  (« GRATUIT100 ») → 8 caractères minimum, code aléatoire proposé, 10 utilisations max, l'ancien désactivé chez Stripe.
- 13/10/2026 (soir) : **adresse de contact de Simple Commerce : `simplecommercess@gmail.com`** (`Config::contactEmail()`,
  surchargeable par `'contact_email'` dans config.php) : Reply-To des e-mails du portail, page d'aide, pied de la page
  d'accueil (modèle seulement : son `public/accueil.html` déjà créé n'est pas réécrit). Expéditeur (`mail_from`) inchangé
  (adresse du domaine, sinon indésirables). Stripe : nom public, logo (logo-512.png, < 512 Ko), secteur « Logiciels/SaaS »
  réglés par lui dans Stripe. Version D.
- 13/10/2026 (après-midi) : le propriétaire a testé en réel (sa clé Stripe, code promo) et s'est abonné **sans carte** : venait
  de `payment_method_collection=if_required` + essai côté portail. Demande : **il faut s'abonner avec sa carte pour avoir les
  7 jours gratuits**, puis prélèvement. Donc : plus d'essai sans abonnement (nouveau compte = « Pas encore abonné », lecture
  seule), `trial_period_days` chez Stripe (1er abonnement seulement), carte toujours demandée (même avec le code à 100 %),
  `trial_ends_at` = fin des jours gratuits Stripe (« Abonné — jours gratuits »). Boutons grisés après « retour arrière »
  (cache de page du navigateur) : remis à neuf sur `pageshow`. Version C. Il a envoyé chez OVH une installation complète
  (zip `simplecommerce-COMPLET-*.zip` = dossier `public` à mettre dans `/home/coumaoq/simplecommerce/`).
- 13/10/2026 (midi) : abonnements simplifiés à la demande : **9 €/mois ou 90 €/an**, **essai 7 jours**, l'admin colle
  seulement sa clé Stripe → `Billing::setupStripe` crée produit, 2 prix et webhook (si https) ; **code promo 100 %**
  (`setupPromo`, défaut `GRATUIT100`, `payment_method_collection=if_required` → 0 € sans carte). `SC_STRIPE_API` pour
  tester contre une imitation. **Version affichée** (`SC_VERSION`, Administration + Aide) car le propriétaire ne voyait
  aucune nouveauté chez OVH (ni « Boutique » dans Réglages, ni sauvegarde dans l'admin) : mise à jour mal envoyée,
  à vérifier avec lui (dossier `/home/coumaoq/simplecommerce/public/`).
- 13/10/2026 (matin) : après la restauration OVH (« tout marche »), **onze nouvelles fonctions** (décision 18), une par
  commit : sauvegardes automatiques (+ sauvegarde complète du portail dans l'administration), alerte de stock bas,
  modification en lot (`FileSiteAdapter::changeMany`, `Changes::publishBatch`), horaires + congés programmés
  (`Hours::tick` dans le Scheduler), pastilles (`DbBackend::upgradeSchema`, base en version 3), « Prévenez-moi »,
  commandes, avis, statistiques (`PublicController`, `ShopController`, `FileSiteAdapter::setPageScript`), application
  téléphone (`public/manifest.webmanifest`, `public/sw.js`, `assets/hors-connexion.html`), abonnements Stripe
  (`Billing`, `BillingController`, CSP `form-action` ouverte à checkout/billing.stripe.com). 68 vérifications.
  Testé dans la démo au navigateur (panier, commande, annulation avec remise en stock, e-mail de retour en stock, avis,
  statistiques, hors connexion, abonnement bloqué puis débloqué par un avis Stripe signé). Démo : lancer avec
  `SC_URL=http://127.0.0.1:PORT` sinon les scripts publics appellent localhost:8080.
- 13/10/2026 (nuit) : **incident** — le propriétaire a effacé par erreur tout son espace OVH (/home/coumaoq vidé à 0 h 26 : `www`,
  `ougot`, `aminteriorss`, `simplecommerce`…). Restauration OVH refusée avant 7 h (« Wait until 7:00 AM ») ; accès `-snapN` en FTP
  refusé chez lui. À faire après 7 h : FTP-SSH → Restaurer une sauvegarde → la plus récente (13/10 0 h 01). Copies anciennes des
  sites données en secours (dépôts coumao/yotam + copie AM). Erreur 500 de Simple Commerce causée par `REDIRECT_STATUS` dans
  le `.htaccess` racine (retiré). Sites récupérés par **Cyberduck en FTP avec `coumaoq-snap1`** (sauvegarde du 12/10 0 h 01 ;
  WinSCP échouait), puis renvoyés en SFTP. Erreur « 501 Not Implemented » même sur les images : **le `.ovhconfig` de la racine
  `/home/coumaoq/` manquait** (non recopié) → recopié depuis le zip (PHP 8.3, stable64) → tout remarche. Disposition finale
  confirmée : multisite → `simplecommerce/public/public`, code dans `/home/coumaoq/simplecommerce/public/`. Portail reparti de
  zéro (nouveau compte admin, sites à relier de nouveau).
- 12/10/2026 (nuit, 8) : **page d'accueil publique = vraie page** `public/accueil.html` (créée au premier passage depuis
  `templates/public/accueil.html`, `{{ORIGINE}}` remplacé ; ignorée par git, jamais écrasée par les mises à jour) : le propriétaire
  peut relier simplecommercess.com à son propre portail (SFTP/FTP OVH, dossier `/simplecommerce/public/public`) et modifier ses
  textes, étapes et logo. `accueil|home.html` comptent comme page d'accueil ; 2 blocs h1/h2 côte à côte ≠ liste.
  **Connecteur Netlify sans GitHub** (`NetlifyBackend`, type `netlify`, groupe « plateforme ») : jeton personnel Netlify + adresse
  `xxx.netlify.app` (ou domaine) ; lecture par `GET /sites/{id}/files` (+ contenu brut `application/vnd.bitballoon.v1.raw`),
  écriture = nouveau déploiement par empreintes SHA1 (liste complète, seuls les fichiers `required` envoyés), attente `ready`.
  Refus si le site se reconstruit depuis un dépôt (`build_settings.repo_url`) ou a des fonctions Netlify. **Testé seulement
  contre une imitation de l'API** (pas de vrai compte) : à vérifier en réel, notamment que `_redirects`/`_headers` figurent
  dans la liste des fichiers (sinon ils seraient perdus au déploiement).
- 12/10/2026 (nuit, 7) : **logo officiel de Simple Commerce** fourni par le propriétaire (carré noir arrondi, « SC » crème et
  flèche de souris) : `public/assets/logo-512.png`, `logo-96.png`, `favicon-32.png`, `apple-touch-icon.png` (recadrés, fond
  transparent). Affiché devant « Simple Commerce » (`.wordmark::before`), icône d'onglet, icône téléphone, `og:image` de l'accueil.
- 12/10/2026 (nuit, 6) : **logo du site modifiable** (rubrique « Logo du site », en tête de liste, sites reliés par fichiers sans
  fichier de contenu) : `HtmlCards::logo` trouve l'image du logo (classe/id/src contenant logo, brand, monogram… sur l'image ou un
  parent proche), le texte à côté (« Studio ») et l'icône de l'onglet (`<link rel=…icon>`) ; source de rubrique `{type: logo}` ;
  `FileSiteAdapter::writeLogo` remplace dans **toutes** les pages HTML en une écriture (chemins recalculés par page, `srcset`
  retiré, `type` de l'icône mis à jour) ; annulation exacte (sauf sites à plusieurs icônes différentes : toutes reprennent la
  première). Champs image `required` → pas de bouton « Retirer ». Aspect « libre », transparence conservée (PNG → WebP).
  Fichiers `docs/FICHE-TECHNIQUE.txt` et `docs/GUIDE-DEVELOPPEUR.txt` écrits à la demande du propriétaire.
- 12/10/2026 (nuit, 5) : « Rester connecté » sans limite (jeton 400 jours renouvelé à chaque visite). **Page de présentation
  publique** sur `/` (visiteur non connecté ; `templates/public/home.php`, mise en page `public`, styles `.pub-*`) : seule page
  indexable (`indexable` → `index, follow` + description + canonical via `Req::origin()`), toutes les autres restent `noindex`.
  `/robots.txt` (n'autorise que `/` et `/assets/`) et `/sitemap.xml`. Tout premier passage (aucun compte) : toujours `/inscription`.
  Le propriétaire a cherché « simplecommercess » sur Google : d'autres sociétés s'appellent « Simple Commerce » (Canada,
  simplecommerce.com) ; lui conseiller la Search Console (vérification par enregistrement DNS TXT dans la zone OVH).
- 12/10/2026 (nuit, 4) : **sites vitrines** (test : copie du site d'un ami, architecte d'intérieur, dossier `aminteriorss` chez OVH,
  https://aminteriorss.com) : `HtmlCards` reconnaît aussi les fiches sans prix (titre + texte/photo : projets, services, méthode),
  les photos en `style="background-image:url(…)"`, les prix « À partir de 70 € » / « Sur devis » (classe `price…`), les listes à
  puces (champ `points`, un point par ligne), et les fiches dont la classe diffère après la première (« price-card featured »).
  Un champ inchangé n'est plus réécrit (badges et mise en forme conservés). **Textes de chaque page** (titres, paragraphes,
  légendes, photos y compris de fond) modifiables un par un (`HtmlCards::texts`, rubrique « Textes de la page … », hors menu,
  liens, boutons, formulaires et fiches). Ordre des rubriques : ouvert/fermé, listes, textes de l'accueil, autres pages.
  Ensuite (demande : « modifier, en ajouter et supprimer » des projets) : **ajouter une fiche qui mène à sa propre page**
  (`projet-x.html`) crée cette page (`FileSiteAdapter::addDetailPage`, copie de la page de la dernière fiche : titre, onglet,
  fil d'Ariane, accroche, 1re photo, description et adresse canonique remplacés ; `HtmlCards::copyPage`), et la fiche y renvoie.
  Supprimer la fiche **garde le fichier de la page** (rien n'est perdu) mais la page détachée n'est plus proposée ; la remettre
  (ou annuler) la relie de nouveau. Après un ajout/suppression dans une page HTML, les rubriques sont relues
  (`Sites::rediscover`, fusion `Sites::mergeSchema` partagée avec « Relire le contenu »). Étiquette de fiche (`categorie`).
- 08/10/2026 : version Next.js complète (vitest, docs, démo) ; style beige/noir/blanc ; prototype cliquable v3.
- 08–09/10/2026 : réécriture PHP/MySQL dans `portail/` (connecteurs, éditeur, options, installation, tests, guide OVH).
- 10/10/2026 : format JS, prix ronds conservés, portail privé par défaut, test réel WordPress/WooCommerce, archive OVH.
- 11/10/2026 : création de ce fichier.
- 12/10/2026 (nuit, 3) : **produits écrits dans les pages HTML** reconnus (`HtmlCards` : blocs frères répétés avec un titre h1-h6 et
  un prix ; nom/prix/description/photo modifiés sur place ; ajout = copie de la dernière fiche, liens de paiement Stripe/PayPal
  de la copie remplacés par « # » ; format `html` dans `Formats`/`ContentSchema`). Réglage « ouvert/fermé » d'un fichier JS
  (`window.X = { open: … }`) proposé comme rubrique « Ouverture du site ». Testé par FTP sur une copie d'un vrai site de
  pâtisserie (dépôt `ougot0/yotam`, dossier `/ougot` chez OVH) : 7 gâteaux trouvés, prix « 6,90&nbsp;€ », ajout avec photo.
- 12/10/2026 (nuit, 2) : **sites faits main reliés sans préparation** : si aucun dossier/fichier de contenu, `FileSiteAdapter`
  cherche dans les .js/.json du site une liste de produits (`findProductFiles`, `isProductSection`) ; `JsLiteral` lit les
  `const NOM = [ … ]` (clés sans guillemets, virgules finales, commentaires) et ne réécrit que les éléments modifiés
  (format interne `jsvars` de `Formats`). Catégories peu nombreuses → liste de choix. FTP sans MLSD : liste via LIST.
  Testé par FTP sur une copie du site d'origine : 20 produits trouvés, prix au format du site, ajout avec photo.
  ⚠ Le site d'exemple encaisse via des identifiants de prix Stripe (stripe-config.js) : changer un prix dans le portail
  ne change pas le montant Stripe.
- 12/10/2026 (nuit) : catalogue hébergé + ligne à coller (pour tous les sites, sans préparation). Nouvel élément visible par défaut.
- 12/10/2026 (soir) : mise en service automatique SQLite + inscription e-mail/mot de passe (décision 9 révisée).
- 12/10/2026 : vérification complète avant mise en ligne. Testés en réel : installation web (PHP intégré et **Apache** avec
  les `.htaccess`, y compris dossier racine mal réglé), SFTP, FTPS, WordPress+WooCommerce, API sur mesure ; GitHub et
  Shopify contre des imitations de leurs API. Corrections : Shopify (Client ID/secret, API 2026-07, mise en vente des
  nouveaux produits), droits des dossiers photos (0755), mode passif FTP, passage automatique en https, port MySQL
  `serveur:port`, accords (« 0 site relié »…), textes Webflow/Bitbucket. Guide de test : `docs/TESTER-LE-PORTAIL.md`.
