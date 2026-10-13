<?php
use SimpleCommerce\Services\Orders;
use SimpleCommerce\Support\Text;
/** @var array $orders @var array $counts @var ?string $status @var bool $enabled @var array $waiting @var string $base @var string $role */
$next = ['nouvelle' => ['preparee', 'Marquer préparée'], 'preparee' => ['envoyee', 'Marquer remise ou envoyée']];
?>
<div class="page-head">
    <div><h1>Commandes</h1><p class="page-intro">Les commandes envoyées depuis votre site. Le paiement se règle directement avec votre client.</p></div>
    <?php if ($orders || array_sum($counts)): ?><a class="btn btn-small" href="<?= e($base) ?>/commandes.csv"><?= icon('download') ?> Télécharger (tableur)</a><?php endif ?>
</div>

<?php if (!$enabled): ?>
<div class="notice"><p>Les commandes ne sont pas ouvertes : vos visiteurs voient vos produits mais ne peuvent pas les commander depuis votre site.
<?php if ($role !== 'editor'): ?><a href="<?= e($base) ?>/reglages#boutique">Ouvrir les commandes dans les réglages</a>.<?php endif ?></p></div>
<?php endif ?>

<nav class="tabs" aria-label="Filtrer les commandes">
    <a href="<?= e($base) ?>/commandes"<?= $status === null ? ' aria-current="page"' : '' ?>>Toutes (<?= array_sum($counts) ?>)</a>
    <?php foreach (Orders::STATUSES as $k => $label): ?>
    <a href="<?= e($base) ?>/commandes?etat=<?= e($k) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach ?>
</nav>

<?php if (!$orders): ?>
<div class="empty"><p><?= $status ? 'Aucune commande dans cet état.' : 'Pas encore de commande.' ?></p></div>
<?php else: ?>
<ul class="lines orders">
    <?php foreach ($orders as $o): ?>
    <li>
        <div class="line-main">
            <span class="line-title">N° <?= (int) $o['number'] ?> — <?= e($o['customer_name']) ?> · <?= e(Text::price((float) $o['total'])) ?></span>
            <div class="muted small"><?= e(when($o['created_at'])) ?> · <a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a><?= $o['phone'] ? ' · <a href="tel:' . e(preg_replace('/[^\d+]/', '', $o['phone'])) . '">' . e($o['phone']) . '</a>' : '' ?></div>
            <ul class="order-items">
                <?php foreach ($o['items'] as $i): ?><li><?= (int) $i['qte'] ?> × <?= e($i['nom']) ?><?= $i['prix'] !== null ? ' — ' . e(Text::price($i['prix'] * $i['qte'])) : '' ?></li><?php endforeach ?>
            </ul>
            <div class="small mt-6"><a href="<?= e($base) ?>/commandes/<?= e($o['id']) ?>/bon" target="_blank" rel="noopener">Bon de commande</a><?php if ($o['status'] !== 'annulee'): ?> · <a href="<?= e($base) ?>/commandes/<?= e($o['id']) ?>/facture" target="_blank" rel="noopener">Facture</a><?php endif ?></div>
            <?php if ($o['address']): ?><div class="small"><span class="muted">Adresse :</span> <?= nl2br(e($o['address'])) ?></div><?php endif ?>
            <?php if ($o['note']): ?><div class="small"><span class="muted">Message :</span> <?= nl2br(e($o['note'])) ?></div><?php endif ?>
        </div>
        <div class="actions">
            <?= tag(['nouvelle' => 'live', 'preparee' => 'draft', 'envoyee' => 'off', 'annulee' => 'error'][$o['status']] ?? 'off', Orders::STATUSES[$o['status']] ?? $o['status']) ?>
            <?php if (isset($next[$o['status']])): ?>
            <form method="post" action="<?= e($base) ?>/commandes/etat" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="status" value="<?= e($next[$o['status']][0]) ?>"><input type="hidden" name="back" value="<?= e($status ?? '') ?>">
                <button class="btn btn-small btn-primary" type="submit"><?= e($next[$o['status']][1]) ?></button></form>
            <?php endif ?>
            <?php if ($o['status'] !== 'annulee'): ?>
            <form method="post" action="<?= e($base) ?>/commandes/etat" class="inline" data-confirm="Annuler la commande n° <?= (int) $o['number'] ?> ? Les produits seront remis en stock."><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="status" value="annulee"><input type="hidden" name="back" value="<?= e($status ?? '') ?>">
                <button class="btn btn-small btn-quiet" type="submit">Annuler</button></form>
            <?php else: ?>
            <form method="post" action="<?= e($base) ?>/commandes/etat" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($o['id']) ?>"><input type="hidden" name="status" value="nouvelle"><input type="hidden" name="back" value="<?= e($status ?? '') ?>">
                <button class="btn btn-small btn-quiet" type="submit">Reprendre</button></form>
            <?php endif ?>
        </div>
    </li>
    <?php endforeach ?>
</ul>
<?php endif ?>

<section class="section-block">
    <h2>Clients qui attendent un retour en stock</h2>
    <p class="muted">Quand un produit est épuisé, vos visiteurs peuvent laisser leur e-mail. Dès que vous remettez du stock, ils reçoivent un message automatiquement.</p>
    <?php if (!$waiting): ?>
    <p class="muted">Personne n'attend pour le moment.</p>
    <?php else: ?>
    <ul class="lines narrow-block">
        <?php foreach ($waiting as $w): ?>
        <li><div class="line-main"><span class="line-title"><?= e($w['name']) ?></span><div class="muted small"><?= e(Text::plural(count($w['emails']), 'personne')) ?> : <?= e(implode(', ', array_slice($w['emails'], 0, 8))) ?><?= count($w['emails']) > 8 ? '…' : '' ?></div></div></li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
