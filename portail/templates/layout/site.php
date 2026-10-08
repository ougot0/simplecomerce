<?php
use SimpleCommerce\Http\Req;
use SimpleCommerce\Repo;
/** @var array $ctx @var string $content @var string $base */
$viewer = $ctx['viewer'];
$site = $ctx['site'];
$path = Req::path();
$drafts = count(Repo::drafts($site['id']));
$navItem = function (string $href, string $label, string $icon, bool $exact = false, ?int $count = null) use ($path) {
    $current = $exact ? $path === $href : ($path === $href || str_starts_with($path, $href . '/'));
    return '<li><a href="' . e($href) . '"' . ($current ? ' aria-current="page"' : '') . '>' . icon($icon) . '<span>' . e($label) . '</span>'
        . ($count ? '<span class="count">' . $count . '</span>' : '') . '</a></li>';
};
include __DIR__ . '/head.php';
include dirname(__DIR__) . '/partials/assist.php';
?>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar-top">
            <a href="<?= e($base) ?>" class="site-name"><?= e($site['name']) ?></a>
            <?php if ($site['public_url']): ?>
            <a class="site-link" href="<?= e($site['public_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('eye') ?> Voir mon site</a>
            <?php endif ?>
        </div>
        <button type="button" class="btn btn-small menu-toggle" data-menu-toggle aria-expanded="false"><?= icon('menu') ?> Menu</button>
        <div class="sidebar-body" data-menu>
            <nav aria-label="Contenu du site">
                <div class="nav-title">Mon contenu</div>
                <ul class="nav">
                    <?= $navItem($base, 'Vue d\'ensemble', 'home', true) ?>
                    <?php foreach ($ctx['sections'] as $s): ?>
                        <?= $navItem("$base/r/{$s['key']}", $s['label'], ($s['kind'] ?? '') === 'collection' ? 'list' : 'text') ?>
                    <?php endforeach ?>
                </ul>
            </nav>
            <nav aria-label="Suivi">
                <div class="nav-title">Suivi</div>
                <ul class="nav">
                    <?= $navItem("$base/brouillons", 'Brouillons', 'draft', false, $drafts) ?>
                    <?= $navItem("$base/historique", 'Historique', 'clock') ?>
                    <?php if ($ctx['role'] !== 'editor'): ?>
                        <?= $navItem("$base/reglages", 'Réglages', 'settings') ?>
                    <?php endif ?>
                    <?= $navItem('/aide', 'Aide', 'help') ?>
                </ul>
            </nav>
            <?php include dirname(__DIR__) . '/partials/userfoot.php'; ?>
        </div>
    </aside>
    <main class="main" id="contenu">
        <?php include dirname(__DIR__) . '/partials/flash.php'; ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
