<?php
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Support\Text;
/** @var array $site @var array $section @var array $entries @var array $caps @var ?string $error @var array $drafts @var ?string $visKey @var string $base */
$sbase = "$base/r/{$section['key']}";
$imageKey = $section['imageField'] ?? null;
$subField = find_field($section, $section['subtitleField'] ?? null);
$itemLabel = $section['itemLabel'] ?? 'élément';
$draftFor = [];
foreach ($drafts as $d) {
    if ($d['entry_id'] !== null) { $draftFor[$d['entry_id']] = $d; }
}
$newDrafts = array_filter($drafts, fn ($d) => $d['entry_id'] === null);
$thumb = function (array $e) use ($imageKey, $site) {
    $v = $imageKey ? ($e['data'][$imageKey] ?? null) : null;
    return image_src(is_array($v) ? ($v[0] ?? null) : $v, $site['public_url']);
};
$state = function (array $e) use ($draftFor, $visKey) {
    if (isset($draftFor[$e['id']])) { return tag('draft', $draftFor[$e['id']]['publish_at'] ? 'Programmé' : 'Brouillon'); }
    return $visKey && ($e['data'][$visKey] ?? null) === false ? tag('off', 'Masqué') : tag('live', 'En ligne');
};
$href = fn (array $e) => "$sbase/e/" . rawurlencode((string) $e['id']) . (isset($draftFor[$e['id']]) ? '?brouillon=' . $draftFor[$e['id']]['id'] : '');
$toggle = function (array $e) use ($visKey, $base, $section) {
    if (!$visKey) { return ''; }
    $on = ($e['data'][$visKey] ?? null) !== false;
    return '<form method="post" action="' . e($base) . '/visibilite" class="inline">' . csrf_field() . '<input type="hidden" name="section" value="' . e($section['key']) . '"><input type="hidden" name="entryId" value="' . e((string) $e['id']) . '"><input type="hidden" name="visible" value="' . ($on ? '0' : '1') . '">'
        . '<button type="submit" class="btn btn-small btn-quiet" title="' . ($on ? 'Masquer sur le site' : 'Afficher sur le site') . '">' . icon($on ? 'eye-off' : 'eye') . '<span>' . ($on ? 'Masquer' : 'Afficher') . '</span></button></form>';
};
?>
<div class="page-head">
    <div>
        <h1><?= e($section['label']) ?></h1>
        <?php if (!$error): ?><p class="page-intro"><?= e(Text::plural(count($entries), $itemLabel)) ?> sur votre site. Cliquez sur un élément pour le modifier.</p><?php endif ?>
    </div>
    <div class="actions">
        <?php if ($caps['reorder'] && count($entries) > 1): ?><a class="btn" href="<?= e($sbase) ?>?ordre=1">Changer l'ordre</a><?php endif ?>
        <?php if ($caps['create']): ?><a class="btn btn-primary" href="<?= e($sbase) ?>/nouveau"><?= icon('plus') ?> <?= e(Text::addLabel($section['itemLabel'] ?? null)) ?></a><?php endif ?>
    </div>
</div>
<?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif ?>

<?php if ($newDrafts): ?>
<div class="notice notice-warn"><p>Pas encore en ligne :
    <?php $i = 0; foreach ($newDrafts as $d): ?><?= $i++ ? ', ' : '' ?><a href="<?= e($sbase) ?>/nouveau?brouillon=<?= e($d['id']) ?>"><?= e($d['label']) ?></a><?php endforeach ?></p></div>
<?php endif ?>

<?php if (!$error && !$entries): ?>
<div class="empty">
    <p>Aucun élément pour l'instant.</p>
    <?php if ($caps['create']): ?><a class="btn btn-primary" href="<?= e($sbase) ?>/nouveau"><?= icon('plus') ?> <?= e(Text::addLabel($section['itemLabel'] ?? null)) ?></a><?php endif ?>
</div>
<?php elseif (!$error): ?>
    <?php if (count($entries) > 5): ?>
    <div class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher dans <?= e(mb_strtolower($section['label'])) ?>…" data-filter aria-label="Rechercher"></div>
    <?php endif ?>
    <?php if ($imageKey): ?>
    <div class="cards-grid" data-filter-list>
        <?php foreach ($entries as $e): $t = $thumb($e); $title = ContentSchema::title($section, $e['data']); ?>
        <div class="item-card" data-filter-item data-text="<?= e(mb_strtolower($title)) ?>">
            <a href="<?= e($href($e)) ?>" class="item-link">
                <?= $t ? '<img class="item-photo" src="' . e($t) . '" alt="" loading="lazy">' : '<span class="item-photo">Pas de photo</span>' ?>
                <span class="item-body">
                    <span class="item-title"><?= e($title) ?></span>
                    <span class="item-row"><span class="item-price"><?= e($subField ? show_value($subField, $e['data'][$subField['key']] ?? null, 60) : '') ?></span><?= $state($e) ?></span>
                </span>
            </a>
            <?php if ($visKey): ?><div class="item-tools"><?= $toggle($e) ?></div><?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php else: ?>
    <div class="ledger-wrap">
        <table class="ledger" data-filter-list>
            <thead><tr><th>Nom</th><?php if ($subField): ?><th class="num hide-small"><?= e($subField['label']) ?></th><?php endif ?><th class="shrink">État</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($entries as $e): $title = ContentSchema::title($section, $e['data']); ?>
                <tr data-filter-item data-text="<?= e(mb_strtolower($title)) ?>">
                    <td class="title-cell"><a href="<?= e($href($e)) ?>"><?= e($title) ?></a></td>
                    <?php if ($subField): ?><td class="num hide-small"><?= e(show_value($subField, $e['data'][$subField['key']] ?? null, 60)) ?></td><?php endif ?>
                    <td class="shrink"><?= $state($e) ?></td>
                    <td class="shrink"><div class="actions nowrap"><?= $toggle($e) ?><a class="btn btn-small" href="<?= e($href($e)) ?>">Modifier</a></div></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
    <p class="muted small mt-14" data-filter-empty hidden>Aucun résultat.</p>
<?php endif;
