<?php use SimpleCommerce\Services\Bookings; /** @var array $b @var array $site @var string $token @var ?string $done */ ?>
<h2>Votre rendez-vous</h2>
<div class="panel">
    <p><strong><?= e($site['name']) ?></strong><br><?= e($b['service']) ?><br><?= e(Bookings::when($b['starts_at'])) ?></p>
    <?php if ($done): ?>
    <div class="notice notice-ok"><p><?= e($done) ?></p></div>
    <?php elseif ($b['status'] !== 'confirme'): ?>
    <p class="muted">Ce rendez-vous est déjà annulé.</p>
    <?php elseif ($b['starts_at'] <= \SimpleCommerce\Db::now()): ?>
    <p class="muted">Ce rendez-vous est passé.</p>
    <?php else: ?>
    <form method="post" action="/rdv/annuler/<?= e($token) ?>" data-confirm="Annuler ce rendez-vous ?"><?= csrf_field() ?>
        <button class="btn btn-danger" type="submit">Annuler mon rendez-vous</button></form>
    <?php endif ?>
</div>
