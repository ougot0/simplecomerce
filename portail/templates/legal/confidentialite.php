<?php include __DIR__ . '/_head.php'; ?>
<div class="prose legal">
<h2>Qui est responsable de vos données ?</h2>
<p><?= $L('nom') ?>, <?= $L('adresse') ?> — contact : <a href="mailto:<?= $L('email') ?>"><?= $L('email') ?></a>.</p>

<h2>Quelles données, et pourquoi ?</h2>
<ul>
    <li><strong>Votre compte</strong> : adresse e-mail, nom, mot de passe (enregistré sous une forme illisible). Pour vous permettre de vous connecter. Base : l'exécution du contrat.</li>
    <li><strong>Les accès de vos sites</strong> (accès à l'hébergement, clés de connexion) : chiffrés, jamais réaffichés. Pour appliquer les modifications que vous demandez. Base : l'exécution du contrat.</li>
    <li><strong>Le contenu de vos sites et l'historique des modifications</strong> : pour publier, annuler et sauvegarder. Base : l'exécution du contrat.</li>
    <li><strong>Votre abonnement</strong> : état de l'abonnement et identifiants Stripe. Les données de carte bancaire sont traitées uniquement par Stripe, jamais par nous. Base : l'exécution du contrat et nos obligations comptables.</li>
    <li><strong>Le journal de sécurité</strong> : connexions et actions importantes, pour protéger votre compte. Base : notre intérêt légitime à sécuriser le service.</li>
    <li><strong>Les e-mails</strong> : liens de connexion, invitations, alertes (stock, commandes, avis). Vous pouvez couper les avis facultatifs dans « Mon compte ».</li>
</ul>

<h2>Les visiteurs des sites de nos clients</h2>
<p>Quand un client utilise les commandes, les avis ou la liste « Prévenez-moi », Simple Commerce conserve, pour le compte de ce client, les informations que ses visiteurs envoient (nom, e-mail, téléphone, adresse, message, avis). Le client est responsable de ces données ; Simple Commerce agit comme sous-traitant et ne s'en sert pour rien d'autre.</p>
<p>Les statistiques de visite ne comptent que des nombres par jour (pages vues, provenance) : <strong>aucun cookie, aucune adresse IP conservée</strong>, aucun moyen de reconnaître un visiteur.</p>

<h2>Cookies</h2>
<p>Le portail utilise uniquement des cookies nécessaires à son fonctionnement : le cookie de session et, si vous cochez « Rester connecté », un cookie qui garde votre connexion. Aucun cookie publicitaire ni de mesure d'audience : aucun consentement n'est donc demandé.</p>

<h2>Qui reçoit vos données ?</h2>
<ul>
    <li><strong>OVH SAS</strong> (hébergement, en France).</li>
    <li><strong>Stripe</strong> (paiements) — ses propres règles : <a href="https://stripe.com/fr/privacy" rel="noopener">stripe.com/fr/privacy</a>.</li>
    <li>Les services où se trouve votre site (votre hébergeur, Shopify, WordPress…), uniquement pour y publier vos modifications.</li>
</ul>
<p>Vos données ne sont jamais vendues ni utilisées à des fins publicitaires.</p>

<h2>Combien de temps ?</h2>
<p>Tant que votre compte existe. Après suppression du compte, les données sont effacées, sauf les informations de facturation que la loi impose de garder 10 ans. Les sauvegardes automatiques des sites sont gardées 14 jours.</p>

<h2>Vos droits</h2>
<p>Vous pouvez accéder à vos données, les corriger, les supprimer, les récupérer ou vous opposer à leur utilisation, en écrivant à <a href="mailto:<?= $L('email') ?>"><?= $L('email') ?></a>. Vous pouvez aussi saisir la CNIL (<a href="https://www.cnil.fr" rel="noopener">www.cnil.fr</a>).</p>

<h2>Sécurité</h2>
<p>Connexion chiffrée (https), mots de passe et accès des sites chiffrés, chaque client ne voit que ses propres sites, tentatives de connexion limitées.</p>
</div>
