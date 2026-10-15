<?php /** @var array $viewer */ $u = $viewer['user']; ?>
<div class="page-head"><div><h1>Mon compte</h1><p class="page-intro"><?= e($u['email']) ?></p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if (!empty($resetHint)): ?><div class="notice notice-warn"><p>Vous êtes connecté. Choisissez maintenant un nouveau mot de passe ci-dessous.</p></div><?php endif ?>
<?php if (\SimpleCommerce\Services\Features::on('parrainage') && \SimpleCommerce\Services\Billing::enabled() && !$viewer['effective']['is_admin']): ?>
<p class="mb-12"><a class="btn" href="/compte/abonnement">Mon abonnement</a></p>
<?php endif ?>
<div class="stack-40">
    <?php if (\SimpleCommerce\Services\Billing::enabled() && !$viewer['effective']['is_admin'] && !$viewer['assist'] && !\SimpleCommerce\Http\Req::inApp()): $ref = \SimpleCommerce\Services\Referral::stats($u); ?>
    <section>
        <h2 class="mb-14">Parrainage : un mois offert</h2>
        <div class="panel">
            <p>Partagez ce lien avec un commerçant ou un artisan. Quand il paie son abonnement pour la première fois, <strong>vous gagnez un mois gratuit</strong>, déduit de votre prochain paiement. Sans limite.</p>
            <div class="copy-row"><input type="text" readonly value="<?= e(\SimpleCommerce\Services\Referral::link($u)) ?>" class="code" aria-label="Votre lien de parrainage" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div>
            <p class="muted small mt-6"><?= e(\SimpleCommerce\Support\Text::plural($ref['count'], 'personne inscrite', 'personnes inscrites')) ?> avec votre lien · <?= e(\SimpleCommerce\Support\Text::plural($ref['months'], 'mois gagné', 'mois gagnés')) ?></p>
        </div>
    </section>
    <?php endif ?>
    <section>
        <h2 class="mb-14">Votre nom</h2>
        <form method="post" class="form panel"><?= csrf_field() ?><input type="hidden" name="intent" value="name">
            <div class="field"><label for="name">Prénom et nom</label><input id="name" name="name" type="text" value="<?= e($u['name']) ?>" autocomplete="name"></div>
            <div class="actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
    </section>
    <section>
        <h2 class="mb-14">Avis par e-mail</h2>
        <form method="post" class="form panel"><?= csrf_field() ?><input type="hidden" name="intent" value="notify">
            <input type="hidden" name="notify" value="0">
            <label class="checkbox"><input type="checkbox" name="notify" value="1" <?= $u['notify'] ? 'checked' : '' ?>>
                <span>Me prévenir quand quelqu'un d'autre modifie un de mes sites<br><span class="help">Au plus un e-mail toutes les 30 minutes par site. Utile si vous travaillez à plusieurs.</span></span></label>
            <div class="actions"><button class="btn" type="submit">Enregistrer</button></div>
        </form>
    </section>
    <section>
        <h2 class="mb-14">Mot de passe</h2>
        <form method="post" class="form panel"><?= csrf_field() ?><input type="hidden" name="intent" value="password">
            <?php if ((int) \SimpleCommerce\Support\Session::get('magic_at') <= time() - 1800): ?>
            <div class="field"><label for="current">Mot de passe actuel</label><input id="current" name="current" type="password" autocomplete="current-password"></div>
            <?php endif ?>
            <div class="field"><label for="password">Nouveau mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password"><span class="help">10 caractères minimum, avec au moins un chiffre.</span></div>
            <div class="field"><label for="confirm">Encore une fois</label><input id="confirm" name="confirm" type="password" autocomplete="new-password"></div>
            <div class="actions"><button class="btn" type="submit">Changer le mot de passe</button></div>
        </form>
    </section>
    <?php if (!$viewer['assist']): $plan = $deletePlan ?? []; ?>
    <section id="supprimer">
        <h2 class="mb-14">Supprimer mon compte</h2>
        <form method="post" class="form panel"><?= csrf_field() ?><input type="hidden" name="intent" value="delete">
            <?php if (!empty($deleteError)): ?><div class="notice notice-error" role="alert"><?= e($deleteError) ?></div><?php endif ?>
            <p>Votre compte et vos accès sont effacés de Simple Commerce. <strong>Vos sites ne sont pas touchés</strong> : ils restent en ligne tels quels.</p>
            <?php if ($plan): ?>
            <ul class="list-plain">
                <?php foreach ($plan as $item): ?>
                <li><strong><?= e($item['site']['name']) ?></strong> : <?= $item['fate'] === 'retire' ? 'retiré de Simple Commerce (personne d\'autre ne s\'en occupe)' : ($item['fate'] === 'transmis' ? 'reste géré par ' . e($item['others'][0]['user']['name'] ?: $item['others'][0]['user']['email']) . (count($item['others']) > 1 ? ' et les autres membres' : '') : 'vous le quittez, les autres membres le gardent') ?>.</li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <?php if (\SimpleCommerce\Services\Billing::enabled() && !$viewer['effective']['is_admin']): ?>
            <p class="help">Si vous avez un abonnement, il est arrêté tout de suite : plus aucun prélèvement.</p>
            <?php endif ?>
            <?php if ((int) \SimpleCommerce\Support\Session::get('magic_at') <= time() - 1800): ?>
            <div class="field"><label for="del-current">Votre mot de passe</label><input id="del-current" name="current" type="password" autocomplete="current-password"></div>
            <?php endif ?>
            <div class="field"><label for="del-confirm">Écrivez SUPPRIMER pour confirmer</label><input id="del-confirm" name="confirm" type="text" autocomplete="off" autocapitalize="characters"></div>
            <div class="actions"><button class="btn btn-danger" type="submit">Supprimer définitivement mon compte</button></div>
        </form>
    </section>
    <?php endif ?>
</div>
