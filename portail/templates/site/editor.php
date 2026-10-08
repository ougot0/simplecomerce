<?php
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Support\Text;
/**
 * @var array $site @var array $section @var ?string $entryId @var ?array $draft @var array $values @var string $fingerprint
 * @var ?string $error @var bool $conflict @var array $errors @var bool $canDelete @var string $cancel @var string $sectionBase @var ?array $otherDraft @var string $base
 */
require __DIR__ . '/fields.php';
$isSingleton = ($section['kind'] ?? '') === 'singleton';
$fctx = ['errors' => $errors, 'base' => $site['public_url'], 'slug' => $site['slug']];
$titleKey = $section['titleField'] ?? null;
$imageKey = $section['imageField'] ?? null;
$subField = find_field($section, $section['subtitleField'] ?? null);
if (!$subField) {
    foreach ($section['fields'] as $f) {
        if ($f['type'] === 'price' && empty($f['hidden'])) { $subField = $f; break; }
    }
}
$textField = null;
foreach ($section['fields'] as $f) {
    if (empty($f['hidden']) && in_array($f['type'], ['textarea', 'richtext', 'markdown'], true)) { $textField = $f; break; }
}
$showPreview = !$isSingleton && ($imageKey || $titleKey);
$imgVal = $imageKey ? ($values[$imageKey] ?? null) : null;
$imgSrc = image_src(is_array($imgVal) ? ($imgVal[0] ?? null) : $imgVal, $site['public_url']);
$scheduledLocal = !empty($draft['publish_at']) ? date('Y-m-d\TH:i', strtotime($draft['publish_at'])) : '';
?>
<div class="page-head">
    <div>
        <?php if (!$isSingleton): ?><div class="crumbs"><a href="<?= e($sectionBase) ?>"><?= e($section['label']) ?></a></div><?php endif ?>
        <h1><?= e($title) ?></h1>
    </div>
    <?php if ($entryId !== null && !$draft): ?>
    <form method="post" action="<?= e($base) ?>/dupliquer" class="inline">
        <?= csrf_field() ?><input type="hidden" name="section" value="<?= e($section['key']) ?>"><input type="hidden" name="entryId" value="<?= e($entryId) ?>">
        <button class="btn btn-small" type="submit"><?= icon('copy') ?> Dupliquer</button>
    </form>
    <?php endif ?>
</div>

<?php if ($otherDraft): ?>
<div class="notice notice-warn"><p><?= $otherDraft['publish_at'] ? 'Une version programmée pour le ' . e(Text::date($otherDraft['publish_at'], false, true)) . ' à ' . e(date('G \h i', strtotime($otherDraft['publish_at']))) . ' attend.' : 'Un brouillon de ce contenu attend d\'être mis en ligne.' ?>
    <a href="?brouillon=<?= e($otherDraft['id']) ?>">Reprendre le brouillon</a></p></div>
<?php endif ?>

