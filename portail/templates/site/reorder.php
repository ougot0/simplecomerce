<?php
use SimpleCommerce\Content\ContentSchema;
/** @var array $site @var array $section @var array $entries @var string $base */
$imageKey = $section['imageField'] ?? null;
$sbase = "$base/r/{$section['key']}";
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= e($sbase) ?>"><?= e($section['label']) ?></a></div><h1>Changer l'ordre</h1>
<p class="page-intro">L'ordre ici est l'ordre d'affichage sur votre site. Utilisez les flèches, puis enregistrez.</p></div></div>
<form method="post" action="<?= e($base) ?>/ordre" data-reorder>
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="<?= e($section['key']) ?>">
    <div class="ledger-wrap"><table class="ledger"><tbody data-items>
        <?php foreach ($entries as $i => $e): $v = $imageKey ? ($e['data'][$imageKey] ?? null) : null; $t = image_src(is_array($v) ? ($v[0] ?? null) : $v, $site['public_url']); ?>
        <tr data-row>
            <td class="shrink num faint" data-pos><?= $i + 1 ?></td>
            <?php if ($imageKey): ?><td class="shrink"><?= $t ? '<img class="thumb" src="' . e($t) . '" alt="">' : '<span class="thumb thumb-empty"></span>' ?></td><?php endif ?>
            <td class="title-cell"><strong><?= e(ContentSchema::title($section, $e['data'])) ?></strong><input type="hidden" name="ids[]" value="<?= e((string) $e['id']) ?>"></td>
            <td class="shrink"><div class="actions nowrap">
                <button type="button" class="btn btn-small" data-move="-1" aria-label="Monter"><?= icon('up') ?></button>
                <button type="button" class="btn btn-small" data-move="1" aria-label="Descendre"><?= icon('down') ?></button>
            </div></td>
        </tr>
        <?php endforeach ?>
    </tbody></table></div>
    <div class="savebar mt-16"><button class="btn btn-primary" type="submit">Enregistrer l'ordre</button><a class="btn btn-quiet" href="<?= e($sbase) ?>">Annuler</a></div>
</form>
