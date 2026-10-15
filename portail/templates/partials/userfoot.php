<?php use SimpleCommerce\Services\Access; /** @var array $viewer */ ?>
<div class="sidebar-foot">
    <span><strong><?= e($viewer['effective']['name'] ?: $viewer['effective']['email']) ?></strong></span>
    <a href="/sites?liste=1">Mes sites</a>
    <a href="/compte">Mon compte</a>
    <?php if (\SimpleCommerce\Services\Features::on('aide')): ?><a href="/aide/contact?depuis=<?= e(rawurlencode(\SimpleCommerce\Http\Req::path())) ?>">Besoin d'aide ?</a><?php endif ?>
    <?php if (Access::isAdmin($viewer)): ?><a href="/admin">Administration</a><?php endif ?>
    <form action="/deconnexion" method="post"><?= csrf_field() ?><button type="submit" class="linklike quiet">Se déconnecter</button></form>
    <a href="/sites?liste=1" class="wordmark small"><?= e(\SimpleCommerce\Services\Studio::name()) ?></a>
</div>
