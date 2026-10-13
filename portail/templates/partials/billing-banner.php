<?php
/** Bandeau d'abonnement (essai qui se termine, ou publication bloquée). @var ?array $viewer */
use SimpleCommerce\Services\Billing;
if (!empty($viewer) && !$viewer['effective']['is_admin'] && Billing::enabled() && \SimpleCommerce\Http\Req::path() !== '/compte/abonnement'):
    $bRow = Billing::row($viewer['effective']);
    $bActive = Billing::active($viewer['effective']);
    $bDays = Billing::daysLeft($bRow);
    if (!$bActive): ?>
<div class="notice notice-warn billing-banner"><p><strong>Pour modifier vos sites, choisissez votre abonnement.</strong><?= Billing::settings()['trialDays'] > 0 ? ' Les ' . (int) Billing::settings()['trialDays'] . ' premiers jours sont gratuits.' : '' ?> <a href="/compte/abonnement">Choisir mon abonnement</a></p></div>
<?php elseif ($bRow['status'] === 'essai' && $bDays <= 7): ?>
<div class="notice notice-warn billing-banner"><p>Accès d'essai offert : <?= $bDays > 1 ? "encore $bDays jours" : ($bDays === 1 ? 'dernier jour demain' : "dernier jour aujourd'hui") ?>. <a href="/compte/abonnement">S'abonner</a></p></div>
<?php elseif ($bRow['status'] === 'impaye'): ?>
<div class="notice notice-warn billing-banner"><p>Le dernier paiement de votre abonnement n'est pas passé. <a href="/compte/abonnement">Vérifier ma carte</a></p></div>
<?php endif; endif ?>
