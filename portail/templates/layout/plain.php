<?php
use SimpleCommerce\Services\Access;
/** @var string $content @var ?array $viewer */
$viewer ??= Access::viewer();
include __DIR__ . '/head.php';
include dirname(__DIR__) . '/partials/assist.php';
?>
<header class="topbar">
    <a href="/sites?liste=1" class="wordmark">Simple<span> </span>Commerce</a>
    <?php if ($viewer): ?>
    <nav class="topbar-nav" aria-label="Compte">
        <a href="/sites?liste=1">Mes sites</a>
        <?php if (Access::isAdmin($viewer)): ?><a href="/admin">Administration</a><?php endif ?>
        <a href="/aide">Aide</a>
        <a href="/compte"><?= e($viewer['effective']['name'] ?: 'Mon compte') ?></a>
        <form action="/deconnexion" method="post"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Se déconnecter</button></form>
    </nav>
    <?php else: ?>
    <nav class="topbar-nav"><a href="/connexion">Se connecter</a></nav>
    <?php endif ?>
</header>
<main class="plain-main<?= !empty($wide) ? ' plain-wide' : '' ?>" id="contenu">
    <?php include dirname(__DIR__) . '/partials/flash.php'; ?>
    <?= $content ?>
</main>
</body>
</html>
