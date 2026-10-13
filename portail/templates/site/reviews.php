<?php
use SimpleCommerce\Services\Reviews;
/** @var array $reviews @var array $counts @var string $status @var bool $enabled @var string $line @var string $base @var string $role */
?>
<div class="page-head">
    <div><h1>Avis clients</h1><p class="page-intro">Vos clients laissent un avis sur votre site. Vous le lisez ici avant qu'il soit affiché, et vous pouvez y répondre.</p></div>
</div>

<?php if (!$enabled): ?>
<div class="panel">
    <p>Les avis ne sont pas encore ouverts sur votre site.</p>
    <?php if ($role !== 'editor'): ?>
    <form method="post" action="<?= e($base) ?>/avis/action"><?= csrf_field() ?><input type="hidden" name="do" value="activer">
        <button class="btn btn-primary" type="submit">Ouvrir les avis</button></form>
    <?php else: ?><p class="muted">Demandez au propriétaire du site de les ouvrir.</p><?php endif ?>
</div>
<?php else: ?>
<div class="panel">
    <p><strong>Pour afficher les avis sur votre site</strong>, collez cette ligne une seule fois à l'endroit voulu (ou demandez-le à la personne qui s'occupe de votre site). Le formulaire « Donner mon avis » apparaît avec.</p>
    <div class="copy-row"><input type="text" readonly value="<?= e($line) ?>" class="code" aria-label="Ligne à coller dans votre site" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div>
    <?php if ($role !== 'editor'): ?>
    <form method="post" action="<?= e($base) ?>/avis/action" class="mt-6" data-confirm="Fermer les avis ? Ils ne seront plus affichés sur votre site."><?= csrf_field() ?><input type="hidden" name="do" value="desactiver">
        <button class="btn btn-small btn-quiet" type="submit">Fermer les avis</button></form>
    <?php endif ?>
</div>
<?php endif ?>

<nav class="tabs mt-6" aria-label="Filtrer les avis">
    <?php foreach (Reviews::STATUSES as $k => $label): ?>
    <a href="<?= e($base) ?>/avis?etat=<?= e($k) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach ?>
</nav>

<?php if (!$reviews): ?>
<div class="empty"><p><?= $status === 'attente' ? 'Aucun avis à relire.' : 'Aucun avis ici.' ?></p></div>
<?php else: ?>
<ul class="lines">
    <?php foreach ($reviews as $r): ?>
    <li>
        <div class="line-main">
            <span class="line-title"><span class="stars" aria-label="<?= (int) $r['rating'] ?> sur 5"><?= str_repeat('★', (int) $r['rating']) ?><span class="stars-off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></span> <?= e($r['author']) ?></span>
            <div class="muted small"><?= e(when($r['created_at'])) ?></div>
            <p class="review-body"><?= nl2br(e($r['body'])) ?></p>
            <form method="post" action="<?= e($base) ?>/avis/action" class="reply-form"><?= csrf_field() ?>
                <input type="hidden" name="do" value="repondre"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
                <label class="small muted" for="reply-<?= e($r['id']) ?>">Votre réponse (affichée sous l'avis)</label>
                <textarea id="reply-<?= e($r['id']) ?>" name="reply" rows="2" maxlength="1000"><?= e((string) $r['reply']) ?></textarea>
                <button class="btn btn-small" type="submit">Enregistrer la réponse</button>
            </form>
        </div>
        <div class="actions">
            <?php if ($r['status'] !== 'publie'): ?>
            <form method="post" action="<?= e($base) ?>/avis/action" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="publier"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
                <button class="btn btn-small btn-primary" type="submit">Afficher</button></form>
            <?php endif ?>
            <?php if ($r['status'] !== 'refuse'): ?>
            <form method="post" action="<?= e($base) ?>/avis/action" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="refuser"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
                <button class="btn btn-small btn-quiet" type="submit"><?= $r['status'] === 'publie' ? 'Ne plus afficher' : 'Refuser' ?></button></form>
            <?php endif ?>
            <form method="post" action="<?= e($base) ?>/avis/action" class="inline" data-confirm="Supprimer définitivement cet avis ?"><?= csrf_field() ?><input type="hidden" name="do" value="supprimer"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
                <button class="btn btn-small btn-quiet" type="submit"><?= icon('trash') ?><span class="visually-hidden">Supprimer</span></button></form>
        </div>
    </li>
    <?php endforeach ?>
</ul>
<?php endif ?>
