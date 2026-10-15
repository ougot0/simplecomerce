<?php
/** @var array $s @var bool $pending @var bool $preview */
use SimpleCommerce\Services\Features;
use SimpleCommerce\Services\Studio;
?>
<div class="page-head"><div><h1>Studio</h1><p class="page-intro">Modifiez Simple Commerce vous-même. Vos changements restent un <strong>brouillon</strong>, visible seulement par vous dans l'aperçu, jusqu'à ce que vous cliquiez sur « Publier pour tous ».</p></div></div>
<?php if ($pending): ?>
<div class="notice notice-warn"><p><strong>Brouillon en cours</strong> : vos clients ne le voient pas encore.</p>
    <div class="actions mt-8">
        <form method="post" action="/studio" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="publier"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Publier pour tous</button></form>
        <form method="post" action="/studio" class="inline" data-confirm="Effacer le brouillon ? Rien ne changera pour vos clients."><?= csrf_field() ?><input type="hidden" name="do" value="annuler"><button class="btn btn-quiet" type="submit">Effacer le brouillon</button></form>
    </div></div>
<?php endif ?>
<div class="studio">
    <form method="post" action="/studio" class="form panel studio-form"><?= csrf_field() ?>
        <h2>Nom</h2>
        <div class="field"><label for="st-name">Nom affiché en haut des pages</label><input id="st-name" name="name" type="text" maxlength="40" value="<?= e($s['name']) ?>"></div>

        <h2>Couleurs</h2>
        <?php foreach (Studio::COLORS as $k => [$label]): ?>
        <div class="field studio-color"><label for="st-<?= e($k) ?>"><?= e($label) ?></label>
            <input id="st-<?= e($k) ?>" name="color_<?= e($k) ?>" type="color" value="<?= e($s['colors'][$k]) ?>"></div>
        <?php endforeach ?>

        <h2>Message pour tous les clients</h2>
        <div class="field"><label for="st-notice">Bandeau en haut du portail <span class="optional">(facultatif)</span></label>
            <textarea id="st-notice" name="notice" rows="2" maxlength="300" placeholder="Ex. : Nouveau ! Ajoutez un onglet Rendez-vous à votre site en un clic."><?= e($s['notice']) ?></textarea>
            <span class="help">Laissez vide pour ne rien afficher.</span></div>

        <h2>Options</h2>
        <?php foreach (Features::LIST as $key => [$label, $help]): ?>
        <div class="field">
            <label class="switch"><input type="checkbox" name="on[<?= e($key) ?>]" value="1"<?= in_array($key, $s['off'], true) ? '' : ' checked' ?>><span class="switch-track" aria-hidden="true"></span><span><strong><?= e($label) ?></strong></span></label>
            <span class="help"><?= e($help) ?></span>
        </div>
        <?php endforeach ?>

        <div class="actions">
            <button class="btn btn-primary" type="submit" name="do" value="brouillon">Voir dans l'aperçu</button>
            <button class="btn btn-quiet" type="submit" name="do" value="defaut" data-busy="…">Remettre les couleurs d'origine</button>
        </div>
        <p class="muted small mt-12">Pour une nouvelle option ou un changement plus important, demandez-le à Claude : il le prépare dans une nouvelle version.</p>
    </form>
    <div class="studio-preview">
        <?php if ($preview): ?>
        <div class="studio-preview-bar"><span>Aperçu (vu seulement par vous)</span>
            <form method="post" action="/studio" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="quitter"><button class="btn btn-small btn-quiet" type="submit">Revoir la version en ligne</button></form></div>
        <?php else: ?>
        <div class="studio-preview-bar"><span>Version en ligne (celle de vos clients)</span></div>
        <?php endif ?>
        <iframe src="/sites?liste=1" title="Aperçu du portail" class="studio-frame"></iframe>
    </div>
</div>
