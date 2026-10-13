<?php /** @var string $src @var bool $blocked @var string $url @var string $device @var string $base */ ?>
<div class="page-head">
    <div><h1>Aperçu sur téléphone</h1><p class="page-intro">Votre site tel que vos clients le voient sur leur téléphone. Après une modification, rechargez l'aperçu pour la voir.</p></div>
    <nav class="tabs" aria-label="Appareil">
        <a href="<?= e($base) ?>/telephone"<?= $device === 'telephone' ? ' aria-current="page"' : '' ?>>Téléphone</a>
        <a href="<?= e($base) ?>/telephone?appareil=tablette"<?= $device === 'tablette' ? ' aria-current="page"' : '' ?>>Tablette</a>
    </nav>
</div>
<?php if ($blocked): ?>
<div class="notice notice-warn"><p>Votre site ne se laisse pas afficher à l'intérieur d'une autre page (c'est une protection de votre hébergeur). Ouvrez-le directement sur votre téléphone : <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a></p></div>
<?php else: ?>
<div class="device-wrap">
    <div class="device device-<?= e($device) ?>">
        <div class="device-notch" aria-hidden="true"></div>
        <iframe src="<?= e($src) ?>" title="Aperçu de votre site" loading="lazy" referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="device-side">
        <a class="btn" href="<?= e($base) ?>/telephone<?= $device === 'tablette' ? '?appareil=tablette' : '' ?>"><?= icon('undo') ?> Recharger l'aperçu</a>
        <a class="btn btn-quiet" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Ouvrir dans un onglet</a>
        <p class="muted small">Dans l'aperçu, vous pouvez faire défiler et cliquer comme sur un vrai téléphone.</p>
    </div>
</div>
<?php endif ?>
