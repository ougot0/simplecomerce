<?php
use SimpleCommerce\Services\Bookings;
use SimpleCommerce\Services\Hours;
/** @var array $settings @var string $servicesText @var array $list @var array $hours @var string $line @var string $base @var string $role */
$s = $settings;
?>
<div class="page-head"><div><h1>Rendez-vous</h1><p class="page-intro">Vos clients réservent eux-mêmes un créneau depuis votre site. Vous et eux recevez un e-mail de confirmation ; ils peuvent annuler en un clic.</p></div></div>

<?php if ($s['enabled']): ?>
<div class="panel mb-12">
    <p><strong>Pour afficher la réservation sur votre site</strong>, collez cette ligne une seule fois à l'endroit voulu (ou demandez-le à la personne qui s'occupe de votre site).</p>
    <div class="copy-row"><input type="text" readonly value="<?= e($line) ?>" class="code" aria-label="Ligne à coller dans votre site" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div>
</div>
<?php endif ?>

<section class="section-block">
    <h2>À venir</h2>
    <?php if (!$list['upcoming']): ?><p class="muted">Aucun rendez-vous à venir.</p><?php else: ?>
    <ul class="lines">
        <?php foreach ($list['upcoming'] as $b): ?>
        <li><div class="line-main"><span class="line-title"><?= e(Bookings::when($b['starts_at'])) ?> — <?= e($b['service']) ?></span>
            <div class="muted small"><?= e($b['customer_name']) ?> · <a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a><?= $b['phone'] ? ' · <a href="tel:' . e(preg_replace('/[^\d+]/', '', $b['phone'])) . '">' . e($b['phone']) . '</a>' : '' ?></div>
            <?php if ($b['note']): ?><div class="small"><span class="muted">Message :</span> <?= nl2br(e($b['note'])) ?></div><?php endif ?></div>
            <form method="post" action="<?= e($base) ?>/rendez-vous/action" class="inline" data-confirm="Annuler ce rendez-vous ? Le client sera prévenu par e-mail."><?= csrf_field() ?><input type="hidden" name="do" value="annuler"><input type="hidden" name="id" value="<?= e($b['id']) ?>">
                <button class="btn btn-small btn-quiet" type="submit">Annuler</button></form></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>

<?php if ($role !== 'editor'): ?>
<section class="section-block" id="reglages">
    <h2>Réglages</h2>
    <form method="post" action="<?= e($base) ?>/rendez-vous/action" class="form panel"><?= csrf_field() ?><input type="hidden" name="do" value="reglages">
        <label class="checkbox"><input type="checkbox" name="enabled" value="1" <?= $s['enabled'] ? 'checked' : '' ?>><span>Ouvrir la prise de rendez-vous sur mon site</span></label>
        <div class="field"><label for="bk-services">Vos prestations</label>
            <textarea id="bk-services" name="services" rows="5" maxlength="4000" placeholder="Coupe femme | 45 | 35 €&#10;Coupe homme | 30 | 22 €&#10;Couleur | 90 | 60 €"><?= e($servicesText) ?></textarea>
            <span class="help">Une prestation par ligne : <strong>nom | durée en minutes | prix</strong> (le prix est facultatif).</span></div>
        <fieldset class="fieldset-plain"><legend>Quand vous recevez</legend>
            <p class="help">Exemple : « 9h-12h, 14h-18h ». Vide = vos horaires d'ouverture (Réglages → Horaires), ou fermé.</p>
            <?php foreach (Hours::DAYS as $i => $day): ?>
            <div class="field hours-row"><label for="bk-week-<?= $i ?>"><?= e($day) ?></label>
                <input id="bk-week-<?= $i ?>" name="week[<?= $i ?>]" type="text" maxlength="120" value="<?= e($s['week'][$i] ?? '') ?>" placeholder="<?= e($hours[$i] !== '' ? $hours[$i] : 'Fermé') ?>"></div>
            <?php endforeach ?>
        </fieldset>
        <div class="field"><label for="bk-notice">Délai minimum avant un rendez-vous</label><div class="inline-fields"><input id="bk-notice" name="notice" type="number" min="0" max="240" class="short" value="<?= (int) $s['notice'] ?>"><span>heures</span></div></div>
        <div class="field"><label for="bk-days">Réservation possible jusqu'à</label><div class="inline-fields"><input id="bk-days" name="days" type="number" min="1" max="180" class="short" value="<?= (int) $s['days'] ?>"><span>jours à l'avance</span></div></div>
        <div class="field"><label for="bk-cap">Clients reçus en même temps</label><div class="inline-fields"><input id="bk-cap" name="capacity" type="number" min="1" max="20" class="short" value="<?= (int) $s['capacity'] ?>"><span>(1 si vous êtes seul)</span></div></div>
        <div class="field"><label for="bk-msg">Message dans l'e-mail de confirmation <span class="optional">(facultatif)</span></label>
            <textarea id="bk-msg" name="message" rows="2" maxlength="300" placeholder="Exemple : merci d'arriver 5 minutes en avance. Adresse : 3 rue…"><?= e($s['message']) ?></textarea></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
</section>
<?php endif ?>

<?php if ($list['past']): ?>
<details class="mt-6"><summary class="muted">Rendez-vous passés et annulés</summary>
    <ul class="lines mt-6">
        <?php foreach ($list['past'] as $b): ?>
        <li><div class="line-main"><span class="line-title"><?= e(Bookings::when($b['starts_at'])) ?> — <?= e($b['service']) ?></span><div class="muted small"><?= e($b['customer_name']) ?><?= $b['status'] === 'annule' ? ' · ' . tag('off', 'Annulé') : '' ?></div></div></li>
        <?php endforeach ?>
    </ul>
</details>
<?php endif ?>
