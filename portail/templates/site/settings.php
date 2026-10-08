<?php
use SimpleCommerce\Adapters\Catalog;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Text;
/** @var array $ctx @var array $site @var array $members @var array $invitations @var ?array $creds @var ?array $def @var mixed $closure @var array $config @var array $errors @var ?array $report @var string $base @var string $role */
$viewer = $ctx['viewer'];
?>
<div class="page-head">
    <div>
        <h1>Réglages</h1>
        <p class="page-intro"><?= e(Catalog::label($site['connector'])) ?><?= $site['last_check_at'] ? ' · dernière vérification ' . e(when($site['last_check_at'])) . ' : ' . ($site['last_check_ok'] ? 'connexion correcte' : 'problème de connexion') : '' ?></p>
    </div>
</div>
<nav class="tabs" aria-label="Sections des réglages">
    <a href="#equipe">Équipe</a><a href="#fermeture">Fermeture</a><a href="#rubriques">Rubriques</a><a href="#connexion">Connexion</a><?= $role === 'admin' ? '<a href="#schema">Schéma</a>' : '' ?><a href="#retirer">Retirer</a>
</nav>

<section id="equipe" class="section-block first">
    <h2>Équipe</h2>
    <p class="muted">Chaque personne a son propre compte. Les modifications sont signées de son nom dans l'historique.</p>
    <ul class="lines narrow-block">
        <?php foreach ($members as $m): $u = $m['user']; if (!$u) continue; ?>
        <li>
            <div class="line-main">
                <span class="line-title"><?= e($u['name'] ?: $u['email']) ?></span><?= $u['is_admin'] ? ' <span class="muted">(administrateur)</span>' : '' ?>
                <div class="muted small"><?= e($u['email']) ?> · <?= $m['role'] === 'owner' ? 'tout gérer' : 'modifier le contenu' ?></div>
            </div>
            <?php if ($m['user_id'] !== $viewer['effective']['id']): ?>
            <form method="post" action="<?= e($base) ?>/reglages/retirer-membre" class="inline" data-confirm="Retirer l'accès de <?= e($u['name'] ?: $u['email']) ?> ?"><?= csrf_field() ?><input type="hidden" name="userId" value="<?= e($m['user_id']) ?>"><button class="btn btn-small btn-quiet" type="submit">Retirer l'accès</button></form>
            <?php endif ?>
        </li>
        <?php endforeach ?>
        <?php foreach ($invitations as $i): ?>
        <li>
            <div class="line-main"><span class="line-title"><?= e($i['email']) ?></span> <?= tag('draft', 'Invitation en attente') ?><div class="muted small">expire le <?= e(Text::date($i['expires_at'], false)) ?></div></div>
            <form method="post" action="<?= e($base) ?>/reglages/annuler-invitation" class="inline"><?= csrf_field() ?><input type="hidden" name="invitationId" value="<?= e($i['id']) ?>"><button class="btn btn-small btn-quiet" type="submit">Annuler l'invitation</button></form>
        </li>
        <?php endforeach ?>
    </ul>
    <h3 class="mt-28 mb-12">Inviter un collègue</h3>
    <form method="post" action="<?= e($base) ?>/reglages/inviter" class="form panel" novalidate>
        <?= csrf_field() ?>
        <?php if (!empty($inviteError)): ?><div class="notice notice-error" role="alert"><?= e($inviteError) ?></div><?php endif ?>
        <?php if (!empty($inviteInfo)): ?>
        <div class="notice notice-ok" role="status"><p><?= e($inviteInfo) ?></p>
            <?php if (!empty($inviteLink)): ?><div class="copy-row"><input type="text" readonly value="<?= e($inviteLink) ?>" class="code" aria-label="Lien d'invitation" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div><?php endif ?>
        </div>
        <?php endif ?>
        <div class="field"><label for="invite-email">Adresse e-mail de la personne</label><input id="invite-email" name="email" type="email" autocomplete="off"></div>
        <fieldset class="field">
            <legend>Ce qu'elle pourra faire</legend>
            <label class="checkbox"><input type="radio" name="role" value="editor" checked><span>Modifier le contenu<br><span class="help">Produits, textes, photos, brouillons, historique.</span></span></label>
            <label class="checkbox"><input type="radio" name="role" value="owner"><span>Tout gérer<br><span class="help">En plus : les accès au site, l'équipe et la fermeture.</span></span></label>
        </fieldset>
        <div class="actions"><button class="btn btn-primary" type="submit"><?= icon('users') ?> Inviter</button></div>
    </form>
