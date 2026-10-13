<?php
use SimpleCommerce\Services\Billing;
use SimpleCommerce\Support\Text;
/** @var array $viewer @var bool $off @var array $row @var array $settings @var bool $active */
?>
<div class="page-head"><div><h1>Abonnement</h1><p class="page-intro"><a href="/compte">Mon compte</a></p></div></div>
<?php if ($off): ?>
<div class="panel"><p>Rien à payer : vous utilisez Simple Commerce librement.</p></div>
<?php else:
    $days = Billing::daysLeft($row); ?>
<div class="panel billing-card">
    <p class="muted small">Votre situation</p>
    <p class="billing-status"><?= tag($active ? ($row['status'] === 'impaye' ? 'draft' : 'live') : 'error', Billing::label($row)) ?></p>
    <?php $trial = (int) $settings['trialDays']; $plans = Billing::plans(); ?>
    <?php if ($row['status'] === 'essai' && $active): ?>
    <p>Un accès d'essai vous est offert jusqu'au <strong><?= e(Text::date($row['trial_ends_at'], true, true)) ?></strong>. Abonnez-vous quand vous voulez pour continuer ensuite.</p>
    <?php elseif ($row['status'] === 'essai'): ?>
    <p>Pour modifier vos sites, choisissez votre abonnement.<?= $trial > 0 ? ' <strong>' . e(Billing::freeSentence()) . "</strong> : votre carte est demandée, mais rien n'est prélevé avant le " . e(Text::date(gmdate('Y-m-d', time() + $trial * 86400), true, true)) . '. Vous pouvez arrêter avant sans rien payer.' : '' ?></p>
    <?php elseif (Billing::inTrial($row)): ?>
    <p>Merci ! Vous profitez de vos jours gratuits jusqu'au <strong><?= e(Text::date($row['trial_ends_at'], true, true)) ?></strong>. Le premier paiement aura lieu ce jour-là, sur la carte que vous avez donnée. Vous pouvez arrêter avant sans rien payer.</p>
    <?php elseif ($row['status'] === 'actif'): ?>
    <p>Merci ! Vous publiez sans limite.<?= $row['period_end'] ? ' Prochain paiement le ' . e(Text::date($row['period_end'], true, true)) . '.' : '' ?></p>
    <?php elseif ($row['status'] === 'impaye'): ?>
    <p>Le dernier paiement n'est pas passé. Stripe va réessayer dans les prochains jours ; vérifiez votre carte pour éviter une coupure.</p>
    <?php elseif ($row['status'] === 'termine'): ?>
    <p>Votre abonnement est arrêté<?= $active && $row['period_end'] ? ' : vous publiez encore jusqu\'au ' . e(Text::date($row['period_end'], true, true)) : '' ?>. Vous pouvez le reprendre à tout moment.</p>
    <?php elseif ($row['status'] === 'gratuit'): ?>
    <p>Votre abonnement vous est offert. Rien à faire.</p>
    <?php endif ?>

    <?php if (\SimpleCommerce\Http\Req::inApp()): ?>
    <p class="help mt-6">L'abonnement ne se gère pas depuis l'application.</p>
    <?php elseif (in_array($row['status'], ['essai', 'termine'], true)): ?>
    <div class="plan-choices">
        <?php foreach (Billing::plans() as $key => $plan): ?>
        <form method="post" action="/compte/abonnement/payer" class="plan-choice"><?= csrf_field() ?><input type="hidden" name="plan" value="<?= e($key) ?>">
            <span class="plan-price"><?= e($plan['label']) ?></span>
            <?php if ($key === 'an' && isset(Billing::plans()['mois']) && $plan['amount'] < Billing::plans()['mois']['amount'] * 12): ?><span class="muted small"><?= e(Billing::euros(Billing::plans()['mois']['amount'] * 12 - $plan['amount'])) ?> d'économie par an</span><?php endif ?>
            <button class="btn btn-primary" type="submit" data-busy="Ouverture de la page de paiement…"<?= $viewer['assist'] ? ' disabled' : '' ?>>Choisir <?= $key === 'an' ? 'l\'année' : 'le mois' ?></button>
        </form>
        <?php endforeach ?>
    </div>
    <p class="help mt-6">Le paiement se fait par carte sur la page sécurisée de Stripe. Simple Commerce ne voit jamais votre carte. Sans engagement : vous arrêtez quand vous voulez. En vous abonnant, vous acceptez les <a href="/conditions-generales-vente">conditions générales de vente</a>.</p>
    <?php endif ?>
    <?php if ($row['customer_id'] && $row['status'] !== 'gratuit' && !\SimpleCommerce\Http\Req::inApp()): ?>
    <form method="post" action="/compte/abonnement/gerer" class="mt-12"><?= csrf_field() ?>
        <button class="btn" type="submit"<?= $viewer['assist'] ? ' disabled' : '' ?>>Changer de carte, voir mes factures ou arrêter</button>
    </form>
    <?php endif ?>
</div>
<?php endif ?>
