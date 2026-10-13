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
$state = function (array $e) use ($draftFor, $visKey, $site, $section) {
    $stock = \SimpleCommerce\Services\Shop::stockTag($site, $section, $e['data']);
    if (isset($draftFor[$e['id']])) { return tag('draft', $draftFor[$e['id']]['publish_at'] ? 'Programmé' : 'Brouillon') . $stock; }
    return ($visKey && ($e['data'][$visKey] ?? null) === false ? tag('off', 'Masqué') : tag('live', 'En ligne')) . $stock;
};
$href = fn (array $e) => "$sbase/e/" . rawurlencode((string) $e['id']) . (isset($draftFor[$e['id']]) ? '?brouillon=' . $draftFor[$e['id']]['id'] : '');
$toggle = function (array $e) use ($visKey, $base, $section) {
    if (!$visKey) { return ''; }
    $on = ($e['data'][$visKey] ?? null) !== false;
    return '<form method="post" action="' . e($base) . '/visibilite" class="inline">' . csrf_field() . '<input type="hidden" name="section" value="' . e($section['key']) . '"><input type="hidden" name="entryId" value="' . e((string) $e['id']) . '"><input type="hidden" name="visible" value="' . ($on ? '0' : '1') . '">'
        . '<button type="submit" class="btn btn-small btn-quiet" title="' . ($on ? 'Masquer sur le site' : 'Afficher sur le site') . '">' . icon($on ? 'eye-off' : 'eye') . '<span>' . ($on ? 'Masquer' : 'Afficher') . '</span></button></form>';
};
// Cases à cocher des éléments (« Solde », « Nouveauté », « Coup de cœur »…) : filtres et modification en lot.
$flags = array_slice(array_values(array_filter($section['fields'], fn ($f) => $f['type'] === 'boolean' && empty($f['hidden']) && $f['key'] !== $visKey)), 0, 6);
$flagCount = [];
foreach ($flags as $f) {
    $flagCount[$f['key']] = count(array_filter($entries, fn ($e) => ($e['data'][$f['key']] ?? null) === true));
}
// Catégories (liste de choix courte) : un filtre par catégorie.
$catField = current(array_filter($section['fields'], fn ($f) => $f['type'] === 'select' && empty($f['hidden']) && count($f['options'] ?? []) >= 2 && count($f['options'] ?? []) <= 10)) ?: null;
$catCount = [];
foreach ($catField['options'] ?? [] as $o) {
    $catCount[(string) $o['value']] = count(array_filter($entries, fn ($e) => (string) ($e['data'][$catField['key']] ?? '') === (string) $o['value']));
}
$catCount = array_filter($catCount);
$flagOn = \SimpleCommerce\Http\Req::query('case', 80);
$flagOn = isset($flagCount[$flagOn]) ? $flagOn : null;
$catOn = \SimpleCommerce\Http\Req::query('categorie', 120);
$catOn = $flagOn === null && isset($catCount[$catOn]) ? $catOn : null;
$allCount = count($entries);
if ($flagOn !== null) {
    $entries = array_values(array_filter($entries, fn ($e) => ($e['data'][$flagOn] ?? null) === true));
}
if ($catOn !== null) {
    $entries = array_values(array_filter($entries, fn ($e) => (string) ($e['data'][$catField['key']] ?? '') === $catOn));
}
$short = fn (string $l): string => trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $l)) ?: $l;
$flagTags = function (array $e) use ($flags, $short): string {
    $out = '';
    foreach ($flags as $f) {
        if (($e['data'][$f['key']] ?? null) === true) {
            $out .= ' ' . tag('draft', $short($f['label']));
        }
    }
    return $out;
};
$bulkPrice = (bool) array_filter($section['fields'], fn ($f) => $f['type'] === 'price' && empty($f['hidden']));
$bulkStock = \SimpleCommerce\Services\Shop::stockField($section) !== null;
$check = fn (array $e) => '<input type="checkbox" name="ids[]" value="' . e((string) $e['id']) . '" form="bulk-form" data-bulk-item aria-label="Sélectionner ' . e(ContentSchema::title($section, $e['data'])) . '">';
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

<?php if (!$error && ($flags || count($catCount) > 1) && $allCount): ?>
<nav class="filter-chips" aria-label="Filtrer">
    <a href="<?= e($sbase) ?>" class="chip"<?= $flagOn === null && $catOn === null ? ' aria-current="page"' : '' ?>>Tous <span class="muted">(<?= $allCount ?>)</span></a>
    <?php foreach ($flags as $f): ?>
    <a href="<?= e($sbase) ?>?case=<?= e(rawurlencode($f['key'])) ?>" class="chip"<?= $flagOn === $f['key'] ? ' aria-current="page"' : '' ?>><?= e($short($f['label'])) ?> <span class="muted">(<?= $flagCount[$f['key']] ?>)</span></a>
    <?php endforeach ?>
    <?php if (count($catCount) > 1): foreach ($catField['options'] as $o): if (!isset($catCount[(string) $o['value']])) { continue; } ?>
    <a href="<?= e($sbase) ?>?categorie=<?= e(rawurlencode((string) $o['value'])) ?>" class="chip"<?= $catOn === (string) $o['value'] ? ' aria-current="page"' : '' ?>><?= e($short((string) $o['label'])) ?> <span class="muted">(<?= $catCount[(string) $o['value']] ?>)</span></a>
    <?php endforeach; endif ?>