</section>

<section id="fermeture" class="section-block">
    <h2>Fermer le site temporairement</h2>
    <p class="muted">Pour des congés ou des travaux : votre site affiche un message à la place de son contenu, puis vous le rouvrez en un clic.</p>
    <?php if (is_array($closure)): ?>
    <form method="post" action="<?= e($base) ?>/reglages/fermeture" class="form panel">
        <?= csrf_field() ?>
        <?php if (!empty($closureError)): ?><div class="notice notice-error" role="alert"><?= e($closureError) ?></div><?php endif ?>
        <?php if ($closure['closed']): ?>
        <div class="notice notice-warn"><p><strong>Votre site est fermé.</strong> Vos visiteurs voient : « <?= e($closure['message']) ?> »<?= $closure['reopenOn'] ? ' — réouverture le ' . e(Text::date($closure['reopenOn'], false, true)) : '' ?>.</p></div>
        <div class="actions"><button class="btn btn-primary" type="submit" name="intent" value="open">Rouvrir le site</button></div>
        <?php else: ?>
        <div class="field"><label for="closure-message">Message pour vos visiteurs</label>
            <textarea id="closure-message" name="message" maxlength="300" rows="3"><?= e($closure['message'] ?: 'Nous sommes en congés. Merci de votre patience, à très bientôt !') ?></textarea></div>
        <div class="field"><label for="closure-date">Date de réouverture <span class="optional">(facultatif)</span></label>
            <input id="closure-date" name="reopenOn" type="date" class="medium" min="<?= e(date('Y-m-d')) ?>" value="<?= e($closure['reopenOn'] ?? '') ?>"></div>
        <div class="actions"><button class="btn btn-danger" type="submit" name="intent" value="close"><?= icon('lock') ?> Fermer le site temporairement</button></div>
        <p class="help">Vos produits et vos textes ne sont pas effacés. <?= e(Sites::delayText($site)) ?></p>
        <?php endif ?>
    </form>
    <?php elseif ($closure === false): ?>
    <div class="notice notice-error">Impossible de lire l'état du site pour le moment. Vérifiez la connexion ci-dessous.</div>
    <?php else: ?>
    <div class="notice"><p><?= e(Catalog::CLOSE_ELSEWHERE[$site['connector']] ?? 'Ce type de site se ferme depuis sa propre administration.') ?></p></div>
    <?php endif ?>
</section>

<section id="rubriques" class="section-block">
    <h2>Rubriques modifiables</h2>
    <p class="muted">Renommez les rubriques avec vos mots, ou masquez celles que vous ne modifiez jamais.</p>
    <form method="post" action="<?= e($base) ?>/reglages/rubriques" class="form wide-form">
        <?= csrf_field() ?>
        <?php if (!$ctx['schema']['sections']): ?><p class="muted">Aucune rubrique.</p><?php else: ?>
        <div class="ledger-wrap"><table class="ledger">
            <thead><tr><th>Nom affiché</th><th class="shrink">Visible</th><th class="shrink hide-small">Type</th></tr></thead>
            <tbody>
            <?php foreach ($ctx['schema']['sections'] as $s): ?>
            <tr>
                <td><input type="text" name="label[<?= e($s['key']) ?>]" value="<?= e($s['label']) ?>" aria-label="Nom de la rubrique <?= e($s['label']) ?>"></td>
                <td class="shrink"><label class="switch"><input type="checkbox" name="visible[<?= e($s['key']) ?>]" value="1"<?= empty($s['hidden']) ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span class="visually-hidden">Visible</span></label></td>
                <td class="shrink hide-small muted small"><?= ($s['kind'] ?? '') === 'collection' ? 'Liste' : 'Bloc' ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer les rubriques</button></div>
    </form>
    <form method="post" action="<?= e($base) ?>/reglages/relire" class="mt-16">
        <?= csrf_field() ?>
        <button class="btn" type="submit" data-busy="Lecture du site…">Relire le contenu du site</button>
        <p class="help mt-6">À faire si votre créateur de site a ajouté de nouvelles rubriques. Vos noms et choix de visibilité sont conservés.</p>
    </form>
