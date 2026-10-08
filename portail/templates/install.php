<?php /** @var array $checks @var array $values @var array $errors */ ?>
<h2>Installer Simple Commerce</h2>
<p class="muted">Une seule fois, juste après avoir envoyé les fichiers. Comptez cinq minutes.</p>
<ul class="checks install-checks">
    <?php foreach ($checks as [$label, $ok, $hint]): ?>
    <li><?= tag($ok ? 'live' : 'error', $ok ? 'OK' : 'À régler') ?><span><?= e($label) ?><?php if (!$ok): ?><span class="help block"><?= e($hint) ?></span><?php endif ?></span></li>
    <?php endforeach ?>
</ul>
<form method="post" action="/installation" class="form panel mt-24" novalidate>
    <?= csrf_field() ?>
    <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <h3>Adresse du portail</h3>
    <div class="field<?= invalid($errors, 'url') ?>"><label for="url">Adresse</label><input id="url" name="url" type="text" value="<?= e($values['url']) ?>"><span class="help">Celle que vous tapez pour venir ici, sans « / » à la fin.</span><?= field_error($errors, 'url') ?></div>
    <h3>Base de données MySQL</h3>
    <p class="help">Chez OVH : espace client → Web Cloud → Hébergements → votre offre → onglet « Bases de données ». Créez une base si besoin ; le serveur ressemble à <code>xxxxx.mysql.db</code>.</p>
    <div class="field<?= invalid($errors, 'db_host') ?>"><label for="db_host">Serveur</label><input id="db_host" name="db_host" type="text" value="<?= e($values['db_host']) ?>" placeholder="monsite.mysql.db"><?= field_error($errors, 'db_host') ?></div>
    <div class="field<?= invalid($errors, 'db_name') ?>"><label for="db_name">Nom de la base</label><input id="db_name" name="db_name" type="text" value="<?= e($values['db_name']) ?>"><?= field_error($errors, 'db_name') ?></div>
    <div class="field<?= invalid($errors, 'db_user') ?>"><label for="db_user">Utilisateur</label><input id="db_user" name="db_user" type="text" value="<?= e($values['db_user']) ?>" autocomplete="off"><?= field_error($errors, 'db_user') ?></div>
    <div class="field"><label for="db_password">Mot de passe de la base</label><input id="db_password" name="db_password" type="password" autocomplete="new-password"></div>
    <h3>Votre compte administrateur</h3>
    <div class="field<?= invalid($errors, 'name') ?>"><label for="name">Prénom et nom</label><input id="name" name="name" type="text" value="<?= e($values['name']) ?>"><?= field_error($errors, 'name') ?></div>
    <div class="field<?= invalid($errors, 'email') ?>"><label for="email">Adresse e-mail</label><input id="email" name="email" type="email" value="<?= e($values['email']) ?>"><?= field_error($errors, 'email') ?></div>
    <div class="field<?= invalid($errors, 'password') ?>"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password"><span class="help">10 caractères minimum, avec au moins un chiffre.</span><?= field_error($errors, 'password') ?></div>
    <div class="field<?= invalid($errors, 'mail_from') ?>"><label for="mail_from">Adresse d'envoi des e-mails <span class="optional">(facultatif)</span></label><input id="mail_from" name="mail_from" type="email" value="<?= e($values['mail_from']) ?>" placeholder="ne-pas-repondre@mon-domaine.fr"><span class="help">Une adresse de votre nom de domaine, pour que les e-mails n'arrivent pas en indésirables.</span><?= field_error($errors, 'mail_from') ?></div>
    <div class="actions"><button class="btn btn-primary" type="submit">Installer</button></div>
    <p class="help">Les clés de chiffrement sont créées automatiquement et enregistrées dans config.php, hors du dossier public. Gardez-en une copie.</p>
</form>