</nav>
<?php endif ?>
<?php if (!$error && !$entries && $flagOn !== null): ?>
<p class="muted">Aucun élément coché « <?= e(find_field($section, $flagOn)['label'] ?? $flagOn) ?> » pour l'instant. Cochez des éléments dans « Tous », puis choisissez « Mettre en <?= e(find_field($section, $flagOn)['label'] ?? $flagOn) ?> ».</p>
<?php elseif (!$error && !$entries): ?>
<div class="empty">
    <p>Aucun élément pour l'instant.</p>
    <?php if ($caps['create']): ?><a class="btn btn-primary" href="<?= e($sbase) ?>/nouveau"><?= icon('plus') ?> <?= e(Text::addLabel($section['itemLabel'] ?? null)) ?></a><?php endif ?>
</div>
<?php elseif (!$error): ?>
    <?php if (count($entries) > 5): ?>
    <div class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher dans <?= e(mb_strtolower($section['label'])) ?>…" data-filter aria-label="Rechercher"></div>
    <?php endif ?>
    <?php if (count($entries) > 1 || ($flags && $entries)): ?>
    <form method="post" action="<?= e($base) ?>/groupe" id="bulk-form" class="bulk-bar" data-bulk>
        <?= csrf_field() ?><input type="hidden" name="section" value="<?= e($section['key']) ?>">
        <label class="checkbox bulk-all"><input type="checkbox" data-bulk-all><span>Tout cocher</span></label>
        <span class="muted small" data-bulk-count>Cochez des éléments pour les modifier ensemble.</span>
        <select name="action" aria-label="Que faire des éléments cochés ?" data-bulk-action>
            <?php if ($visKey): ?><option value="masquer">Masquer sur le site</option><option value="afficher">Afficher sur le site</option><?php endif ?>
            <?php foreach ($flags as $f): ?><option value="oui:<?= e($f['key']) ?>">Mettre en « <?= e($f['label']) ?> »</option><option value="non:<?= e($f['key']) ?>">Retirer de « <?= e($f['label']) ?> »</option><?php endforeach ?>
            <?php if ($bulkPrice): ?><option value="prix">Changer le prix de…</option><?php endif ?>
            <?php if ($bulkStock): ?><option value="stock">Mettre le stock à…</option><?php endif ?>
            <?php if ($caps['delete']): ?><option value="supprimer">Supprimer</option><?php endif ?>
        </select>
        <?php if ($bulkPrice): ?><span class="bulk-extra" data-bulk-for="prix"><input type="text" name="percent" inputmode="decimal" class="short" placeholder="+10 ou -20" aria-label="Pourcentage"> % <label class="checkbox"><input type="checkbox" name="round" value="1" checked><span>Arrondir à l'euro</span></label></span><?php endif ?>
        <?php if ($bulkStock): ?><span class="bulk-extra" data-bulk-for="stock"><input type="number" name="stock" min="0" class="short" aria-label="Nouveau stock"></span><?php endif ?>
        <button class="btn btn-small btn-primary" type="submit" data-busy="Modification…">Appliquer</button>
    </form>
    <?php endif ?>
    <?php if ($imageKey): ?>
    <div class="cards-grid" data-filter-list>
        <?php foreach ($entries as $e): $t = $thumb($e); $title = ContentSchema::title($section, $e['data']); ?>
        <div class="item-card" data-filter-item data-text="<?= e(mb_strtolower($title)) ?>">
            <?php if (count($entries) > 1 || $flags): ?><label class="item-check"><?= $check($e) ?></label><?php endif ?>
            <a href="<?= e($href($e)) ?>" class="item-link">
                <?= $t ? '<img class="item-photo" src="' . e($t) . '" alt="" loading="lazy">' : '<span class="item-photo">Pas de photo</span>' ?>
                <span class="item-body">
                    <span class="item-title"><?= e($title) ?></span>
                    <span class="item-row"><span class="item-price"><?= e($subField ? show_value($subField, $e['data'][$subField['key']] ?? null, 60) : '') ?></span><?= $state($e) ?><?= $flagTags($e) ?></span>
                </span>
            </a>
            <?php if ($visKey): ?><div class="item-tools"><?= $toggle($e) ?></div><?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php else: ?>
    <div class="ledger-wrap">
        <table class="ledger" data-filter-list>
            <thead><tr><?php if (count($entries) > 1 || $flags): ?><th class="shrink"><span class="visually-hidden">Sélection</span></th><?php endif ?><th>Nom</th><?php if ($subField): ?><th class="num hide-small"><?= e($subField['label']) ?></th><?php endif ?><th class="shrink">État</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($entries as $e): $title = ContentSchema::title($section, $e['data']); ?>
                <tr data-filter-item data-text="<?= e(mb_strtolower($title)) ?>">
                    <?php if (count($entries) > 1 || $flags): ?><td class="shrink"><?= $check($e) ?></td><?php endif ?>
                    <td class="title-cell"><a href="<?= e($href($e)) ?>"><?= e($title) ?></a></td>
                    <?php if ($subField): ?><td class="num hide-small"><?= e(show_value($subField, $e['data'][$subField['key']] ?? null, 60)) ?></td><?php endif ?>
                    <td class="shrink"><?= $state($e) ?><?= $flagTags($e) ?></td>
                    <td class="shrink"><div class="actions nowrap"><?= $toggle($e) ?><a class="btn btn-small" href="<?= e($href($e)) ?>">Modifier</a></div></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
    <p class="muted small mt-14" data-filter-empty hidden>Aucun résultat.</p>
<?php endif;
