<?php
/** En-tête commun des pages légales. @var string $title */
use SimpleCommerce\Controllers\LegalController;
use SimpleCommerce\Services\Legal;
$L = fn (string $k): string => e(Legal::show($k));
$site = e(\SimpleCommerce\Http\Req::origin());
?>
<div class="page-head"><div><h1><?= e($title) ?></h1><p class="page-intro">Dernière mise à jour : <?= e(Legal::UPDATED) ?></p></div></div>
<nav class="tabs legal-tabs" aria-label="Documents légaux">
    <?php foreach (LegalController::PAGES as $slug => [, $label]): ?>
    <a href="/<?= e($slug) ?>"<?= $label === $title ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach ?>
</nav>
