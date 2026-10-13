<?php
use SimpleCommerce\Services\Legal;
/** @var array $info */
?>
<div class="page-head"><div><h1>Informations légales</h1><p class="page-intro"><a href="/admin">Administration</a> · Ces informations sont obligatoires : elles remplissent vos <a href="/mentions-legales">mentions légales</a>, <a href="/conditions-generales-utilisation">CGU</a>, <a href="/conditions-generales-vente">CGV</a> et votre <a href="/confidentialite">politique de confidentialité</a>.</p></div></div>
<?php if (!Legal::complete()): ?><div class="notice notice-warn"><p>Tant que ces champs sont vides, vos pages légales affichent « [à compléter] ».</p></div><?php endif ?>
<form method="post" action="/admin/informations-legales" class="form panel narrow-block"><?= csrf_field() ?>
    <?php foreach (Legal::FIELDS as $k => [$label, $help]): ?>
    <div class="field"><label for="legal-<?= e($k) ?>"><?= e($label) ?></label>
        <input id="legal-<?= e($k) ?>" name="<?= e($k) ?>" type="text" maxlength="300" value="<?= e(json_decode((string) \SimpleCommerce\Repo::meta('legal_info', '{}'), true)[$k] ?? '') ?>">
        <?php if ($help !== ''): ?><span class="help"><?= e($help) ?></span><?php endif ?></div>
    <?php endforeach ?>
    <p class="help">E-mail de contact affiché : <?= e($info['email']) ?>. Hébergeur (OVH) et prestataire de paiement (Stripe) sont déjà indiqués.</p>
    <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
</form>
<p class="muted small mt-12">Ces textes sont des modèles sérieux mais généraux : faites-les relire par un juriste ou un expert-comptable si votre activité a des particularités.</p>
