<?php
use SimpleCommerce\Services\Billing;
/** @var array $settings @var bool $ready @var string $keyHint @var string $hookHint @var array $users @var array $rows @var string $webhookUrl */
$on = !empty($settings['enabled']) && $ready;
?>
<div class="page-head"><div><h1>Abonnements</h1><p class="page-intro"><a href="/admin">Administration</a> · Vos clients vous paient chaque mois (ou chaque année) par carte, avec Stripe. L'argent arrive sur votre compte bancaire.</p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><p><?= e($error) ?></p></div><?php endif ?>

<?php if ($on): ?>
<div class="notice notice-ok"><p><strong>Abonnements activés.</strong> Formules proposées : <?= e(implode(' ou ', array_column(Billing::plans(), 'label'))) ?>. Essai gratuit : <?= (int) $settings['trialDays'] ?> jours.<?= $settings['promoCode'] !== '' ? ' Code promo gratuit : <strong>' . e($settings['promoCode']) . '</strong>.' : '' ?>
    <?= $hookHint === '' ? '<br><strong>Attention :</strong> les avis de paiement ne sont pas branchés (le portail doit être en https). Les abonnements fonctionnent quand même au retour du paiement, mais un arrêt ou un impayé ne sera pas vu.' : '' ?></p>
    <form method="post" action="/admin/abonnements" class="inline" data-confirm="Éteindre les abonnements ? Tout le monde pourra de nouveau publier sans payer."><?= csrf_field() ?><input type="hidden" name="intent" value="eteindre"><button class="btn btn-small btn-quiet" type="submit">Éteindre</button></form></div>
<?php else: ?>
<div class="notice notice-warn"><p><strong>Abonnements éteints.</strong> Tout le monde publie librement. Suivez les 3 étapes ci-dessous pour les activer.</p>
    <?php if ($ready): ?><form method="post" action="/admin/abonnements" class="inline"><?= csrf_field() ?><input type="hidden" name="intent" value="allumer"><button class="btn btn-small btn-primary" type="submit">Rallumer</button></form><?php endif ?></div>
<?php endif ?>

<div class="two-cols">
<section class="section-block">
    <h2><?= $ready ? 'Modifier les prix' : 'Activer les abonnements' ?></h2>
    <ol class="steps-list">
        <li>Créez un compte gratuit sur <strong>stripe.com</strong> et renseignez votre compte bancaire (Stripe vous le demande : c'est là que l'argent arrive).</li>
        <li>Dans Stripe : <strong>Développeurs → Clés API → Clé secrète → Révéler</strong>, puis copiez-la (elle commence par <code>sk_live_</code>).</li>
        <li>Collez-la ici, vérifiez les prix, puis cliquez sur le bouton. Simple Commerce crée tout seul l'abonnement chez Stripe.</li>
    </ol>
    <form method="post" action="/admin/abonnements" class="form panel"><?= csrf_field() ?>
        <div class="field"><label for="secret-key">Clé secrète Stripe <?= $keyHint ? '<span class="optional">(enregistrée ' . e($keyHint) . ' — laissez vide pour la garder)</span>' : '' ?></label>
            <input id="secret-key" name="secretKey" type="password" autocomplete="off" class="code" placeholder="sk_live_…">
            <span class="help">Elle est chiffrée et ne sera plus jamais affichée. Pour faire un essai sans vrai paiement, utilisez la clé « sk_test_ » (carte d'essai 4242 4242 4242 4242).</span></div>
        <div class="field"><label for="monthly">Prix par mois</label><div class="inline-fields"><input id="monthly" name="monthly" type="text" inputmode="decimal" class="short" value="<?= e(str_replace('.', ',', (string) (float) $settings['monthly'])) ?>"><span>€ (0 = pas de formule mensuelle)</span></div></div>
        <div class="field"><label for="yearly">Prix par an</label><div class="inline-fields"><input id="yearly" name="yearly" type="text" inputmode="decimal" class="short" value="<?= e(str_replace('.', ',', (string) (float) $settings['yearly'])) ?>"><span>€ (90 € = 2 mois offerts ; 0 = pas de formule annuelle)</span></div></div>
        <div class="field"><label for="trial">Essai gratuit</label><div class="inline-fields"><input id="trial" name="trialDays" type="number" min="0" max="365" class="short" value="<?= (int) $settings['trialDays'] ?>"><span>jours (0 = payer tout de suite)</span></div></div>
        <div class="field"><label for="promo">Code promo gratuit <span class="optional">(pour vos tests ou vos amis)</span></label>
            <input id="promo" name="promoCode" type="text" maxlength="30" class="medium code" value="<?= e($settings['promoCode'] !== '' ? $settings['promoCode'] : 'GRATUIT100') ?>">
            <span class="help">Sur la page de paiement, cliquez sur « Ajouter un code promotionnel » et tapez ce code : l'abonnement coûte 0 €, sans carte. Laissez vide pour ne pas en créer.</span></div>
        <div class="actions"><button class="btn btn-primary" type="submit" data-busy="Préparation chez Stripe…"><?= $ready ? 'Enregistrer les prix' : 'Préparer Stripe et activer les abonnements' ?></button></div>
        <p class="help">Changer un prix ne change pas celui des clients déjà abonnés : seuls les nouveaux paient le nouveau prix.</p>
    </form>
</section>

<section class="section-block" id="comptes">
    <h2>Comptes</h2>
    <div class="ledger-wrap"><table class="ledger">
        <thead><tr><th>Personne</th><th>Situation</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php $any = false; foreach ($users as $u): if ($u['is_admin']) continue; $any = true; $r = $rows[$u['id']] ?? null; ?>
        <tr>
            <td class="title-cell"><?= e($u['name'] ?: $u['email']) ?><div class="muted small"><?= e($u['email']) ?></div></td>
            <td class="small"><?= $r ? e(Billing::label($r)) . ($r['status'] === 'essai' && Billing::label($r) !== 'Essai terminé' ? ' <span class="muted">jusqu\'au ' . e(\SimpleCommerce\Support\Text::date($r['trial_ends_at'], true)) . '</span>' : '') : '<span class="faint">pas encore venu</span>' ?></td>
            <td class="shrink"><form method="post" action="/admin/abonnements" class="inline"><?= csrf_field() ?><input type="hidden" name="intent" value="compte"><input type="hidden" name="userId" value="<?= e($u['id']) ?>">
                <?php if (($r['status'] ?? '') !== 'gratuit'): ?>
                <button class="btn btn-small" name="status" value="gratuit" type="submit">Offrir</button>
                <button class="btn btn-small btn-quiet" name="status" value="essai" type="submit">+30 jours d'essai</button>
                <?php else: ?>
                <button class="btn btn-small btn-quiet" name="status" value="normal" type="submit">Ne plus offrir</button>
                <?php endif ?>
            </form></td>
        </tr>
        <?php endforeach ?>
        <?php if (!$any): ?><tr><td colspan="3" class="muted">Pas encore de client.</td></tr><?php endif ?>
        </tbody>
    </table></div>
    <p class="muted small mt-12">« Offrir » : la personne publie sans payer (un ami, un client de test…). Vous, administrateur, ne payez jamais.</p>
</section>
</div>
