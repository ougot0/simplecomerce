<?php /** @var array $values @var array $errors @var string $next @var bool $demo */ ?>
<h2>Se connecter</h2>
<p class="muted">Retrouvez votre site et tout ce que vous avez déjà relié.</p>
<form method="post" action="/connexion" class="form panel" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <?php if (!empty($info)): ?>
    <div class="notice notice-ok" role="status">
        <p><?= e($info) ?></p>
        <?php if (!empty($demoLink)): ?><p>Démonstration : aucun e-mail n'est envoyé. <a href="<?= e($demoLink) ?>">Ouvrir le lien de connexion</a></p><?php endif ?>
    </div>
    <?php endif ?>
    <div class="field<?= invalid($errors, 'email') ?>">
        <label for="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($values['email'] ?? '') ?>">
        <?= field_error($errors, 'email') ?>
    </div>
    <div class="field<?= invalid($errors, 'password') ?>">
        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" autocomplete="current-password">
        <?= field_error($errors, 'password') ?>
        <a href="/mot-de-passe-oublie" class="small">Mot de passe oublié ?</a>
    </div>
    <label class="checkbox">
        <input type="checkbox" name="remember" value="1" checked>
        <span>Rester connecté<br><span class="help">Pendant 30 jours. Décochez sur un ordinateur partagé.</span></span>
    </label>
    <div class="actions"><button class="btn btn-primary" type="submit" name="intent" value="password">Se connecter</button></div>
    <div class="divider">ou</div>
    <div>
        <button class="btn" type="submit" name="intent" value="magic">Recevoir un lien de connexion par e-mail</button>
        <p class="help mt-6">Pas besoin de mot de passe : un clic dans l'e-mail suffit.</p>
    </div>
    <p>Pas encore de compte ? <a href="/inscription<?= $next !== '/sites' ? '?next=' . e(rawurlencode($next)) : '' ?>">Créer un compte</a></p>
    <?php if ($demo): ?>
    <div class="notice small">
        <p><strong>Comptes de démonstration</strong> — cliquez pour remplir</p>
        <p class="demo-accounts">
            <button type="button" class="linklike" data-fill="marie@patisserie-lune.fr|tarte-citron-2026">Marie, Pâtisserie Lune</button><br>
            <button type="button" class="linklike" data-fill="paul@atelier-brun.fr|maison-bois-2026">Paul, Atelier Brun</button><br>
            <button type="button" class="linklike" data-fill="alex@simplecommerce.demo|atelier-demo-2026">Alex, administrateur</button>
        </p>
    </div>
    <?php endif ?>
</form>
