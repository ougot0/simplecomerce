<?php
use SimpleCommerce\Adapters\Catalog;
use SimpleCommerce\Config;
/** @var ?array $def @var array $values @var array $errors @var ?array $report @var bool $isAdmin */ ?>
<div class="page-head">
    <div>
        <div class="crumbs"><a href="/sites?liste=1">Mes sites</a></div>
        <h1>Relier un site</h1>
        <p class="page-intro">Votre site reste où il est, chez votre hébergeur. Simple Commerce vient simplement y écrire vos modifications.</p>
    </div>
</div>
<?php if (!$def): ?>
<div class="two-cols">
    <div>
        <h2>Comment votre site est-il fait ?</h2>
        <p class="muted mt-8">Si vous ne savez pas, demandez à la personne qui a créé votre site : la réponse tient en un mot.</p>
        <?php foreach (Catalog::GROUPS as $g => $label):
            $items = array_filter(Catalog::all(), fn ($c) => $c['group'] === $g);
            if (!$items) continue; ?>
            <div class="choice-group-title"><?= e($label) ?></div>
            <ul class="choice-list">
                <?php foreach ($items as $c): ?>
                <li><a class="choice" href="/sites/nouveau?type=<?= e($c['id']) ?>"><span><span class="choice-title"><?= e($c['label']) ?></span><br><span class="muted small"><?= e($c['description']) ?></span></span><span class="go" aria-hidden="true">→</span></a></li>
                <?php endforeach ?>
            </ul>
        <?php endforeach ?>
    </div>
    <aside class="card">
        <h3>Votre hébergeur n'est pas OVH ?</h3>
        <p class="muted small mt-8">Aucun problème : Simple Commerce fonctionne avec tous les hébergeurs qui proposent un accès FTP ou SFTP (o2switch, Hostinger, IONOS, Infomaniak, LWS, PlanetHoster, serveurs dédiés…), avec Shopify, WordPress, Webflow, et les sites dont le code est sur GitHub, GitLab ou Bitbucket.</p>
        <h3 class="mt-24">Pas encore disponibles</h3>
        <ul class="lines small mt-8">
            <?php foreach (Catalog::NOT_YET as [$n, $why]): ?><li><span><strong><?= e($n) ?></strong><br><span class="muted"><?= e($why) ?></span></span></li><?php endforeach ?>
        </ul>
        <p class="muted small mt-14">Un site fait autrement peut presque toujours être relié avec « Mon site a sa propre base de données », ou en rangeant son contenu dans un fichier (voir l'<a href="/aide#preparer">aide</a>).</p>
    </aside>
</div>
<?php else: ?>
<div class="two-cols">
    <form method="post" action="/sites/nouveau" class="form panel" novalidate data-connect-form>
        <?= csrf_field() ?>
        <input type="hidden" name="connector" value="<?= e($def['id']) ?>">
        <div>
            <a href="/sites/nouveau" class="small faint-link">← Changer de type de site</a>
            <h2 class="mt-6"><?= e($def['label']) ?></h2>
        </div>
        <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif ?>
        <div class="field<?= invalid($errors, 'name') ?>">
            <label for="name">Nom du site</label>
            <input id="name" name="name" type="text" placeholder="Pâtisserie Lune" required value="<?= e($values['name'] ?? '') ?>">
            <span class="help">Le nom de votre commerce, tel que vous voulez le voir ici.</span>
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="field<?= invalid($errors, 'publicUrl') ?>">
            <label for="publicUrl">Adresse de votre site</label>
            <input id="publicUrl" name="publicUrl" type="text" inputmode="url" placeholder="https://www.mon-site.fr" required value="<?= e($values['publicUrl'] ?? ($def['id'] === 'demo' ? '/demo-sites/patisserie-lune' : '')) ?>">
            <?= field_error($errors, 'publicUrl') ?>
        </div>
        <?php $values = $values ?? []; include dirname(__DIR__) . '/partials/connector-fields.php'; ?>
        <div data-report><?php if ($report) { include dirname(__DIR__) . '/partials/report.php'; } ?></div>
        <?php if ($report && !$report['ok'] && $isAdmin): ?>
        <label class="checkbox"><input type="checkbox" name="force" value="1"><span>Relier quand même (le contenu sera configuré plus tard)</span></label>
        <?php endif ?>
        <?php if ($report === null && !empty($values) && array_filter($def['fields'], fn ($f) => !empty($f['secret']))): ?>
        <p class="help">Par sécurité, les mots de passe et jetons ne sont jamais réaffichés : saisissez-les à nouveau.</p>
        <?php endif ?>
        <div class="actions">
            <button class="btn" type="submit" name="intent" value="test" data-test-button><?= icon('link') ?> Tester la connexion</button>
            <button class="btn btn-primary" type="submit" name="intent" value="create">Relier mon site</button>
        </div>
        <p class="help"><?= icon('lock') ?> Vos accès sont chiffrés dès leur enregistrement. Ils ne sont plus jamais affichés, ni à vous ni à personne, et ne quittent jamais le serveur.</p>
    </form>
    <aside class="card">
        <h3>Où trouver ces informations</h3>
        <ol class="steps mt-12"><?php foreach ($def['steps'] as $s): ?><li><?= e($s) ?></li><?php endforeach ?></ol>
        <?php if (in_array($def['id'], ['sftp', 'ftp'], true)): ?>
        <p class="muted small mt-14">Le contenu modifiable doit être rangé dans un fichier (par exemple content.json) que le site lit. Votre créateur de site peut le faire en une heure : voir l'<a href="/aide#preparer">aide</a>.</p>
        <?php endif ?>
    </aside>
</div>
<?php endif;
