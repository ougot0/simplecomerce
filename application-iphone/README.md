# L'application iPhone de Simple Commerce

Ce dossier contient l'application iPhone de Simple Commerce. Elle ouvre votre portail
**https://simplecommercess.com** comme une vraie application, avec en plus ce qu'un téléphone sait faire :

- un écran clair quand il n'y a pas d'Internet, avec un bouton « Réessayer » ;
- tirer la page vers le bas pour l'actualiser ;
- l'appareil photo pour prendre les produits en photo directement ;
- l'impression des bons de commande et des factures ;
- le partage des fichiers (sauvegardes, export des commandes) ;
- les liens vers les sites des clients qui s'ouvrent dans Safari.

**Vous n'avez besoin ni d'un Mac ni d'installer quoi que ce soit.** L'application est fabriquée par GitHub,
sur un Mac en ligne, à chaque modification. Tout ce que vous ferez se passe dans votre navigateur.

Dans l'application, **rien ne parle du paiement de l'abonnement** : Apple l'interdit (sinon, il prend jusqu'à
30 %). Vos clients s'abonnent sur le site, puis utilisent l'application.

---

## Ce que vous pouvez faire dès maintenant (sans compte Apple validé)

### 1. Voir l'application

Sur votre iPhone, ouvrez **simplecommercess.com** dans Safari : c'est exactement ce que montre l'application.

### 2. Cliquer dans la vraie application, depuis votre ordinateur (gratuit)

1. Ouvrez **github.com/ougot0/simplecomerce**, onglet **Actions**, puis **Application iPhone**.
2. Cliquez sur la dernière ligne avec une coche verte.
3. En bas, dans **Artifacts**, téléchargez **apercu-iphone**. Vous obtenez un fichier `apercu-iphone.zip`.
   (Si vous obtenez un dossier, prenez le fichier `apercu-iphone.zip` qui est dedans.)
4. Allez sur **appetize.io**, créez un compte gratuit, puis cliquez sur **Upload** et choisissez ce fichier.
5. Cliquez sur **Play** : un iPhone apparaît dans votre navigateur avec l'application dedans.

L'offre gratuite d'Appetize donne 30 minutes par mois, par séances de 3 minutes. C'est suffisant pour essayer.

Vous trouverez aussi, au même endroit, **photo-application** : une photo de l'application ouverte sur un iPhone
virtuel, prise automatiquement à chaque construction.

### 3. Mettre à jour le portail chez OVH

L'application a besoin de la nouvelle version du portail : bouton **Supprimer mon compte**, exigé par Apple,
et paiement caché dans l'application. Renvoyez le dossier du portail chez OVH, comme d'habitude.

### 4. Créer un compte de démonstration pour Apple

Apple teste chaque application avant de l'accepter. Il lui faut un compte pour se connecter :
1. Sur votre portail, créez un compte, par exemple `apple.test@simplecommercess.com`, avec un mot de passe simple.
2. Reliez-lui un petit site d'exemple : un catalogue, avec 2 ou 3 produits.
3. Dans **Administration → Abonnements**, cliquez sur **Offrir** sur sa ligne : il pourra publier sans payer.

---

## Quand Apple a validé votre compte développeur

### Étape A : réserver le nom de l'application (5 minutes)

1. Allez sur **developer.apple.com/account**, puis **Certificates, IDs & Profiles**, puis **Identifiers**, puis **+**.
   Choisissez **App IDs**, puis **App**. Description : `Simple Commerce`.
   Bundle ID, en mode **Explicit** : `com.simplecommercess.app`. Validez.
2. Allez sur **appstoreconnect.apple.com**, puis **Apps**, puis **+**, puis **Nouvelle app** :
   - plateforme : **iOS** ;
   - nom : **Simple Commerce**. S'il est déjà pris, essayez **Simple Commerce – Mon site** ;
   - langue principale : **Français** ;
   - identifiant de lot : `com.simplecommercess.app` ;
   - UGS : `simplecommerce-ios`.

### Étape B : relier GitHub à Apple (10 minutes, une seule fois)

1. Dans **App Store Connect**, ouvrez **Utilisateurs et accès**, puis **Intégrations**, puis **Clés App Store Connect**.
   Cliquez sur **Générer une clé**. Nom : `GitHub`. Accès : **Admin**.
2. **Téléchargez la clé**, un fichier `AuthKey_XXXX.p8`. Apple ne la donne **qu'une seule fois** : gardez-la précieusement.
   Notez aussi l'**ID de la clé** et l'**ID de l'émetteur**, affichés sur la même page.
3. Sur **developer.apple.com/account**, dans **Membership details**, notez votre **Team ID** (10 caractères).
4. Sur **github.com/ougot0/simplecomerce**, ouvrez **Settings**, puis **Secrets and variables**, puis **Actions**,
   puis **New repository secret**. Créez ces 4 secrets :

   | Nom du secret | Ce qu'il faut coller |
   |---|---|
   | `APPLE_TEAM_ID` | le Team ID |
   | `APPLE_CLE_ID` | l'ID de la clé |
   | `APPLE_EMETTEUR_ID` | l'ID de l'émetteur |
   | `APPLE_CLE_P8` | tout le contenu du fichier `.p8` (ouvrez-le avec le Bloc-notes, puis copiez tout) |

Ne collez jamais ces informations dans une conversation : seulement dans GitHub.

### Étape C : envoyer l'application à Apple (1 clic)

1. Sur GitHub, ouvrez **Actions**, puis **Application iPhone**, puis **Run workflow**.
2. Choisissez la branche, cochez **Envoyer à Apple**, puis **Run workflow**.
3. Après 10 à 30 minutes, l'application apparaît dans **App Store Connect**, dans **TestFlight**.
4. Installez l'application **TestFlight** sur votre iPhone : vous pouvez tester Simple Commerce comme une vraie
   application, avant tout le monde.

### Étape D : la publier sur l'App Store

Dans **App Store Connect**, ouvrez votre app, puis la version **1.0**. Remplissez les champs avec les textes
du fichier [FICHE-APP-STORE.md](FICHE-APP-STORE.md) et ajoutez les images du dossier `captures/`.
Choisissez la construction envoyée à l'étape C, puis cliquez sur **Soumettre pour vérification**.
Apple répond en général en 1 à 3 jours.

---

## Pour un développeur

- **Code :** `SimpleCommerce/*.swift` (UIKit et WKWebView, iOS 15 minimum). L'adresse du portail se trouve dans `Reglages.swift`.
- **Projet :** `project.yml`, pour XcodeGen ; le projet Xcode n'est pas enregistré dans le dépôt. Sur un Mac :
  `brew install xcodegen && xcodegen generate && open SimpleCommerce.xcodeproj`.
- **Construction :** `.github/workflows/application-iphone.yml`. Elle prend le Xcode le plus récent du Mac de GitHub
  et gère la signature automatiquement, avec la clé App Store Connect. Le numéro de construction est celui de la construction GitHub.
- **Reconnaissance par le portail :** l'application ajoute `SimpleCommerceApp/<version>` à l'identité du navigateur.
  Le portail (`Req::inApp()`) cache alors les boutons et liens de paiement, et le parrainage. Cette marque ne donne aucun droit.
- **Pistes :** notifications « nouvelle commande » (il faut une clé APNs et un envoi depuis le portail) ; liens
  universels, pour que les liens de connexion reçus par e-mail ouvrent l'application (il faut le Team ID).
