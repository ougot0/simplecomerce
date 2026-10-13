<?php
use SimpleCommerce\Services\Billing;
use SimpleCommerce\Support\Text;
/** @var ?array $report @var ?string $error @var array $local @var int $users @var bool $ready */
$months = ['01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril', '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août', '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'];
?>
<div class="page-head"><div><h1>Revenus</h1><p class="page-intro"><a href="/admin">Administration</a> · Chiffres lus en direct chez Stripe. <a href="/admin/abonnements">Réglages des abonnements</a></p></div></div>
<?php if (!$ready): ?>
<div class="notice notice-warn"><p>Les abonnements ne sont pas encore reliés à Stripe. <a href="/admin/abonnements">Les régler</a>.</p></div>
<?php elseif ($error): ?>
<div class="notice notice-error"><p><?= e($error) ?></p></div>
<?php else: ?>
<div class="stats">
    <div class="stat"><span class="stat-num"><?= e(Billing::euros($report['mrr'])) ?></span><span class="muted">revenu chaque mois<br><span class="small">(abonnés qui paient ; l'année compte pour 1/12)</span></span></div>
    <div class="stat"><span class="stat-num"><?= e(Billing::euros($report['paid30'])) ?></span><span class="muted">encaissés ces 30 derniers jours</span></div>
    <div class="stat"><span class="stat-num"><?= e(Billing::euros($report['paidYear'])) ?></span><span class="muted">encaissés depuis le 1<sup>er</sup> janvier</span></div>
    <div class="stat"><span class="stat-num"><?= e(Billing::euros($report['futureMrr'])) ?></span><span class="muted">en plus chaque mois après les périodes gratuites</span></div>
</div>
<div class="stats mt-12">
    <div class="stat"><span class="stat-num"><?= (int) $report['active'] ?></span><span class="muted"><?= $report['active'] > 1 ? 'abonnés qui paient' : 'abonné qui paie' ?></span></div>
    <div class="stat"><span class="stat-num"><?= (int) $report['trialing'] ?></span><span class="muted">en période gratuite</span></div>
    <div class="stat<?= $report['late'] ? ' stat-alert' : '' ?>"><span class="stat-num"><?= (int) $report['late'] ?></span><span class="muted">paiement en retard</span></div>
    <div class="stat"><span class="stat-num"><?= (int) $report['canceled30'] ?></span><span class="muted"><?= $report['canceled30'] > 1 ? 'arrêts' : 'arrêt' ?> ces 30 derniers jours</span></div>
</div>
<div class="two-cols">
    <section class="section-block">
        <h2>Formules choisies</h2>
        <table class="ledger"><tbody>
            <tr><td>Au mois</td><td class="shrink num"><?= (int) $report['monthlyPlans'] ?></td></tr>
            <tr><td>À l'année</td><td class="shrink num"><?= (int) $report['yearlyPlans'] ?></td></tr>
        </tbody></table>
    </section>
    <section class="section-block">
        <h2>Fins de période gratuite à venir</h2>
        <?php if (!$report['trialEnds']): ?><p class="muted">Aucune pour le moment.</p><?php else: ?>
        <table class="ledger"><tbody>
            <?php foreach ($report['trialEnds'] as $ym => $n): ?><tr><td><?= e(ucfirst($months[substr($ym, 5, 2)] ?? $ym) . ' ' . substr($ym, 0, 4)) ?></td><td class="shrink num"><?= (int) $n ?></td></tr><?php endforeach ?>
        </tbody></table>
        <p class="muted small">Chacun de ces clients sera prélevé pour la première fois ce mois-là (s'il n'arrête pas avant).</p>
        <?php endif ?>
    </section>
</div>
<?php endif ?>
<section class="section-block">
    <h2>Comptes</h2>
    <p><?= e(Text::plural($users, 'compte')) ?> sur le portail · <?= (int) ($local['gratuit'] ?? 0) ?> accès offert<?= ($local['gratuit'] ?? 0) > 1 ? 's' : '' ?> · <?= (int) ($local['essai'] ?? 0) ?> pas encore abonné<?= ($local['essai'] ?? 0) > 1 ? 's' : '' ?></p>
    <p class="muted small">Le détail de chaque paiement et les virements vers votre banque sont dans votre tableau de bord Stripe.</p>
</section>
