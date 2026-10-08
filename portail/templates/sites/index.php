<?php
use SimpleCommerce\Adapters\Catalog;
use SimpleCommerce\Services\Access;
/** @var array $sites @var array $viewer */ ?>
<div class="page-head">
    <div>
        <h1><?= $sites ? 'Vos sites' : 'Bienvenue' ?></h1>
        <?php if (!$sites): ?><p class="page-intro">Pour commencer, reliez votre site à Simple Commerce. Cela prend quelques minutes, une seule fois. Ensuite, il sera là à chaque connexion.</p><?php endif ?>
    </div>
    <a class="btn btn-primary" href="/sites/nouveau"><?= icon('plus') ?> Relier un site</a>
</div>
<?php if ($sites): ?>
<ul class="lines site-rows">
    <?php foreach ($sites as $s): ?>
    <li>
        <span class="site-mark"><?= icon('store') ?></span>
        <div class="line-main">
            <a class="line-title" href="/s/<?= e($s['slug']) ?>"><?= e($s['name']) ?></a>
            <div class="muted small"><?= e(Catalog::label($s['connector'])) ?> · <?= $s['role'] === 'owner' ? 'propriétaire' : 'collaborateur' ?><?= $s['status'] === 'suspended' ? ' · suspendu' : '' ?></div>
        </div>
        <a class="btn btn-small" href="/s/<?= e($s['slug']) ?>">Ouvrir</a>
    </li>
    <?php endforeach ?>
</ul>
<?php else: ?>
<div class="welcome-steps">
    <div><span class="step-num">1</span><strong>Dites comment votre site est fait</strong><span class="muted">Shopify, WordPress, un hébergeur (OVHcloud, o2switch, IONOS…), GitHub…</span></div>
    <div><span class="step-num">2</span><strong>Entrez vos accès une seule fois</strong><span class="muted">Ils sont chiffrés et ne sont plus jamais affichés.</span></div>
    <div><span class="step-num">3</span><strong>Modifiez, publiez</strong><span class="muted">Produits, prix, photos, horaires : votre site suit.</span></div>
</div>
<?php endif ?>
<?php if (Access::isAdmin($viewer)): ?>
<p class="muted mt-28">Les sites de tous vos clients sont dans l'<a href="/admin">administration</a>.</p>
<?php endif ?>
