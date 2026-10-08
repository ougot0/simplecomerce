<?php /** @var array $viewer */ $u = $viewer['user']; ?>
<div class="page-head"><div><h1>Mon compte</h1><p class="page-intro"><?= e($u['email']) ?></p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if (!empty($resetHint)): ?><div class="notice notice-warn"><p>Vous êtes connecté. Choisissez maintenant un nouveau mot de passe ci-dessous.</p></div><?php endif ?>
<div class="stack-40">
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
            <div class="field"><label for="password">Nouveau mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password"><span class="help">10 caractères minimum, avec au moins un chiffre.</span></div>
            <div class="field"><label for="confirm">Encore une fois</label><input id="confirm" name="confirm" type="password" autocomplete="new-password"></div>
            <div class="actions"><button class="btn" type="submit">Changer le mot de passe</button></div>
        </form>
    </section>
</div>
