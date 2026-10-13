<?php
use SimpleCommerce\Adapters\Catalog;
/** @var array $sites @var array $users @var array $members @var array $count @var array $stats */ ?>
<div class="page-head">
    <div><h1>Administration</h1><p class="page-intro">Tous les sites et tous les comptes. <a href="/admin/journal">Journal d'activité</a> · <a href="/admin/revenus">Revenus</a> · <a href="/admin/abonnements">Abonnements</a> · <a href="/admin/informations-legales">Informations légales</a> · <a href="/admin/emails">E-mails</a> · <a href="/admin/redaction">Rédaction automatique</a></p></div>
    <a class="btn btn-primary" href="/sites/nouveau"><?= icon('plus') ?> Relier un site</a>
</div>
<div class="notice <?= $signupOpen ? 'notice-ok' : 'notice-warn' ?> access-mode">
    <p><?= icon($signupOpen ? 'users' : 'lock') ?> <strong><?= $signupOpen ? 'Portail ouvert à tous.' : 'Portail privé.' ?></strong>
    <?= $signupOpen ? "N'importe qui peut créer un compte et relier son site." : "Seuls vous et les personnes que vous invitez peuvent entrer. Idéal pour tester avec de vrais sites avant de l'ouvrir." ?></p>
    <form method="post" action="/admin/inscriptions" class="inline"<?= $signupOpen ? '' : ' data-confirm="Ouvrir les inscriptions à tout le monde ?"' ?>><?= csrf_field() ?><input type="hidden" name="open" value="<?= $signupOpen ? '0' : '1' ?>">
        <button class="btn btn-small" type="submit"><?= $signupOpen ? 'Rendre le portail privé' : 'Ouvrir les inscriptions' ?></button></form>
</div>
<div class="panel mb-12">
    <p><strong>Version du portail : <?= e(SC_VERSION) ?></strong></p>
    <p class="small">Où trouver les nouveautés :</p>
    <ul class="small">
        <li><strong>Abonnements</strong> (prix, clé Stripe, code promo) : <a href="/admin/abonnements">Administration → Abonnements</a>.</li>
        <li><strong>Sauvegarde complète du portail</strong> : le bouton juste en dessous.</li>
        <li><strong>Dans chaque site relié</strong> (cliquez sur le nom du site, menu de gauche) : <strong>Boutique</strong> → Avis clients, Statistiques (et Commandes pour un catalogue hébergé) ; <strong>Réglages</strong> → onglets Boutique, Horaires, Fermeture, Sauvegardes.</li>
        <li><strong>Modifier plusieurs produits d'un coup</strong> : dans une liste de produits, les cases à cocher.</li>
    </ul>
    <?php if (!$sites): ?><p class="small muted">Vous n'avez encore relié aucun site : ces menus apparaîtront dès le premier.</p><?php endif ?>
</div>
<p class="mb-12"><a class="btn btn-small" href="/admin/sauvegarde"><?= icon('download') ?> Télécharger une sauvegarde complète du portail</a> <span class="muted small">Comptes, sites reliés et réglages. Gardez-la en lieu sûr : elle contient la clé des accès.</span></p>
<div class="stats">
    <div class="stat"><span class="stat-num"><?= (int) $stats['sites'] ?></span><span class="muted small"><?= $stats['sites'] > 1 ? 'sites reliés' : 'site relié' ?></span></div>
    <div class="stat"><span class="stat-num"><?= (int) $stats['users'] ?></span><span class="muted small">compte<?= $stats['users'] > 1 ? 's' : '' ?></span></div>
    <div class="stat"><span class="stat-num"><?= (int) $stats['week'] ?></span><span class="muted small"><?= $stats['week'] > 1 ? 'modifications publiées' : 'modification publiée' ?> en 7 jours</span></div>
    <div class="stat<?= $stats['failing'] || $stats['failedChanges'] ? ' stat-alert' : '' ?>"><span class="stat-num"><?= (int) $stats['failing'] ?></span><span class="muted small">connexion<?= $stats['failing'] > 1 ? 's' : '' ?> en échec<?= $stats['failedChanges'] ? ' · ' . (int) $stats['failedChanges'] . ($stats['failedChanges'] > 1 ? ' publications échouées' : ' publication échouée') . ' cette semaine' : '' ?></span></div>
