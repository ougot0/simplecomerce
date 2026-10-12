# Tester Simple Commerce, pas à pas

Ce guide sert une fois le portail installé sur votre hébergement OVH (voir `portail/README.md`).
Vous êtes connecté avec votre compte **administrateur**. Le portail est **privé** : personne d'autre ne peut entrer.

À chaque étape : notez ce qui ne va pas et faites une **capture d'écran**.

## 1. Relier un site — une façon par type de site

Menu **Administration → Relier un site**, puis choisissez le type. Pour chaque essai :

1. remplissez le formulaire (la colonne de droite dit où trouver les accès) ;
2. cliquez sur **Tester la connexion** : toutes les lignes doivent être « OK » ;
3. cliquez sur **Relier mon site** ;
4. ouvrez une rubrique, changez un prix ou un texte, ajoutez une photo, puis **Publier sur mon site** ;
5. vérifiez sur le vrai site (rafraîchissez la page) que le changement est là.

| Type | Ce qu'il vous faut | Remarque |
|---|---|---|
| **Hébergeur (FTP)** | serveur, identifiant, mot de passe FTP (OVH : onglet « FTP-SSH ») | le site doit avoir son contenu dans un fichier (ex. `content.json`) |
| **Hébergeur (SFTP)** | mêmes accès, en SFTP (OVH offres Pro/Performance) | plus sûr que le FTP |
| **WordPress / WooCommerce** | adresse du site, identifiant, **mot de passe d'application** (Profil → tout en bas) | le site doit être en https |
| **Shopify** | adresse `…myshopify.com`, **Client ID** et **Client secret** d'une application créée dans le Dev Dashboard de Shopify | depuis 2026, Shopify ne donne plus de jeton `shpat_` aux nouvelles applications ; un ancien jeton reste accepté (réglages avancés) |
| **Webflow** | jeton d'accès du site (droits Sites, CMS, Assets) | seules les collections du CMS sont modifiables |
| **GitHub / GitLab / Bitbucket** | adresse du dépôt + jeton d'accès en écriture | le site se met à jour 1 à 2 minutes après |
| **Propre base de données** | adresse du point d'accès + clé secrète, fournies par le développeur | voir `docs/API-SUR-MESURE.md` |

Pour vos premiers essais FTP/SFTP, le plus simple est un petit site de test sur votre OVH,
avec un fichier `content.json` (exemple : `portail/demo/sites/patisserie-lune/content.json`).

## 2. Les options, sur un site relié

- **Brouillon** : « Enregistrer sans publier », puis menu Brouillons → « Publier maintenant ».
- **Programmer** : « Programmer… », choisir une heure ; la tâche planifiée OVH (toutes les heures) la publie.
- **Historique** : annuler une modification, vérifier sur le site que l'ancienne version revient.
- **Masquer / Afficher / Dupliquer** un produit depuis la liste.
- **Fermer le site temporairement** (Réglages → Fermeture), puis le rouvrir.
- **Sauvegarde** : « Télécharger une sauvegarde » sur la page d'accueil du site.

## 3. Les comptes

1. Réglages d'un site → **Inviter un collègue** avec une autre adresse e-mail à vous.
2. Ouvrez le lien (dans l'e-mail, ou celui affiché à l'écran) dans une **fenêtre de navigation privée**, créez le compte.
3. Vérifiez que ce compte **ne voit que ce site** et pas l'administration.
4. Dans Administration, essayez **« Agir pour ce client »** puis « Revenir à mon compte ».
5. Testez **« Mot de passe oublié »** (lien de connexion par e-mail). Si l'e-mail n'arrive pas, regardez les indésirables.

## 4. Si quelque chose ne marche pas

- Le message à l'écran dit en général quoi faire.
- Administration → **Journal d'activité** montre ce qui s'est passé.
- Envoyez la capture d'écran : avec le message exact, on corrige vite.
