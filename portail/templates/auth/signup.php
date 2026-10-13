<?php /** @var array $values @var array $errors @var string $next */ ?>
<h2>Créer un compte</h2>
<p class="muted">Une adresse e-mail et un mot de passe suffisent. Ensuite, vous reliez votre site en quelques minutes.</p>
<?php if (!empty($firstAccount)): ?><div class="notice notice-ok"><p>Ce premier compte sera celui de l'administrateur de Simple Commerce.</p></div><?php endif ?>
<form method="post" action="/inscription" class="form panel" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php if (!empty($parrain)): ?><input type="hidden" name="parrain" value="<?= e($parrain) ?>"><div class="notice notice-ok"><p>Vous avez été invité par un client de Simple Commerce : bienvenue !</p></div><?php endif ?>
    <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
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
    <label class="checkbox"><input type="checkbox" name="remember" value="1" checked><span>Rester connecté<br><span class="help">Vous restez connecté sur cet appareil jusqu'à ce que vous cliquiez sur « Se déconnecter ». Décochez sur un ordinateur partagé.</span></span></label>
    <div class="field<?= invalid($errors, 'terms') ?>">
        <label class="checkbox"><input type="checkbox" name="terms" value="1" required <?= !empty($values['terms']) ? 'checked' : '' ?>><span>J'accepte les <a href="/conditions-generales-utilisation" target="_blank">conditions générales d'utilisation</a> et <a href="/conditions-generales-vente" target="_blank">de vente</a>, et j'ai lu la <a href="/confidentialite" target="_blank">politique de confidentialité</a>.</span></label>
        <?= field_error($errors, 'terms') ?>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Créer mon compte</button></div>
    <p>Déjà un compte ? <a href="/connexion<?= $next !== '/sites' ? '?next=' . e(rawurlencode($next)) : '' ?>">Se connecter</a></p>
</form>