</div>

<section class="section-block">
    <h2 class="mb-14">Sites</h2>
    <?php if (count($sites) > 6): ?><div class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher un site…" data-filter aria-label="Rechercher un site"></div><?php endif ?>
    <div class="ledger-wrap"><table class="ledger" data-filter-list>
        <thead><tr><th>Site</th><th class="hide-small">Type</th><th class="hide-small">Clients</th><th class="hide-small">Connexion</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php if (!$sites): ?>
        <tr><td colspan="5" class="muted">Aucun site pour l'instant. Cliquez sur « Relier un site » pour commencer.</td></tr>
        <?php endif ?>
        <?php foreach ($sites as $s): ?>
        <tr data-filter-item data-text="<?= e(mb_strtolower($s['name'] . ' ' . implode(' ', $members[$s['id']] ?? []))) ?>">
            <td class="title-cell"><a href="/s/<?= e($s['slug']) ?>"><?= e($s['name']) ?></a><?= $s['status'] === 'suspended' ? ' ' . tag('off', 'Suspendu') : '' ?><div class="muted small"><?= e($s['public_url']) ?></div></td>
            <td class="hide-small small"><?= e(Catalog::label($s['connector'])) ?></td>
            <td class="hide-small small"><?= $members[$s['id']] ? e(implode(', ', $members[$s['id']])) : '<span class="faint">aucun</span>' ?></td>
            <td class="hide-small small"><?= $s['last_check_at'] ? tag($s['last_check_ok'] ? 'live' : 'error', $s['last_check_ok'] ? 'OK' : 'En échec') . ' <span class="muted">' . e(when($s['last_check_at'])) . '</span>' : '<span class="faint">jamais testée</span>' ?></td>
            <td class="shrink"><div class="actions nowrap">
                <a class="btn btn-small" href="/s/<?= e($s['slug']) ?>/reglages">Réglages</a>
                <form method="post" action="/admin/suspendre" class="inline"<?= $s['status'] === 'active' ? ' data-confirm="Suspendre « ' . e($s['name']) . ' » ? Ses clients n\'y auront plus accès."' : '' ?>><?= csrf_field() ?><input type="hidden" name="siteId" value="<?= e($s['id']) ?>"><input type="hidden" name="status" value="<?= $s['status'] === 'active' ? 'suspended' : 'active' ?>"><button class="btn btn-small btn-quiet" type="submit"><?= $s['status'] === 'active' ? 'Suspendre' : 'Réactiver' ?></button></form>
            </div></td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>

<section class="section-block">
    <h2 class="mb-14">Comptes</h2>
    <div class="ledger-wrap"><table class="ledger">
        <thead><tr><th>Personne</th><th class="hide-small num">Sites</th><th class="hide-small">Inscrit</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td class="title-cell"><strong><?= e($u['name'] ?: '(sans nom)') ?></strong><?= $u['is_admin'] ? '<span class="muted"> · administrateur</span>' : '' ?><?= !$u['is_admin'] && !\SimpleCommerce\Services\Auth::isVerified($u) ? ' ' . tag('draft', 'E-mail non confirmé') : '' ?><div class="muted small"><?= e($u['email']) ?></div></td>
            <td class="hide-small num"><?= (int) ($count[$u['id']] ?? 0) ?></td>
            <td class="hide-small small muted"><?= e(when($u['created_at'])) ?></td>
            <td class="shrink"><?php if (!$u['is_admin'] && !\SimpleCommerce\Services\Auth::isVerified($u)): ?><form method="post" action="/admin/confirmer-email" class="inline"><?= csrf_field() ?><input type="hidden" name="userId" value="<?= e($u['id']) ?>"><button class="btn btn-small btn-quiet" type="submit">Confirmer l'e-mail</button></form><?php endif ?><?php if (!$u['is_admin']): ?><form method="post" action="/admin/assister" class="inline"><?= csrf_field() ?><input type="hidden" name="userId" value="<?= e($u['id']) ?>"><button class="btn btn-small" type="submit">Agir pour ce client</button></form><?php endif ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <p class="muted small mt-12">« Agir pour ce client » ouvre son espace tel qu'il le voit, pendant une heure au plus. Tout ce que vous y faites est enregistré à votre nom.</p>
</section>
