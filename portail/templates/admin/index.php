<?php
use SimpleCommerce\Adapters\Catalog;
/** @var array $sites @var array $users @var array $members @var array $count @var array $stats */ ?>
<div class="page-head">
    <div><h1>Administration</h1><p class="page-intro">Tous les sites et tous les comptes. <a href="/admin/journal">Journal d'activité</a></p></div>
    <a class="btn btn-primary" href="/sites/nouveau"><?= icon('plus') ?> Relier un site</a>
</div>
<div class="stats">
    <div class="stat"><span class="stat-num"><?= (int) $stats['sites'] ?></span><span class="muted small">site<?= $stats['sites'] > 1 ? 's' : '' ?> reliés</span></div>
    <div class="stat"><span class="stat-num"><?= (int) $stats['users'] ?></span><span class="muted small">compte<?= $stats['users'] > 1 ? 's' : '' ?></span></div>
    <div class="stat"><span class="stat-num"><?= (int) $stats['week'] ?></span><span class="muted small">modification<?= $stats['week'] > 1 ? 's' : '' ?> publiées en 7 jours</span></div>
    <div class="stat<?= $stats['failing'] || $stats['failedChanges'] ? ' stat-alert' : '' ?>"><span class="stat-num"><?= (int) $stats['failing'] ?></span><span class="muted small">connexion<?= $stats['failing'] > 1 ? 's' : '' ?> en échec<?= $stats['failedChanges'] ? ' · ' . (int) $stats['failedChanges'] . ' publication(s) échouée(s) cette semaine' : '' ?></span></div>
</div>

<section class="section-block">
    <h2 class="mb-14">Sites</h2>
    <?php if (count($sites) > 6): ?><div class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher un site…" data-filter aria-label="Rechercher un site"></div><?php endif ?>
    <div class="ledger-wrap"><table class="ledger" data-filter-list>
        <thead><tr><th>Site</th><th class="hide-small">Type</th><th class="hide-small">Clients</th><th class="hide-small">Connexion</th><th class="shrink"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
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
            <td class="title-cell"><strong><?= e($u['name'] ?: '(sans nom)') ?></strong><?= $u['is_admin'] ? '<span class="muted"> · administrateur</span>' : '' ?><div class="muted small"><?= e($u['email']) ?></div></td>
            <td class="hide-small num"><?= (int) ($count[$u['id']] ?? 0) ?></td>
            <td class="hide-small small muted"><?= e(when($u['created_at'])) ?></td>
            <td class="shrink"><?php if (!$u['is_admin']): ?><form method="post" action="/admin/assister" class="inline"><?= csrf_field() ?><input type="hidden" name="userId" value="<?= e($u['id']) ?>"><button class="btn btn-small" type="submit">Agir pour ce client</button></form><?php endif ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <p class="muted small mt-12">« Agir pour ce client » ouvre son espace tel qu'il le voit, pendant une heure au plus. Tout ce que vous y faites est enregistré à votre nom.</p>
</section>
