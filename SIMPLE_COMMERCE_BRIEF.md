# Simple Commerce — fiche de présentation pour un teaser vidéo

> Fiche écrite à partir du code, des fichiers et de la documentation du projet (dépôt `ougot0/simplecomerce`).
> Rien n'est inventé : quand une information n'existe pas dans le projet, elle est marquée « non défini ».

---

## 1. Identité

| | |
|---|---|
| **Nom** | Simple Commerce |
| **Slogan** | « Modifiez votre site vous-même, sans développeur. » (titre de la page d'accueil) |
| **Sous-titre App Store** | « Modifiez votre site vous-même » |
| **Phrase d'accroche (App Store)** | « Changez un prix, une photo ou un horaire depuis votre téléphone : c'est en ligne sur votre site tout de suite. » |
| **Description courte** | Simple Commerce est l'espace où les commerçants et les artisans changent eux-mêmes les produits, les prix, les photos et les textes de leur site. « Vous modifiez ici, votre site suit. » |
| **Forme** | Un portail web (navigateur) et une application iPhone. L'application ouvre le même portail. |
| **Statut** | Le portail web est en ligne sur simplecommercess.com. L'application iPhone version 1.0 est **en attente de vérification par Apple** (App Store seulement, pas de Play Store). |
| **Date de sortie** | non défini |
| **Prix** | Application gratuite à télécharger. Le portail prévoit un abonnement de **9 €/mois ou 90 €/an**, avec **la première année gratuite**. Les abonnements sont éteints par défaut et se règlent dans l'administration. |
| **Site** | https://simplecommercess.com |
| **Aide** | https://simplecommercess.com/aide |
| **Confidentialité** | https://simplecommercess.com/confidentialite |
| **Contact** | simplecommercess@gmail.com |
| **Réseaux sociaux** | non défini |
| **Lien App Store** | non défini (application pas encore publiée) |

---

## 2. Public et problème résolu

**Pour qui :** les commerçants et artisans **non techniques** qui ont déjà un site internet : boutique, pâtisserie, atelier, architecte d'intérieur… Le projet vise aussi le développeur qui crée des sites pour eux, et qui ne veut pas avoir à changer les produits à leur place.

**Le problème :**
- Pour changer un prix, une photo ou un horaire sur son site, un commerçant dépend souvent de la personne qui l'a construit.
- Les sites sont hébergés partout et construits de mille façons. Il n'y a pas d'outil unique pour les modifier.

**La réponse de Simple Commerce :**
- Le commerçant **relie son site une seule fois** (avec les accès de son hébergeur).
- Le portail **retrouve tout seul** ses produits, ses prix et les textes de ses pages.
- Il modifie ensuite tout dans des formulaires simples et appuie sur **« Publier sur mon site »**.

Ça marche avec le site **tel qu'il est**, hébergé n'importe où :
- chez un hébergeur : OVH, o2switch, Hostinger, IONOS, Infomaniak, LWS, PlanetHoster ;
- sur une plateforme : WordPress/WooCommerce, Shopify, Webflow, GitHub, GitLab, Bitbucket, Netlify ;
- ou avec une API sur mesure.

Pour un site qui ne peut pas être relié, il reste une solution : **« Gérer mes produits ici, puis les afficher sur mon site »**. Le catalogue est alors gardé par Simple Commerce et s'affiche sur le site.

**Ton de la marque :**
- Français simple, vouvoiement, phrases courtes, **aucun jargon**.
- Des boutons qui disent ce qu'ils font : « Publier sur mon site », « Enregistrer sans publier », « Relier mon site », « Tester la connexion ».

---

## 3. Fonctionnalités

### Le cœur
- Ajouter, modifier, supprimer des produits, des projets ou des formules. Changer un prix ou un stock.
- **Photos** : prises avec le téléphone ou envoyées. Elles sont recadrées, allégées, tournées dans le bon sens, et les informations de localisation sont retirées.
- **Textes de chaque page** du site, modifiables un par un. **Logo du site** remplacé sur toutes les pages d'un coup.
- **Publier tout de suite**, **enregistrer sans publier** (brouillon) ou **programmer** à une date et une heure.
- **Masquer / Afficher** un produit en un clic, **Dupliquer**, **modifier plusieurs produits d'un coup**.
- **Filtres** dans les listes (par exemple « Soldes », « Nouveautés », catégories) et recherche.
- **Historique** de chaque modification et **annulation en un clic**.
- **« Fermer le site temporairement »**, avec un message et une date de réouverture.
- **Horaires et congés programmés**.
- **Inviter un collègue**, sur le site choisi seulement.
- Aperçu en direct et **aperçu « Sur téléphone »**.

### La boutique
- **Commandes** : sans paiement en ligne, le commerçant règle avec son client. Le stock est retiré à la commande et remis si elle est annulée.
- Bon de commande et facture imprimables.
- **Alerte de stock bas** et liste d'attente **« Prévenez-moi »** : le client reçoit un e-mail au retour en stock.
- **Pastilles** et produits mis en avant.
- **Avis clients**, relus avant d'être affichés.
- **Prise de rendez-vous** : un onglet « Rendez-vous » est **ajouté tout seul au menu du site**.
- Onglets « Avis » et « Horaires » ajoutés de la même façon.
- **Statistiques de visites** sans Google, sans cookie et sans adresse IP.
- Résumé de la semaine par e-mail, chaque lundi.

### Les options intelligentes
- **« Écrire pour moi »** : rédaction automatique d'une description de produit (écrire, améliorer, raccourcir). Ça marche avec une clé Claude ajoutée par l'administrateur.
- **Version anglaise du site** fabriquée automatiquement, avec un bouton « English / Français » sur le site.

### Sécurité
- Accès des sites **chiffrés**, jamais réaffichés : seule une empreinte « …a3F9 » est montrée.
- Chaque client ne voit que ses propres sites.
- Sauvegardes automatiques chaque nuit.

### Pour l'administrateur du portail
- Journal d'activité, « agir pour » un client.
- Abonnements Stripe, tableau des revenus, parrainage (un mois offert).
- **Options du portail** : 8 interrupteurs pour activer ou désactiver des fonctions pour tous.
- **Studio** (`/studio`) : il change le nom affiché, les 4 couleurs du portail et un message pour tous les clients. Les changements restent en brouillon, visibles seulement par lui dans un aperçu, jusqu'à « Publier pour tous ».

### 3 à 5 moments forts visuels (pour le teaser)
1. **Le bouton noir « Publier sur mon site »** : un prix change dans le formulaire, un clic, et le vrai site est à jour. C'est le cœur de la promesse.
2. **La liste de produits de la page d'accueil** : « Tarte au citron 4,50 € », « Éclair au café 3,80 € », « Fraisier — Masqué ». On y voit les pastilles d'état (« En ligne » noire, « Masqué » grise bordée).
3. **La photo prise avec le téléphone** qui apparaît recadrée sur la fiche produit, puis sur le site.
4. **L'onglet « Rendez-vous » qui apparaît tout seul** dans le menu du site du client, sans toucher au code.
5. **« Fermer le site temporairement »** : un message de vacances en ligne en un geste. Ou bien **l'annulation en un clic** dans l'historique.

---

## 4. Parcours utilisateur, écran par écran

1. **Page d'accueil publique** (simplecommercess.com).
   - Titre : « Modifiez votre site vous-même, sans développeur. »
   - Une illustration de liste de produits avec le bouton « Publier sur mon site ».
   - Les sections : « Comment ça marche » (1. Vous reliez votre site · 2. Vous modifiez · 3. Vous publiez), « Ce que vous pouvez faire », « Avec votre site, tel qu'il est », « Vos accès restent protégés », « Tout reste sur votre site », « Prêt à essayer ? ».
   - Le bouton « Créer un compte ».
2. **Créer un compte** : adresse e-mail, mot de passe, « Rester connecté », et la case « J'accepte les conditions ». La connexion est aussi possible par un lien reçu par e-mail.
3. **Mes sites** : la liste des sites reliés, et le bouton pour en relier un nouveau.
4. **Relier un site (l'assistant)**.
   - On choisit le type de site : hébergeur, plateforme, ou « Gérer mes produits ici ».
   - Les hébergeurs connus pré-remplissent le formulaire et disent où trouver les accès.
   - **« Tester la connexion »** affiche une liste de vérifications avec des pastilles noires « OK » ou « À revoir ».
   - Puis **« Relier mon site »**.
5. **Le tableau de bord du site** : menu latéral noir avec les rubriques trouvées automatiquement (Produits, Textes de la page d'accueil, Logo du site, Ouverture du site…), et Commandes, Avis, Rendez-vous, Statistiques, Réglages.
6. **La liste des produits** : recherche, filtres (par exemple Soldes, Nouveautés), pastilles d'état, « Modifier plusieurs », et un bouton pour ajouter un produit.
7. **La fiche produit** : nom, prix, stock, photo, description (avec « Écrire pour moi »). En bas : **« Publier sur mon site »**, « Enregistrer sans publier », « Programmer… ».
8. **Après publication** : un message de confirmation, et l'historique avec la possibilité d'annuler.
9. **Sur iPhone** : le même parcours dans l'application. Les 4 captures officielles montrent la Connexion, Mon site, Mes produits et Modifier un produit.

---

## 5. Identité visuelle

**Direction :** « beige, noir, blanc, couleurs classiques, moderne, compréhensible » (demande du propriétaire). C'est une ambiance sobre, artisanale et calme. Cartes blanches avec une ombre légère, menu latéral noir, boutons principaux noirs en forme de pilule.

### Couleurs (variables CSS de `portail/public/assets/app.css`)

| Rôle | Hex |
|---|---|
| Fond beige clair | `#f4efe6` |
| Cartes | `#ffffff` |
| Surface secondaire | `#faf7f2` |
| Beige | `#ebe3d5` |
| Beige soutenu | `#d9ccb6` |
| Lignes | `#e4dccf` |
| Lignes soutenues | `#cfc4b2` |
| Encre (texte, menu, boutons) | `#141210` |
| Texte secondaire | `#4b453e` |
| Texte discret | `#8b8377` |
| Texte sur fond noir | `#ffffff` |
| Erreurs et suppressions **seulement** | `#a12d1f` (fond `#fbeeec`) |

- Arrondis : 12 px (cartes), 8 px (petits éléments).
- Ombre : `0 1px 2px rgba(20,18,16,.04), 0 4px 16px rgba(20,18,16,.04)`.

**Pastilles d'état :**
- « En ligne » : noire ;
- « Brouillon » et « Programmé » : beiges ;
- « Masqué » : grise bordée ;
- « Échec » : rouge.

### Police
- **Figtree**, en graisses 400, 500, 600 et 700. Elle est servie par le portail lui-même : `portail/public/assets/fonts/figtree-latin-*-normal.woff2`.

### Logo
- Un carré noir arrondi, avec les lettres « SC » en crème et une flèche de souris. Il est affiché devant le mot « Simple Commerce ».
- `portail/public/assets/logo-512.png`
- `portail/public/assets/logo-96.png`
- `portail/public/assets/favicon-32.png`
- `portail/public/assets/apple-touch-icon.png`
- `portail/public/assets/icon.svg`
- Icône de l'application iPhone : `application-iphone/SimpleCommerce/Assets.xcassets/AppIcon.appiconset/`.

### Captures d'écran (iPhone, 1320×2868)
- `application-iphone/captures/1-connexion.png`
- `application-iphone/captures/2-mon-site.png`
- `application-iphone/captures/3-mes-produits.png`
- `application-iphone/captures/4-modifier-un-produit.png`
- Mêmes écrans pour l'iPhone 6,3 pouces : `application-iphone/captures-6.3/`.

**Captures du portail sur ordinateur :** non défini (aucune dans le projet). On peut les faire avec la démonstration, voir la section 6.

### À éviter absolument
- Dégradés violets.
- Polices Inter, Geist ou Space Grotesk.
- Petit label en MAJUSCULES au-dessus d'un grand titre centré.
- Animations superflues, faux témoignages, **emojis**.
- Tout jargon (« API », « JSON », « token », « dépôt »…).

---

## 6. Technique (court)

- **Portail** :
  - PHP 8.1+, sans framework, avec SQLite (ou MySQL). Hébergé chez OVH, en hébergement mutualisé.
  - CSS et JavaScript écrits à la main, sans bibliothèque externe ni Google Fonts.
- **Application iPhone** :
  - Swift/UIKit avec une fenêtre web qui ouvre simplecommercess.com. Elle est construite sur GitHub Actions.
  - Fonctions natives : appareil photo pour les produits, impression des factures, partage de fichiers, écran hors connexion, tirer pour actualiser.
- **Connecteurs** : SFTP, FTP/FTPS, GitHub, GitLab, Bitbucket, Netlify, WordPress/WooCommerce, Shopify, Webflow, API sur mesure, et catalogue hébergé.
- **Rédaction et traduction** : Claude (bibliothèque officielle d'Anthropic).
- **Paiements des abonnements** : Stripe. **E-mails** : Brevo, ou l'envoi du serveur à défaut.
- **Démonstration locale**, utile pour filmer des écrans :
  - Commande : `cd portail && SC_DEMO=1 SC_ALLOW_PRIVATE=1 php -S 127.0.0.1:8080 -t public public/index.php`.
  - Deux faux sites : « Pâtisserie Lune » et « Atelier Brun ».
  - Compte : `marie@patisserie-lune.fr` / `tarte-citron-2026`.

---

## 7. Idées pour le teaser

Ce sont des propositions, construites à partir des faits ci-dessus.

- **Format** :
  - vertical 9:16 de 20 à 30 secondes (réseaux sociaux) ;
  - plus une version 16:9 pour le site.
- **Accroche (0–3 s)**, plan serré d'un téléphone, texte à l'écran :
  - « Changer un prix sur votre site ? » ;
  - puis « Sans attendre votre développeur. ».
- **Démonstration (3–18 s)**, en trois battements comme « Comment ça marche » :
  1. *Vous reliez votre site* : l'écran « Tester la connexion » et ses pastilles « OK » qui s'allument une à une.
  2. *Vous modifiez* : « Tarte au citron 4,50 € » devient « 4,80 € », et une photo est prise au téléphone.
  3. *Vous publiez* : un doigt appuie sur le bouton noir **« Publier sur mon site »**, et le vrai site se met à jour à côté.
- **Moment « waouh » (18–24 s)** : l'onglet « Rendez-vous » apparaît tout seul dans le menu du site, ou le site passe en « Fermé pour congés » en un geste.
- **Fin (24–30 s)** :
  - le logo SC sur fond beige `#f4efe6` ;
  - le slogan « Modifiez votre site vous-même, sans développeur. » ;
  - « simplecommercess.com » et « Bientôt sur l'App Store ».
  - Mention possible : « Première année gratuite ».
- **Style** :
  - fond beige, cartes blanches, noir profond `#141210` ;
  - police Figtree ;
  - mouvements lents et nets (glissements, apparitions simples), pas d'effets tape-à-l'œil ;
  - musique douce et artisanale.
- **Personnages possibles** : une pâtissière, un artisan du bois, une créatrice. Ce sont les univers des sites de démonstration et de l'App Store. Attention : aucun vrai client n'est cité dans le projet, et il ne faut **ni faux témoignage, ni faux chiffre**.
- **Ne pas montrer** :
  - de paiement dans l'application iPhone (règle Apple) ;
  - de mots techniques ;
  - d'emojis.
