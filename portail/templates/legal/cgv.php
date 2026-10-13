<?php
include __DIR__ . '/_head.php';
use SimpleCommerce\Services\Billing;
$plans = Billing::plans();
$s = Billing::settings();
$free = Billing::freeSentence();
$monthly = Billing::euros((float) $s['monthly']);
$yearly = Billing::euros((float) $s['yearly']);
?>
<div class="prose legal">
<h2>1. Objet et champ d'application</h2>
<p>Les présentes conditions générales de vente (CGV) s'appliquent à l'abonnement au service <strong>Simple Commerce</strong>, vendu par <?= $L('nom') ?>, <?= $L('adresse') ?>, SIRET <?= $L('siret') ?> (le « Vendeur »), à toute personne qui s'abonne depuis <?= $site ?> (le « Client »). Elles complètent les <a href="/conditions-generales-utilisation">conditions générales d'utilisation</a>. S'abonner vaut acceptation des présentes CGV.</p>
<p>Le service s'adresse principalement aux professionnels (commerçants, artisans, indépendants). Les règles propres aux consommateurs s'appliquent lorsque le Client agit à titre personnel (voir article 7).</p>

<h2>2. Service vendu</h2>
<p>L'abonnement donne accès au portail Simple Commerce pour modifier le contenu de ses propres sites, avec les fonctions décrites sur le site au moment de la souscription. Sans abonnement, le Client peut consulter ses sites dans le portail mais pas les modifier.</p>

<h2>3. Prix</h2>
<p>Formules proposées :</p>
<ul>
    <li><strong>Mensuelle : <?= e($monthly) ?> par mois</strong></li>
    <li><strong>Annuelle : <?= e($yearly) ?> par an</strong></li>
</ul>
<p>Prix en euros. <?= $L('tva') ?>.</p>
<?php if ($free !== ''): ?>
<p><strong>Offre de lancement : <?= e(mb_strtolower(mb_substr($free, 0, 1)) . mb_substr($free, 1)) ?>.</strong> Une carte bancaire est demandée à la souscription, mais aucun montant n'est prélevé pendant cette période. Le premier paiement a lieu à la fin de la période gratuite, puis à chaque échéance. Le Client peut arrêter avant la fin de la période gratuite sans rien payer. La période gratuite n'est accordée qu'une fois par Client.</p>
<?php endif ?>
<p>Le Vendeur peut modifier ses prix. Les nouveaux prix ne s'appliquent qu'aux nouveaux abonnements ; un Client déjà abonné garde son prix, sauf information préalable d'au moins 30 jours lui laissant la possibilité d'arrêter.</p>

<h2>4. Paiement</h2>
<p>Le paiement se fait par carte bancaire (ou Apple Pay, Google Pay…) sur la page sécurisée de notre prestataire <strong>Stripe</strong>. Le Vendeur n'a jamais accès aux numéros de carte. L'abonnement est prélevé automatiquement à chaque échéance (chaque mois ou chaque année selon la formule). Une facture est disponible dans « Mon compte → Abonnement ».</p>
<p>En cas d'échec de paiement, Stripe réessaie pendant quelques jours. Si le paiement n'aboutit pas, l'abonnement est arrêté et le Client ne peut plus modifier ses sites jusqu'à un nouveau paiement. Ses sites et son contenu ne sont pas effacés.</p>

<h2>5. Durée, renouvellement et arrêt</h2>
<p>L'abonnement est <strong>sans engagement</strong> et se renouvelle automatiquement à chaque échéance. Le Client peut l'arrêter à tout moment, en un clic, depuis « Mon compte → Abonnement ». L'arrêt prend effet à la fin de la période déjà payée : le Client garde l'accès jusqu'à cette date. Les périodes commencées ne sont pas remboursées.</p>
<p>Le Vendeur peut arrêter l'abonnement d'un Client qui ne respecte pas les conditions générales d'utilisation, après l'en avoir informé.</p>

<h2>6. Livraison</h2>
<p>Le service est accessible en ligne dès la souscription.</p>

<h2>7. Droit de rétractation (consommateurs)</h2>
<p>Le Client consommateur dispose d'un délai de 14 jours à compter de la souscription pour se rétracter, sans avoir à se justifier, en écrivant à <a href="mailto:<?= $L('email') ?>"><?= $L('email') ?></a> ou en arrêtant son abonnement. En demandant à utiliser le service pendant ce délai, il accepte de payer le service fourni jusqu'à sa rétractation. Pendant la période gratuite, aucune somme n'est due. Les professionnels ne bénéficient pas de ce droit, sauf exceptions prévues par la loi.</p>

<h2>8. Responsabilité</h2>
<p>Les règles de responsabilité sont celles des <a href="/conditions-generales-utilisation">conditions générales d'utilisation</a> (article 6).</p>

<h2>9. Réclamations et médiation</h2>
<p>Toute réclamation peut être adressée à <a href="mailto:<?= $L('email') ?>"><?= $L('email') ?></a>. Le Client consommateur peut aussi recourir gratuitement à un médiateur de la consommation ou à la plateforme européenne de règlement en ligne des litiges : <a href="https://ec.europa.eu/consumers/odr" rel="noopener">ec.europa.eu/consumers/odr</a>.</p>

<h2>10. Droit applicable</h2>
<p>Les présentes CGV sont soumises au droit français. À défaut d'accord amiable, les tribunaux français sont compétents ; pour un Client professionnel, ceux du ressort du siège du Vendeur.</p>
</div>
