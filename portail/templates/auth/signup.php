<?php /** @var array $values @var array $errors @var string $next */ ?>
<h2>Créer un compte</h2>
<p class="muted">Gratuit et sans engagement. Ensuite, vous reliez votre site en quelques minutes.</p>
<form method="post" action="/inscription" class="form panel" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <div class="field<?= invalid($errors, 'name') ?>">
        <label for="name">Prénom et nom</label>
        <input id="name" name="name" type="text" autocomplete="name" required value="<?= e($values['name'] ?? '') ?>">
        <span class="help">Affiché dans l'historique des modifications.</span>
        <?= field_error($errors, 'name') ?>
    </div>
    <div class="field<?= invalid($errors, 'email') ?>">
        <label for="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($values['email'] ?? '') ?>">
        <?= field_error($errors, 'email') ?>
    </div>
    <div class="field<?= invalid($errors, 'password') ?>">
        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10">
        <span class="help">10 caractères minimum, avec au moins un chiffre.</span>
        <?= field_error($errors, 'password') ?>
    </div>
    <label class="checkbox"><input type="checkbox" name="remember" value="1" checked><span>Rester connecté</span></label>
    <div class="actions"><button class="btn btn-primary" type="submit">Créer mon compte</button></div>
    <p>Déjà un compte ? <a href="/connexion<?= $next !== '/sites' ? '?next=' . e(rawurlencode($next)) : '' ?>">Se connecter</a></p>
</form>