<div class="<?= $showPreview ? 'editor' : 'editor-single' ?>">
    <form method="post" action="<?= e($base) ?>/enregistrer" class="form panel" novalidate data-editor data-dirty-guard>
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="<?= e($section['key']) ?>">
        <input type="hidden" name="entryId" value="<?= e($entryId ?? '') ?>">
        <input type="hidden" name="draftId" value="<?= e($draft['id'] ?? '') ?>">
        <input type="hidden" name="fingerprint" value="<?= e($fingerprint) ?>">

        <?php if ($draft): ?>
        <div class="notice notice-warn"><p><?= $draft['publish_at'] ? 'Version programmée : elle sera publiée automatiquement le ' . e(Text::date($draft['publish_at'], false, true)) . ' à ' . e(date('G \h i', strtotime($draft['publish_at']))) . '.' : 'Vous modifiez un brouillon : il n\'est pas encore visible sur votre site.' ?></p></div>
        <?php endif ?>
        <?php if ($error): ?>
        <div class="notice notice-error" role="alert"><p><?= e($error) ?></p><?php if ($conflict): ?><p><a href="">Recharger la page</a></p><?php endif ?></div>
        <?php endif ?>

        <?php foreach ($section['fields'] as $f): ?>
            <?= sc_field($f, $values[$f['key']] ?? null, 'data[' . $f['key'] . ']', $f['key'], $fctx) ?>
        <?php endforeach ?>

        <div class="schedule" data-schedule<?= isset($errors['_publishAt']) || $scheduledLocal ? '' : ' hidden' ?>>
            <div class="field<?= invalid($errors, '_publishAt') ?>">
                <label for="publishAt"><?= icon('calendar') ?> Publier automatiquement le</label>
                <input id="publishAt" name="publishAt" type="datetime-local" class="medium" value="<?= e($scheduledLocal) ?>" min="<?= e(date('Y-m-d\TH:i')) ?>">
                <span class="help">Heure de Paris. Pratique pour une promotion, un menu de fête ou une fermeture annoncée.</span>
                <?= field_error($errors, '_publishAt') ?>
            </div>
            <div class="actions"><button class="btn" type="submit" name="mode" value="schedule">Programmer la publication</button></div>
        </div>

        <div class="savebar">
            <button class="btn btn-primary" type="submit" name="mode" value="publish"><?= icon('check') ?> Publier sur mon site</button>
            <button class="btn" type="submit" name="mode" value="draft">Enregistrer sans publier</button>
            <button class="btn btn-quiet" type="button" data-toggle-schedule><?= icon('calendar') ?> Programmer…</button>
            <a class="btn btn-quiet" href="<?= e($cancel) ?>" data-leave>Annuler</a>
            <span class="spacer"></span>
            <?php if ($canDelete && $entryId !== null): ?>
            <button type="button" class="btn btn-danger btn-small" data-open-delete>Supprimer…</button>
            <?php endif ?>
        </div>
    </form>

    <?php if ($showPreview): ?>
    <aside class="preview" aria-label="Aperçu" data-preview data-title="<?= e($titleKey ?? '') ?>" data-image="<?= e($imageKey ?? '') ?>" data-sub="<?= e($subField['key'] ?? '') ?>" data-sub-price="<?= ($subField['type'] ?? '') === 'price' ? '1' : '' ?>" data-text="<?= e($textField['key'] ?? '') ?>">
        <div class="preview-label">Aperçu — l'apparence exacte dépend de votre site</div>
        <div class="preview-body">
            <?php if ($imageKey): ?><div class="preview-photo" data-p-photo><?= $imgSrc ? '<img src="' . e($imgSrc) . '" alt="">' : '<span>Aucune photo</span>' ?></div><?php endif ?>
            <div class="p-title" data-p-title><?= e(ContentSchema::title($section, $values)) ?></div>
            <div class="p-sub" data-p-sub><?= e($subField ? show_value($subField, $values[$subField['key']] ?? null) : '') ?></div>
            <div class="p-text" data-p-text><?= e($textField ? show_value($textField, $values[$textField['key']] ?? null, 260) : '') ?></div>
        </div>
    </aside>
    <?php endif ?>
</div>

<?php if ($canDelete && $entryId !== null): ?>
<form method="post" action="<?= e($base) ?>/supprimer" class="notice notice-error delete-box" data-delete-box hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="<?= e($section['key']) ?>">
    <input type="hidden" name="entryId" value="<?= e($entryId) ?>">
    <input type="hidden" name="fingerprint" value="<?= e($fingerprint) ?>">
    <p><strong>Supprimer « <?= e(ContentSchema::title($section, $values)) ?> » de votre site ?</strong> Vous pourrez l'annuler depuis l'historique.</p>
    <div class="actions">
        <button type="submit" class="btn btn-danger">Oui, supprimer</button>
        <button type="button" class="btn btn-quiet" data-close-delete>Non, garder</button>
    </div>
</form>
<?php endif ?>

<dialog class="dialog-native" data-library-dialog>
    <div class="dialog-head"><strong>Mes photos</strong><button type="button" class="btn btn-small btn-quiet" data-close-dialog>Fermer</button></div>
    <div class="library" data-library-list><p class="muted">Chargement…</p></div>
</dialog>
<dialog class="dialog-native dialog-wide" data-crop-dialog>
    <div class="dialog-head"><strong>Recadrer la photo</strong><span class="help">Faites glisser pour cadrer, utilisez le curseur pour zoomer.</span></div>
    <div class="crop-area" data-crop-area><canvas data-crop-canvas></canvas></div>
    <div class="dialog-foot">
        <label class="zoom">Zoom <input type="range" min="1" max="3" step="0.01" value="1" data-crop-zoom></label>
        <div class="field grow"><label for="crop-alt">Description de la photo <span class="optional">(pour les personnes malvoyantes et Google)</span></label><input id="crop-alt" type="text" data-crop-alt maxlength="300" placeholder="Ex. : tarte au citron meringuée vue de dessus"></div>
        <div class="actions"><button type="button" class="btn btn-quiet" data-crop-cancel>Annuler</button><button type="button" class="btn btn-primary" data-crop-ok>Utiliser cette photo</button></div>
    </div>
</dialog>
<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-file-input>
<div data-upload-url="<?= e($base) ?>/photo" data-library-url="<?= e($base) ?>/photos" hidden></div>
