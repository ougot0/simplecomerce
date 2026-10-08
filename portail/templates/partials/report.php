<?php /** @var array $report */ ?>
<div class="notice <?= $report['ok'] ? 'notice-ok' : 'notice-error' ?>" role="status">
    <p><strong><?= $report['ok'] ? 'La connexion fonctionne.' : 'La connexion ne fonctionne pas encore.' ?></strong></p>
    <ul class="checks">
        <?php foreach ($report['checks'] as $c): ?>
        <li><?= tag($c['ok'] ? 'live' : 'error', $c['ok'] ? 'OK' : 'À revoir') ?><span><?= e($c['label']) ?><?php if (!empty($c['hint'])): ?><span class="help block"><?= e($c['hint']) ?></span><?php endif ?></span></li>
        <?php endforeach ?>
    </ul>
</div>
