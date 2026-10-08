<?php
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Text;
/** @var array $ctx @var array $site @var array $changes @var array $last @var ?array $closure @var array $drafts @var bool $welcome @var array $people @var string $base @var string $role */
$first = explode(' ', trim((string) $ctx['viewer']['effective']['name']))[0] ?? '';
$sections = $ctx['sections'];
$scheduled = array_filter($drafts, fn ($d) => $d['publish_at']);
$verbs = ['create' => 'ajouté', 'delete' => 'supprimé', 'reorder' => 'ordre modifié', 'revert' => 'modification annulée'];
?>
<div class="page-head">
    <div>
        <h1><?= $first !== '' ? 'Bonjour ' . e($first) : 'Bonjour' ?></h1>
        <p class="page-intro">Choisissez ce que vous voulez modifier. Vos changements apparaissent sur votre site dès que vous cliquez sur « Publier sur mon site ».</p>
    </div>
    <div class="actions">
        <?php if ($site['public_url']): ?><a class="btn" href="<?= e($site['public_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('eye') ?> Voir mon site</a><?php endif ?>
    </div>
</div>

<?php if ($welcome): ?>
<div class="notice notice-ok">
    <p><strong>Votre site est relié.</strong> <?= $sections ? e(Text::plural(count($sections), 'rubrique')) . (count($sections) > 1 ? ' sont modifiables' : ' est modifiable') . ' ci-dessous.' : "Aucune rubrique modifiable n'a encore été trouvée." ?></p>
    <?php if (!$sections): ?><p>Le contenu du site doit d'abord être rangé à part du code. Votre créateur de site peut s'en charger : l'<a href="/aide#preparer">aide</a> lui explique comment.</p><?php endif ?>
    <?php if ($sections && $role !== 'editor'): ?><p>Vous pouvez renommer ou masquer des rubriques dans <a href="<?= e($base) ?>/reglages#rubriques">Réglages</a>.</p><?php endif ?>
</div>
<?php endif ?>

<?php if (!empty($closure['closed'])): ?>
<div class="notice notice-error closed-banner">
    <p><?= icon('lock') ?> <strong>Votre site est fermé temporairement.</strong> Vos visiteurs voient : « <?= e($closure['message']) ?> »<?= $closure['reopenOn'] ? ' — réouverture le ' . e(Text::date($closure['reopenOn'], false, true)) : '' ?>.
    <?= $role !== 'editor' ? '<a href="' . e($base) . '/reglages#fermeture">Rouvrir le site</a>' : 'Le propriétaire du site peut le rouvrir.' ?></p>
</div>
<?php endif ?>

<?php if ($drafts): ?>
<div class="notice notice-warn">
    <p><?= e(Text::plural(count($drafts), 'brouillon')) ?> en attente<?= $scheduled ? ', dont ' . count($scheduled) . ' programmé' . (count($scheduled) > 1 ? 's' : '') : '' ?>. <a href="<?= e($base) ?>/brouillons">Voir les brouillons</a></p>
</div>
<?php endif ?>

<section>
    <h2 class="mb-14">Que voulez-vous modifier ?</h2>
    <?php if (!$sections): ?>
    <p class="muted">Aucune rubrique pour le moment.</p>
    <?php else: ?>
    <div class="cards-grid">
        <?php foreach ($sections as $s): $isList = ($s['kind'] ?? '') === 'collection'; ?>
        <a class="section-card" href="<?= e($base) ?>/r/<?= e($s['key']) ?>">
            <span class="section-icon"><?= icon($isList ? 'list' : 'text') ?></span>
            <strong><?= e($s['label']) ?></strong>
            <span class="muted small"><?= $isList ? e(Text::listOf($s['label'])) : 'Textes et informations' ?><?= isset($last[$s['key']]) ? ' · modifié ' . e(when($last[$s['key']])) : '' ?></span>
            <span class="go"><?= $isList ? 'Voir la liste →' : 'Modifier →' ?></span>
        </a>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</section>

<section class="section-block">
    <div class="block-head">
        <h2>Dernières modifications</h2>
        <?php if ($changes): ?><a href="<?= e($base) ?>/historique" class="small">Tout l'historique →</a><?php endif ?>
    </div>
    <?php if (!$changes): ?>
    <p class="muted">Rien pour l'instant. Vos modifications apparaîtront ici. <?= e(Sites::delayText($site)) ?></p>
    <?php else: ?>
    <ul class="lines">
        <?php foreach ($changes as $c): ?>
        <li>
            <div class="line-main">
                <span class="line-title"><?= e($c['entry_label']) ?></span> <span class="muted">— <?= e($verbs[$c['action']] ?? 'modifié') ?></span>
                <div class="muted small"><?= e($people[$c['actor_id']] ?? "Quelqu'un") ?><?= $c['on_behalf_of'] ? ' (assistance pour ' . e($people[$c['on_behalf_of']] ?? 'le client') . ')' : '' ?> · <?= e(when($c['created_at'])) ?></div>
            </div>
            <?= $c['status'] === 'failed' ? tag('error', 'Échec') : ($c['status'] === 'pending' ? tag('draft', 'En cours') : '') ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>

<section class="section-block quick-links">
    <a href="<?= e($base) ?>/sauvegarde" class="quick"><?= icon('download') ?><span><strong>Télécharger une sauvegarde</strong><span class="muted small">Tout votre contenu dans un fichier, à garder chez vous.</span></span></a>
    <?php if ($role !== 'editor'): ?>
    <a href="<?= e($base) ?>/reglages#equipe" class="quick"><?= icon('users') ?><span><strong>Inviter un collègue</strong><span class="muted small">Il pourra modifier le site avec son propre compte.</span></span></a>
    <a href="<?= e($base) ?>/reglages#fermeture" class="quick"><?= icon('lock') ?><span><strong>Fermer le site temporairement</strong><span class="muted small">Congés, travaux : un message à la place du site.</span></span></a>
    <?php endif ?>
</section>