</section>

<section id="connexion" class="section-block">
    <h2>Connexion au site</h2>
    <p class="muted">Les accès enregistrés ne sont jamais réaffichés. Laissez un champ secret vide pour garder la valeur actuelle.</p>
    <form method="post" action="<?= e($base) ?>/reglages/connexion" class="form panel" novalidate data-connect-form>
        <?= csrf_field() ?>
        <?php if (!empty($connError)): ?><div class="notice notice-error"><?= e($connError) ?></div><?php endif ?>
        <div class="field<?= invalid($errors, 'name') ?>"><label for="name">Nom du site</label><input id="name" name="name" type="text" value="<?= e($_POST['name'] ?? $site['name']) ?>"><?= field_error($errors, 'name') ?></div>
        <div class="field<?= invalid($errors, 'publicUrl') ?>"><label for="publicUrl">Adresse du site</label><input id="publicUrl" name="publicUrl" type="text" value="<?= e($_POST['publicUrl'] ?? $site['public_url']) ?>"><?= field_error($errors, 'publicUrl') ?></div>
        <?php if ($def):
            $values = $config;
            $fingerprints = $creds['fingerprints'] ?? [];
            include dirname(__DIR__) . '/partials/connector-fields.php';
        endif ?>
        <div data-report><?php if ($report) { include dirname(__DIR__) . '/partials/report.php'; } ?></div>
        <div class="actions">
            <button class="btn" type="submit" name="intent" value="test" data-test-button><?= icon('link') ?> Tester la connexion</button>
            <button class="btn btn-primary" type="submit" name="intent" value="save">Enregistrer</button>
        </div>
        <?php if ($creds): ?><p class="help"><?= icon('lock') ?> Accès chiffrés, modifiés <?= e(when($creds['updated_at'])) ?>.</p><?php endif ?>
    </form>
</section>

<?php if ($role === 'admin'): ?>
<section id="schema" class="section-block">
    <h2>Schéma de contenu (administrateur)</h2>
    <p class="muted">Champs modifiables, formats de photo, limites de longueur. Chaque enregistrement crée une nouvelle version.</p>
    <form method="post" action="<?= e($base) ?>/reglages/schema" class="form wide-form">
        <?= csrf_field() ?>
        <?php if (!empty($schemaError)): ?><div class="notice notice-error" role="alert"><?= e($schemaError) ?></div><?php endif ?>
        <textarea class="code" name="schema" spellcheck="false" aria-label="Schéma de contenu"><?= e($schemaText ?? json_encode($ctx['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer le schéma</button></div>
    </form>
</section>
<?php endif ?>

<section id="retirer" class="section-block">
    <h2>Retirer le site</h2>
    <form method="post" action="<?= e($base) ?>/reglages/retirer-site" class="form panel">
        <?= csrf_field() ?>
        <?php if (!empty($deleteError)): ?><div class="notice notice-error" role="alert"><?= e($deleteError) ?></div><?php endif ?>
        <p>Retire ce site de Simple Commerce et efface ses accès enregistrés. <strong>Votre site lui-même n'est pas touché</strong> : il reste en ligne, tel quel.</p>
        <div class="field"><label for="confirm-name">Pour confirmer, recopiez le nom du site : <?= e($site['name']) ?></label><input id="confirm-name" name="confirm" type="text" autocomplete="off"></div>
        <div class="actions"><button class="btn btn-danger" type="submit">Retirer le site</button></div>
    </form>
</section>
