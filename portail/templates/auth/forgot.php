<?php /** @var array $errors */ ?>
<h2>Mot de passe oublié</h2>
<p class="muted">Indiquez votre adresse : vous recevrez un lien pour vous connecter, puis vous choisirez un nouveau mot de passe.</p>
<?php if (!empty($info)): ?>
<div class="notice notice-ok" role="status">
    <p><?= e($info) ?></p>
    <?php if (!empty($demoLink)): ?><p>Démonstration : <a href="<?= e($demoLink) ?>">ouvrir le lien</a></p><?php endif ?>
</div>
<?php else: ?>
<form method="post" action="/mot-de-passe-oublie" class="form panel" novalidate>
    <?= csrf_field() ?>
    <div class="field<?= invalid($errors, 'email') ?>">
        <label for="email">Adresse e-mail</label>
        <input id="email" name="email" type="email" autocomplete="email" required>
        <?= field_error($errors, 'email') ?>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Envoyer le lien</button></div>
</form>
<?php endif ?>
<p class="mt-24"><a href="/connexion">Retour à la connexion</a></p>
