<?php
use SimpleCommerce\Adapters\Catalog;
/** @var array $def @var array $values @var array $errors @var array $fingerprints */
$fingerprints ??= [];
$render = function (array $f) use ($values, $errors, $fingerprints) {
    $id = 'c_' . $f['name'];
    $saved = !empty($f['secret']) ? ($fingerprints[$f['name']] ?? null) : null;
    $value = !empty($f['secret']) ? '' : (string) ($values[$f['name']] ?? $values[$id] ?? $f['default'] ?? '');
    $ph = $saved ? "Enregistré ($saved) — laisser vide pour le garder" : ($f['placeholder'] ?? '');
    ob_start(); ?>
    <div class="field<?= invalid($errors, $id) ?>">
        <label for="<?= e($id) ?>"><?= e($f['label']) ?> <?= empty($f['required']) ? '<span class="optional">(facultatif)</span>' : '' ?></label>
        <?php if ($f['type'] === 'select'): ?>
            <select id="<?= e($id) ?>" name="<?= e($id) ?>"<?= $f['name'] === 'hostPreset' ? ' data-host-preset data-hosts="' . e(json_encode(Catalog::HOSTS, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
                <?php foreach ($f['options'] as $o): ?><option value="<?= e($o['value']) ?>"<?= $o['value'] === $value ? ' selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach ?>
            </select>
        <?php elseif ($f['type'] === 'textarea'): ?>
            <textarea id="<?= e($id) ?>" name="<?= e($id) ?>" class="code short" placeholder="<?= e($ph) ?>" autocomplete="off" spellcheck="false"></textarea>
        <?php else: ?>
            <input id="<?= e($id) ?>" name="<?= e($id) ?>" type="<?= $f['type'] === 'password' ? 'password' : ($f['type'] === 'number' ? 'number' : 'text') ?>" value="<?= e($value) ?>" placeholder="<?= e($ph) ?>" autocomplete="<?= !empty($f['secret']) ? 'new-password' : 'off' ?>" spellcheck="false">
        <?php endif ?>
        <?php if (!empty($f['help'])): ?><span class="help"><?= e($f['help']) ?></span><?php endif ?>
        <?php if ($f['name'] === 'hostPreset'): ?><span class="help host-where" data-host-where></span><?php endif ?>
        <?= field_error($errors, $id) ?>
    </div>
    <?php return ob_get_clean();
};
$basic = array_filter($def['fields'], fn ($f) => empty($f['advanced']));
$advanced = array_filter($def['fields'], fn ($f) => !empty($f['advanced']));
foreach ($basic as $f) {
    echo $render($f);
}
if ($advanced): ?>
<details class="advanced"<?= array_intersect_key($errors, array_flip(array_map(fn ($f) => 'c_' . $f['name'], $advanced))) ? ' open' : '' ?>>
    <summary>Réglages avancés (à laisser tels quels en général)</summary>
    <div class="form"><?php foreach ($advanced as $f) { echo $render($f); } ?></div>
</details>
<?php endif;
