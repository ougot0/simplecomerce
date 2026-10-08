<?php
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Services\Changes;
/** @var array $ctx @var array $changes @var array $people @var string $base @var string $filter */
$verb = ['create' => 'Ajout', 'update' => 'Modification', 'delete' => 'Suppression', 'reorder' => 'Nouvel ordre', 'revert' => 'Annulation'];
?>
<div class="page-head">
    <div><h1>Historique</h1><p class="page-intro">Qui a modifié quoi, et quand. Une modification peut être annulée tant que le contenu n'a pas changé depuis.</p></div>
    <form method="get" class="inline filter-form">
        <label class="visually-hidden" for="rubrique">Rubrique</label>
        <select id="rubrique" name="rubrique" data-autosubmit>
            <option value="">Toutes les rubriques</option>
            <?php foreach ($ctx['schema']['sections'] as $s): ?><option value="<?= e($s['key']) ?>"<?= $filter === $s['key'] ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach ?>
            <option value="<?= Changes::CLOSURE_SECTION ?>"<?= $filter === Changes::CLOSURE_SECTION ? ' selected' : '' ?>>Fermeture du site</option>
        </select>
        <noscript><button class="btn btn-small" type="submit">Filtrer</button></noscript>
    </form>
</div>
<?php if (!$changes): ?>
<div class="empty"><p>Aucune modification pour l'instant.</p></div>
<?php else: ?>
<ul class="lines timeline">
    <?php foreach ($changes as $c):
        $section = ContentSchema::find($ctx['schema'], $c['section_key']);
        $canRevert = $section && Changes::inverse($c, $section);
        $diff = [];
        if ($section && is_array($c['before_json']) && is_array($c['after_json'])) {
            foreach ($section['fields'] as $f) {
                if (!empty($f['hidden'])) continue;
                $b = $c['before_json'][$f['key']] ?? null;
                $a = $c['after_json'][$f['key']] ?? null;
                if (json_encode($b ?? '') !== json_encode($a ?? '')) {
                    $diff[] = [$f['label'], show_value($f, $b) ?: '(vide)', show_value($f, $a) ?: '(vide)'];
                }
            }
        } ?>
    <li>
        <div class="line-main">
            <span class="line-title"><?= e($verb[$c['action']] ?? 'Modification') ?> — <?= e($c['entry_label']) ?></span>
            <div class="muted small"><?= e($section['label'] ?? ($c['section_key'] === Changes::CLOSURE_SECTION ? 'Fermeture du site' : $c['section_key'])) ?> · <?= e($people[$c['actor_id']] ?? '?') ?><?= $c['on_behalf_of'] ? ' (assistance pour ' . e($people[$c['on_behalf_of']] ?? 'le client') . ')' : '' ?> · <?= e(when($c['created_at'])) ?></div>
            <?php if ($diff): ?>
            <div class="diff"><?php foreach (array_slice($diff, 0, 6) as [$l, $b, $a]): ?><div><span class="muted"><?= e($l) ?> : </span><del><?= e($b) ?></del> → <ins><?= e($a) ?></ins></div><?php endforeach ?></div>
            <?php endif ?>
            <?php if ($c['status'] === 'failed'): ?><div class="error-text">Échec : <?= e($c['error']) ?></div><?php endif ?>
        </div>
        <div class="actions">
            <?= $c['status'] === 'pending' ? tag('draft', 'En cours') : '' ?>
            <?= $c['status'] === 'failed' ? tag('error', 'Non appliquée') : '' ?>
            <?= $c['reverted_by_id'] ? tag('off', 'Annulée') : '' ?>
            <?php if ($canRevert): ?>
            <form method="post" action="<?= e($base) ?>/historique/annuler" class="inline" data-confirm="Annuler « <?= e(mb_strtolower($verb[$c['action']] ?? 'modification')) ?> — <?= e($c['entry_label']) ?> » ? Le site reviendra à l'état d'avant.">
                <?= csrf_field() ?><input type="hidden" name="changeId" value="<?= e($c['id']) ?>">
                <button class="btn btn-small" type="submit"><?= icon('undo') ?> Annuler</button>
            </form>
            <?php endif ?>
        </div>
    </li>
    <?php endforeach ?>
</ul>
<?php endif;
