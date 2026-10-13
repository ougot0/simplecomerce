<?php
use SimpleCommerce\Services\Billing;
use SimpleCommerce\Support\Text;
/** @var array $viewer @var bool $off @var array $row @var array $settings @var bool $active */
?>
<div class="page-head"><div><h1>Abonnement</h1><p class="page-intro"><a href="/compte">Mon compte</a></p></div></div>
<?php if ($off): ?>
<div class="panel"><p>Rien à payer : vous utilisez Simple Commerce librement.</p></div>
<?php else:
    $price = $settings['priceLabel'] !== '' ? $settings['priceLabel'] : 'l\'abonnement';
    $days = Billing::daysLeft($row); ?>
<div class="panel billing-card">
    <p class="muted small">Votre situation</p>
    <p class="billing-status"><?= tag($active ? ($row['status'] === 'impaye' ? 'draft' : 'live') : 'error', Billing::label($row)) ?></p>
    <?php if ($row['status'] === 'essai' && $active): ?>
    <p>Votre essai gratuit se termine le <strong><?= e(Text::date($row['trial_ends_at'], true, true)) ?></strong> (<?= $days > 1 ? "encore $days jours" : ($days === 1 ? 'demain' : "aujourd'hui") ?>). Abonnez-vous quand vous voulez : le premier paiement n'aura lieu qu'à la fin de l'essai.</p>
    <?php elseif ($row['status'] === 'essai'): ?>
    <p>Votre essai gratuit est terminé. Vos sites restent intacts et vous pouvez toujours les consulter ; pour publier de nouveau, choisissez l'abonnement.</p>
    <?php elseif ($row['status'] === 'actif'): ?>
    <p>Merci ! Vous publiez sans limite.<?= $row['period_end'] ? ' Prochain renouvellement le ' . e(Text::date($row['period_end'], true, true)) . '.' : '' ?></p>
    <?php elseif ($row['status'] === 'impaye'): ?>
    <p>Le dernier paiement n'est pas passé. Stripe va réessayer dans les prochains jours ; vérifiez votre carte pour éviter une coupure.</p>
    <?php elseif ($row['status'] === 'termine'): ?>
    <p>Votre abonnement est arrêté<?= $active && $row['period_end'] ? ' : vous publiez encore jusqu\'au ' . e(Text::date($row['period_end'], true, true)) : '' ?>. Vous pouvez le reprendre à tout moment.</p>
    <?php elseif ($row['status'] === 'gratuit'): ?>
    <p>Votre abonnement vous est offert. Rien à faire.</p>
    <?php endif ?>

    <?php if (in_array($row['status'], ['essai', 'termine'], true)): ?>
    <form method="post" action="/compte/abonnement/payer" class="mt-12"><?= csrf_field() ?>
        <button class="btn btn-primary" type="submit" data-busy="Ouverture de la page de paiement…"<?= $viewer['assist'] ? ' disabled' : '' ?>>S'abonner<?= $settings['priceLabel'] !== '' ? ' — ' . e($settings['priceLabel']) : '' ?></button>
    </form>
    <p class="help mt-6">Le paiement se fait sur la page sécurisée de Stripe. Simple Commerce ne voit jamais votre carte. Sans engagement : vous arrêtez quand vous voulez.</p>
    <?php endif ?>
    <?php if ($row['customer_id'] && $row['status'] !== 'gratuit'): ?>
    <form method="post" action="/compte/abonnement/gerer" class="mt-12"><?= csrf_field() ?>
        <button class="btn" type="submit"<?= $viewer['assist'] ? ' disabled' : '' ?>>Changer de carte, voir mes factures ou arrêter</button>
    </form>
    <?php endif ?>
</div>
<?php endif ?>
