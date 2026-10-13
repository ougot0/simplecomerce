<?php
use SimpleCommerce\Services\Billing;
/** @var array $settings @var bool $ready @var string $keyHint @var string $hookHint @var array $users @var array $rows @var string $webhookUrl */
?>
<div class="page-head"><div><h1>Abonnements</h1><p class="page-intro"><a href="/admin">Administration</a> · Faire payer vos clients chaque mois avec Stripe.</p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
<div class="notice <?= !empty($settings['enabled']) ? 'notice-ok' : 'notice-warn' ?>"><p><strong><?= !empty($settings['enabled']) ? 'Abonnements activés.' : 'Abonnements éteints.' ?></strong>
    <?= !empty($settings['enabled']) ? 'Après leur essai gratuit, vos clients doivent s\'abonner pour publier.' : 'Tout le monde publie librement. Réglez Stripe ci-dessous, puis cochez « Activer ».' ?></p></div>

<div class="two-cols">
<section class="section-block">
    <h2>Réglages</h2>
    <form method="post" action="/admin/abonnements" class="form panel"><?= csrf_field() ?>
        <div class="field"><label for="price-label">Prix affiché aux clients</label><input id="price-label" type="text" name="priceLabel" maxlength="60" placeholder="19 € par mois" value="<?= e($settings['priceLabel']) ?>"></div>
        <div class="field"><label for="trial">Essai gratuit</label><div class="inline-fields"><input id="trial" name="trialDays" type="number" min="0" max="365" class="short" value="<?= (int) $settings['trialDays'] ?>"><span>jours</span></div></div>
        <div class="field"><label for="price-id">Identifiant du prix Stripe</label><input id="price-id" type="text" name="priceId" class="code" placeholder="price_…" value="<?= e($settings['priceId']) ?>">
            <span class="help">Stripe → Catalogue de produits → créez « Simple Commerce » avec un prix récurrent mensuel → copiez son identifiant.</span></div>
        <div class="field"><label for="secret-key">Clé secrète Stripe <?= $keyHint ? '<span class="optional">(enregistrée ' . e($keyHint) . ', laissez vide pour la garder)</span>' : '' ?></label>
            <input id="secret-key" name="secretKey" type="password" autocomplete="off" class="code" placeholder="sk_live_…">
            <span class="help">Stripe → Développeurs → Clés API. Elle est chiffrée et ne sera plus jamais affichée.</span></div>
        <div class="field"><label for="hook">Secret du webhook <?= $hookHint ? '<span class="optional">(enregistré ' . e($hookHint) . ')</span>' : '<span class="optional">(conseillé)</span>' ?></label>
            <input id="hook" name="webhookSecret" type="password" autocomplete="off" class="code" placeholder="whsec_…">
            <span class="help">Stripe → Développeurs → Webhooks → Ajouter une destination. Adresse : <code><?= e($webhookUrl) ?></code> — évènements : <code>checkout.session.completed</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code>. Copiez ensuite son « secret de signature ».</span></div>
        <label class="checkbox"><input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>><span>Activer les abonnements<br><span class="help">Chaque client commence alors son essai gratuit. Vous et les comptes « offerts » ne payez jamais.</span></span></label>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
</section>

<section class="section-block" id="comptes">
    <h2>Comptes</h2>
    <div class="ledger-wrap"><table class="ledger">
        <thead><tr><th>Personne</th><th>Situation</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): if ($u['is_admin']) continue; $r = $rows[$u['id']] ?? null; ?>
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
        </tbody>
    </table></div>
</section>
</div>
