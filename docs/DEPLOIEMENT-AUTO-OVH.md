# Déploiement automatique GitHub → OVH (recette à donner à Claude Code)

> Copie-colle tout ce bloc dans une nouvelle conversation Claude Code
> (pour mettre en place le même système de mise en ligne automatique sur un autre site).

---

**Contexte : je veux un déploiement automatique « GitHub → OVH ».**
Mon site est hébergé chez OVH (hébergement mutualisé) et son code est sur GitHub.
Je veux qu'à **chaque modification poussée sur GitHub, le site se mette en ligne tout seul**
via SFTP, sans upload manuel.

**Le principe (déjà testé et qui marche) :**
1. Un fichier GitHub Actions `.github/workflows/deploy.yml` se déclenche à chaque `push`.
2. Il se connecte à OVH en **SFTP (port 22)** — ⚠️ PAS en FTPS : OVH Perso refuse le FTPS
   (erreur « 500 This security scheme is not implemented »).
3. Il copie les fichiers du site dans le **dossier du site** sur le serveur.
4. Le mot de passe OVH est stocké dans un **secret GitHub** `OVH_FTP_PASSWORD`
   (jamais en clair dans le code).

**Le workflow exact à créer** (adapter les valeurs en MAJUSCULES) :

```yaml
name: Déploiement OVH
on:
  push:
    branches: [ NOM_DE_MA_BRANCHE ]
  workflow_dispatch:
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Préparer le dossier (sans fichiers techniques)
        run: |
          mkdir -p _deploy
          rsync -a --exclude='.git' --exclude='.github' --exclude='_deploy' \
                --exclude='README.md' --exclude='images/simplecommerce' ./ _deploy/
          # Anti-cache : remplace __BUILD__ par le hash du commit dans les .html
          find _deploy -maxdepth 1 -name '*.html' -exec sed -i "s/__BUILD__/${GITHUB_SHA::7}/g" {} +
      - name: Envoyer sur OVH (SFTP)
        uses: wlixcc/SFTP-Deploy-Action@v1.2.4
        with:
          server: SERVEUR_SFTP_OVH        # ex. ftp.clusterXXX.hosting.ovh.net
          username: IDENTIFIANT_FTP       # ex. coumaoq
          password: ${{ secrets.OVH_FTP_PASSWORD }}
          port: 22
          local_path: './_deploy/*'
          remote_path: '/home/IDENTIFIANT_FTP/DOSSIER_DU_SITE'   # ex. /home/coumaoq/ougot
          sftp_only: true
```

**Où trouver les valeurs OVH :**
- Espace client OVH → l'hébergement → onglet « FTP - SSH » :
  serveur SFTP, identifiant, et « Chemin du répertoire home ».
- Le dossier du site : onglet « Mes sites » → colonne « Dossier racine ».

**Le secret GitHub (une seule fois) :**
Dépôt GitHub → Settings → Secrets and variables → Actions → New repository secret
→ Name : `OVH_FTP_PASSWORD`
→ Value : le mot de passe FTP/SFTP OVH (uniquement le mot de passe, rien d'autre).

**Astuce anti-cache (importante) :**
Dans le HTML, référencer les fichiers JS/CSS/images modifiables avec `?v=__BUILD__`,
par ex. `<script src="script.js?v=__BUILD__"></script>` ou
`<img src="photo.jpg?v=__BUILD__">`.
Le workflow remplace `__BUILD__` par le hash du commit → le navigateur recharge
toujours la dernière version (fini le problème de cache).

**Peux-tu me mettre tout ça en place pour mon site ?**

---

## Points de blocage à connaître (gain de temps)
- OVH Perso = **SFTP port 22** (le FTPS échoue).
- Le **dossier distant** doit être le bon : `/home/<identifiant>/<dossier_du_site>`
  (ne pas écraser `/www` si c'est un autre site !).
- Le premier déploiement peut « échouer » tant que le secret `OVH_FTP_PASSWORD`
  n'est pas créé : c'est normal, relancer après l'avoir ajouté
  (onglet « Actions » → « Run workflow »).
- Si « Permission denied » : le mot de passe du secret est faux → le réinitialiser
  sur OVH (onglet FTP-SSH, crayon) et le remettre dans le secret.

## ⚠️ Site client modifié par Simple Commerce ET mis en ligne par cette recette

Si un site est mis en ligne par GitHub (cette recette) **et** relié à Simple Commerce par **SFTP/FTP**, chaque
modification faite dans le portail (prix, textes, produits) est écrite directement chez OVH… puis **écrasée au
prochain envoi GitHub** par la version du dépôt (seules les photos du dossier `images/simplecommerce` sont épargnées).

Solution : relier ce site à Simple Commerce **par GitHub** (type « GitHub » dans « Relier un site », même dépôt et même
branche). Le portail enregistre alors ses modifications dans le dépôt, et c'est cette recette qui les met en ligne :
une seule source, rien n'est perdu.

Pour Simple Commerce lui-même, l'équivalent est `.github/workflows/portail-ovh.yml` (secret `OVH_FTP_PASSWORD`,
+ `OVH_SFTP_SERVEUR`).
