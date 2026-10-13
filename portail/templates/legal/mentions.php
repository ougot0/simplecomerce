<?php include __DIR__ . '/_head.php'; ?>
<div class="prose legal">
<h2>Éditeur du site</h2>
<p>Le site <?= $site ?> et le service Simple Commerce sont édités par :<br>
<strong><?= $L('nom') ?></strong><br>
<?= $L('statut') ?><br>
<?= $L('adresse') ?><br>
SIRET : <?= $L('siret') ?><br>
<?= $L('tva') ?><br>
E-mail : <a href="mailto:<?= $L('email') ?>"><?= $L('email') ?></a><?= $info['telephone'] !== '' ? '<br>Téléphone : ' . $L('telephone') : '' ?></p>
<p>Directeur de la publication : <?= $L('directeur') ?>.</p>

<h2>Hébergement</h2>
<p>Le site est hébergé par <strong>OVH SAS</strong>, 2 rue Kellermann, 59100 Roubaix, France — téléphone : 1007 — <a href="https://www.ovhcloud.com" rel="noopener">www.ovhcloud.com</a>.<br>
Les données sont stockées en France.</p>

<h2>Paiements</h2>
<p>Les paiements des abonnements sont traités par <strong>Stripe Payments Europe Ltd.</strong>, 1 Grand Canal Street Lower, Dublin 2, Irlande. Simple Commerce n'a jamais accès aux numéros de carte bancaire.</p>

<h2>Propriété intellectuelle</h2>
<p>Le nom « Simple Commerce », son logo, ses textes et son logiciel sont la propriété de l'éditeur. Toute reproduction sans autorisation est interdite. Les contenus des sites des clients (textes, photos, produits) restent la propriété de chaque client.</p>

<h2>Contact</h2>
<p>Pour toute question : <a href="mailto:<?= $L('email') ?>"><?= $L('email') ?></a>.</p>
</div>
