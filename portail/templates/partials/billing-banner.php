<?php
/** Bandeau d'abonnement (essai qui se termine, ou publication bloquée). @var ?array $viewer */
use SimpleCommerce\Services\Billing;
if (!empty($viewer) && !$viewer['effective']['is_admin'] && Billing::enabled() && \SimpleCommerce\Http\Req::path() !== '/compte/abonnement'):
    $bRow = Billing::row($viewer['effective']);
    $bActive = Billing::active($viewer['effective']);
    $bDays = Billing::daysLeft($bRow);
    if (!$bActive): ?>
<div class="notice notice-error billing-banner"><p><strong>Votre essai gratuit est terminé.</strong> Vous pouvez consulter vos sites, mais plus publier. <a href="/compte/abonnement">Choisir l'abonnement</a></p></div>
<?php elseif ($bRow['status'] === 'essai' && $bDays <= 7): ?>
<div class="notice notice-warn billing-banner"><p>Essai gratuit : <?= $bDays > 1 ? "encore $bDays jours" : ($bDays === 1 ? 'dernier jour demain' : "dernier jour aujourd'hui") ?>. <a href="/compte/abonnement">S'abonner</a></p></div>
<?php elseif ($bRow['status'] === 'impaye'): ?>
<div class="notice notice-warn billing-banner"><p>Le dernier paiement de votre abonnement n'est pas passé. <a href="/compte/abonnement">Vérifier ma carte</a></p></div>
<?php endif; endif ?>
