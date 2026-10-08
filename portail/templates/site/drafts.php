<?php
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Support\Text;
/** @var array $ctx @var array $drafts @var array $people @var string $base */ ?>
<div class="page-head"><div><h1>Brouillons</h1><p class="page-intro">Préparés mais pas encore visibles sur votre site. Les versions programmées partent toutes seules à l'heure prévue.</p></div></div>
<?php if (!$drafts): ?>
<div class="empty"><p>Aucun brouillon.</p><p class="muted small">Pour en créer un, utilisez « Enregistrer sans publier » ou « Programmer… » dans un formulaire.</p></div>
<?php else: ?>
<ul class="lines">
    <?php foreach ($drafts as $d):
        $section = ContentSchema::find($ctx['schema'], $d['section_key']);
        $href = !$section ? null : (($section['kind'] ?? '') === 'singleton' ? "$base/r/{$section['key']}?brouillon={$d['id']}"
            : ($d['entry_id'] !== null ? "$base/r/{$section['key']}/e/" . rawurlencode($d['entry_id']) . "?brouillon={$d['id']}" : "$base/r/{$section['key']}/nouveau?brouillon={$d['id']}")); ?>
    <li>
        <div class="line-main">
            <?php if ($href): ?><a class="line-title" href="<?= e($href) ?>"><?= e($d['label']) ?></a><?php else: ?><span class="line-title"><?= e($d['label']) ?></span><?php endif ?>
            <?php if ($d['publish_at']): ?> <?= tag('draft', 'Programmé le ' . Text::date($d['publish_at'], false) . ' à ' . date('G \h i', strtotime($d['publish_at']))) ?><?php endif ?>
            <div class="muted small"><?= e($section['label'] ?? 'Rubrique supprimée') ?> · <?= $d['entry_id'] === null ? 'nouvel élément' : 'modification' ?> · <?= e($people[$d['updated_by']] ?? '?') ?>, <?= e(when($d['updated_at'])) ?></div>
        </div>
        <div class="actions">
            <form method="post" action="<?= e($base) ?>/brouillons/publier" class="inline"><?= csrf_field() ?><input type="hidden" name="draftId" value="<?= e($d['id']) ?>"><button class="btn btn-small btn-primary" type="submit"><?= $d['publish_at'] ? 'Publier maintenant' : 'Publier' ?></button></form>
            <form method="post" action="<?= e($base) ?>/brouillons/jeter" class="inline" data-confirm="Jeter le brouillon « <?= e($d['label']) ?> » ?"><?= csrf_field() ?><input type="hidden" name="draftId" value="<?= e($d['id']) ?>"><button class="btn btn-small btn-quiet" type="submit">Jeter</button></form>
        </div>
    </li>
    <?php endforeach ?>
</ul>
<?php endif;
