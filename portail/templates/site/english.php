<?php
/** @var array $state @var bool $ready @var string $base @var array $site */
use SimpleCommerce\Support\Text;
?>
<div class="page-head"><div><h1>Version anglaise</h1>
    <p class="page-intro">Vos pages sont traduites en anglais et ajoutées à votre site, à côté des pages en français. Un petit bouton « English » apparaît en bas de chaque page, et « Français » sur les pages anglaises.</p></div></div>
<?php if ($state['on']): ?>
<div class="notice notice-ok"><p><strong>Votre site existe en anglais</strong> (<?= e(Text::plural((int) $state['pages'], 'page')) ?><?= $state['at'] ? ', mis à jour le ' . e(Text::date(substr((string) $state['at'], 0, 10))) : '' ?>).
    Quand vous changez un texte en français, la page anglaise ne suit pas toute seule : cliquez sur « Mettre à jour l'anglais ».</p></div>
<?php endif ?>
<?php if (!$ready): ?>
<div class="notice notice-warn"><p>La traduction automatique n'est pas encore activée sur ce portail. Écrivez-nous depuis « Besoin d'aide ? ».</p></div>
<?php else: ?>
<section class="section-block">
    <form method="post" action="<?= e($base) ?>/anglais" class="panel" data-english>
        <?= csrf_field() ?><input type="hidden" name="do" value="tout">
        <p><?= $state['on'] ? 'Seuls les textes nouveaux ou modifiés sont traduits : c\'est rapide.' : 'Comptez une à quelques minutes selon le nombre de pages. Vous pourrez relire et corriger les pages anglaises comme les autres, ou tout retirer en un clic.' ?></p>
        <div class="progress" data-english-progress hidden><div class="progress-bar"><span data-english-bar></span></div><p class="help" data-english-status role="status"></p></div>
        <div class="actions"><button class="btn btn-primary" type="submit" data-busy="Traduction…"><?= icon('globe') ?> <?= $state['on'] ? 'Mettre à jour l\'anglais' : 'Créer la version anglaise' ?></button></div>
    </form>
</section>
<?php endif ?>
<?php if ($state['on']): ?>
<section class="section-block">
    <h2>Retirer</h2>
    <form method="post" action="<?= e($base) ?>/anglais" data-confirm="Retirer la version anglaise ? Les pages anglaises seront supprimées de votre site et le bouton « English » disparaîtra."><?= csrf_field() ?><input type="hidden" name="do" value="retirer">
        <p class="muted">Supprime les pages anglaises créées par Simple Commerce. Vos pages en français ne changent pas.</p>
        <button class="btn btn-small btn-danger" type="submit">Retirer la version anglaise</button></form>
</section>
<?php endif ?>
