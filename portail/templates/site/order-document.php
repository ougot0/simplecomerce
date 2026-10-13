<?php
use SimpleCommerce\Support\Text;
/** @var array $site @var array $order @var bool $invoice @var ?string $number @var array $x */
$o = $order;
$seller = trim((string) ($x['legalName'] ?? '')) ?: $site['name'];
$invDate = $invoice ? (\SimpleCommerce\Repo::meta('invoiceDate:' . $o['id']) ?? date('Y-m-d')) : null;
?>
<div class="doc-actions no-print"><button type="button" class="btn btn-primary" data-print>Imprimer ou enregistrer en PDF</button> <span class="muted small">Dans la fenêtre d'impression, choisissez « Enregistrer au format PDF » pour obtenir un fichier.</span></div>
<article class="doc">
    <header class="doc-head">
        <div>
            <strong class="doc-seller"><?= e($seller) ?></strong>
            <?php if (!empty($x['legalAddress'])): ?><div><?= nl2br(e($x['legalAddress'])) ?></div><?php endif ?>
            <?php if (!empty($x['legalSiret'])): ?><div>SIRET : <?= e($x['legalSiret']) ?></div><?php endif ?>
            <?php if ($site['public_url']): ?><div><?= e(preg_replace('#^https?://#', '', rtrim($site['public_url'], '/'))) ?></div><?php endif ?>
        </div>
        <div class="doc-title">
            <h1><?= $invoice ? 'Facture' : 'Bon de commande' ?></h1>
            <div><?= $invoice ? 'N° ' . e($number) : 'Commande n° ' . (int) $o['number'] ?></div>
            <div>Date : <?= e(Text::date($invoice ? $invDate : $o['created_at'], true)) ?></div>
            <?php if ($invoice): ?><div class="muted">Commande n° <?= (int) $o['number'] ?> du <?= e(Text::date($o['created_at'], true)) ?></div><?php endif ?>
        </div>
    </header>
    <section class="doc-client">
        <div class="muted small"><?= $invoice ? 'Facturé à' : 'Client' ?></div>
        <strong><?= e($o['customer_name']) ?></strong>
        <?php if ($o['address']): ?><div><?= nl2br(e($o['address'])) ?></div><?php endif ?>
        <div><?= e($o['email']) ?><?= $o['phone'] ? ' · ' . e($o['phone']) : '' ?></div>
    </section>
    <table class="doc-table">
        <thead><tr><th>Produit</th><th class="num">Quantité</th><th class="num">Prix unitaire</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($o['items'] as $i): ?>
            <tr><td><?= e($i['nom']) ?></td><td class="num"><?= (int) $i['qte'] ?></td><td class="num"><?= $i['prix'] !== null ? e(Text::price($i['prix'])) : '—' ?></td><td class="num"><?= $i['prix'] !== null ? e(Text::price($i['prix'] * $i['qte'])) : '—' ?></td></tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><td colspan="3">Total<?= $invoice && !empty($x['legalTva']) && stripos((string) $x['legalTva'], '293') !== false ? '' : ' TTC' ?></td><td class="num"><?= e(Text::price((float) $o['total'])) ?></td></tr></tfoot>
    </table>
    <?php if ($o['note']): ?><p class="doc-note"><span class="muted">Message du client :</span> <?= nl2br(e($o['note'])) ?></p><?php endif ?>
    <footer class="doc-foot small">
        <?php if (!empty($x['legalTva'])): ?><p><?= e($x['legalTva']) ?></p><?php endif ?>
        <?php if ($invoice): ?><p>Facture établie le <?= e(Text::date($invDate, true)) ?>. Paiement réglé directement auprès de <?= e($seller) ?>.</p><?php endif ?>
        <?php if ($invoice && (empty($x['legalAddress']) || empty($x['legalSiret']))): ?><p class="no-print error-text">Il manque votre adresse ou votre SIRET : complétez-les dans Réglages → Boutique pour que la facture soit valable.</p><?php endif ?>
    </footer>
</article>
