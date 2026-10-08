# Simple Commerce — version PHP (hébergement web OVH et tout hébergement PHP)

Le portail qui permet à vos clients de modifier eux-mêmes leur site : produits, prix, photos, textes, horaires, actualités.
Cette version fonctionne sur un **hébergement web mutualisé** (OVHcloud Perso/Pro/Performance, o2switch, Hostinger, IONOS…) :
PHP 8.1 ou plus récent et une base MySQL/MariaDB, rien d'autre. Pas de Node.js, pas de terminal nécessaire.

Les sites **de vos clients**, eux, peuvent être n'importe où : chez n'importe quel hébergeur (FTP/SFTP), sur Shopify,
WordPress/WooCommerce, Webflow, GitHub, GitLab, Bitbucket, ou derrière une petite API sur mesure.

## Ce que contient ce dossier

| Dossier | Rôle | Visible depuis Internet ? |
|---|---|---|
| `public/` | page d'entrée, styles, scripts | **oui, uniquement celui-ci** |
| `src/`, `templates/` | le code du portail | non |
| `vendor/` | bibliothèques (SFTP en PHP pur, lecture YAML) | non |
| `storage/` | photos en attente, sessions, journal | non (bloqué) |
| `bin/cron.php` | publication des brouillons programmés | non |
| `config.php` | créé à l'installation : base de données et **clés de chiffrement** | non |

## Mettre en ligne sur OVH, pas à pas

### 1. Choisir l'adresse du portail

Le plus simple : un sous-domaine d'un domaine que vous avez déjà, par exemple `portail.votre-domaine.fr`.
Vous pourrez acheter un nom de domaine dédié plus tard (voir plus bas) : il suffira de l'ajouter de la même façon.

### 2. Créer la base de données

Espace client OVH → **Web Cloud → Hébergements** → votre offre → onglet **Bases de données** → **Créer une base de données**
(MySQL). Notez le **serveur** (du type `xxxxx.mysql.db`), le **nom de la base**, l'**utilisateur** et le **mot de passe**.

### 3. Envoyer les fichiers

Avec FileZilla (ou le FTP de l'espace client), connectez-vous à votre hébergement (onglet **FTP - SSH** pour les accès),
puis envoyez **tout le dossier `portail`** dans votre espace, par exemple sous le nom `simplecommerce/` à côté de `www/` :

```
/
├── www/                  ← votre site actuel, on n'y touche pas
└── simplecommerce/       ← le contenu de ce dossier portail/
    ├── public/
    ├── src/ templates/ vendor/ storage/ bin/
    └── .ovhconfig
```

Le dossier `storage/` doit être inscriptible (droits 705 ou 755 : c'est le cas par défaut chez OVH).

### 4. Relier l'adresse au dossier `public/`

Onglet **Multisite** → **Ajouter un domaine ou sous-domaine** → `portail.votre-domaine.fr`,
**dossier racine : `simplecommerce/public`**. Cochez **SSL** pour avoir le https (certificat gratuit Let's Encrypt).
La propagation prend de quelques minutes à quelques heures.

> Si vous pointez par erreur sur `simplecommerce/` au lieu de `simplecommerce/public`, le fichier `.htaccess` de sécurité
> redirige vers `public/` et bloque l'accès au code et à la configuration. Corrigez quand même le dossier racine.

### 5. Version de PHP

Le fichier `.ovhconfig` fourni demande PHP 8.3. Placez-le à la racine de votre hébergement (à côté de `www/`) s'il n'y en a pas
déjà un ; s'il en existe un, mettez simplement `app.engine.version=8.3` (et `environment=production`) dedans.

### 6. Installer

Ouvrez `https://portail.votre-domaine.fr` : la page d'installation vérifie l'hébergement, puis demande la base de données et
votre compte administrateur. Les clés de chiffrement sont créées automatiquement dans `config.php`.

- Faites l'installation **juste après l'envoi des fichiers** : tant qu'elle n'est pas faite, la page reste ouverte à tous.
- **Téléchargez une copie de `config.php`** (FTP) et gardez-la en lieu sûr : sans ses clés, les accès enregistrés de vos clients
  ne peuvent plus être relus (il faudrait les ressaisir).

### 7. Publication programmée

Onglet **Plus → Tâches planifiées - Cron** → **Ajouter une planification** :
- commande : `simplecommerce/bin/cron.php`
- langage : PHP 8.3
- fréquence : toutes les heures (ou plus souvent si votre offre le permet).

En secours, le portail publie aussi les brouillons échus pendant les visites, au plus une fois par minute.

### 8. E-mails

Les e-mails (liens de connexion, invitations, avis de modification) partent avec la fonction `mail()` de l'hébergement.
Indiquez à l'installation une adresse de votre domaine (ex. `ne-pas-repondre@votre-domaine.fr`) pour éviter les indésirables.
Si un e-mail n'arrive pas, le portail affiche le lien d'invitation à copier.

### 9. Mettre à jour plus tard

Renvoyez les dossiers `src/`, `templates/`, `public/`, `vendor/` et `bin/` par FTP. Ne touchez ni à `config.php` ni à `storage/`.

## Acheter le nom de domaine de Simple Commerce

Quand vous êtes prêt : espace client OVH → **Web Cloud → Noms de domaine → Commander** (par exemple `simplecommerce.fr`
s'il est libre, ou une variante). Une fois acheté :

1. **Multisite → Ajouter un domaine** → choisissez le nouveau domaine, dossier racine `simplecommerce/public`, cochez SSL ;
   OVH ajoute lui-même les réglages DNS si le domaine est chez eux.
2. Dans `config.php`, remplacez la ligne `'url' => …` par la nouvelle adresse (`https://www.simplecommerce.fr`).
3. Les comptes, sites et accès de vos clients restent les mêmes.

## Autres hébergeurs

N'importe quel hébergement avec PHP 8.1+, MySQL/MariaDB et Apache (`.htaccess`) convient : o2switch, Hostinger, IONOS,
Infomaniak, LWS, PlanetHoster… Le principe est identique : envoyer les fichiers, faire pointer le domaine sur `public/`,
ouvrir l'adresse pour installer, ajouter la tâche planifiée `php bin/cron.php`.
Sous Nginx, faites pointer la racine sur `public/` et renvoyez toutes les adresses vers `index.php`.

## Sécurité

- Identifiants des sites (jetons Shopify, GitHub, mots de passe FTP/SFTP…) chiffrés en base (XChaCha20-Poly1305, clé dans
  `config.php` hors du dossier public, liée à chaque site), jamais renvoyés au navigateur ni écrits dans le journal.
- Isolation entre clients : chaque page et chaque action vérifie l'accès au site ; un site inaccessible répond « page introuvable ».
- Tous les appels aux services externes partent du serveur ; adresses internes refusées (protection SSRF) ; empreinte SFTP mémorisée.
- Photos : vrai type vérifié par le contenu (JPEG, PNG, WebP), 8 Mo maximum, réencodées en WebP (métadonnées et position GPS retirées).
- Formulaires protégés contre la falsification (jeton CSRF), cookies HttpOnly/SameSite, en-têtes de sécurité (CSP…),
  limitation des tentatives de connexion, « Rester connecté » par jeton haché de 30 jours.

## Essayer sur son ordinateur

```
SC_DEMO=1 SC_ALLOW_PRIVATE=1 php -S localhost:8080 -t public public/index.php
```

Puis http://localhost:8080 — comptes de démonstration affichés sur la page de connexion. Effacer `storage/` remet tout à zéro.

Tests : `php tests/run.php`.
