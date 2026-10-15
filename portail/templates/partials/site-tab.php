<?php
use SimpleCommerce\Services\SiteTabs;
/** Bouton « Ajouter l'onglet … à mon site », avec la ligne à coller en solution de secours. @var array $site @var string $base @var string $kind @var string $line */
$k = SiteTabs::KINDS[$kind];
$tab = SiteTabs::current($site, $kind);
?>
<?php if (SiteTabs::supported($site)): ?>
    <?php if ($tab): ?>
    <p><strong>L'onglet « <?= e($k['label']) ?> » est sur votre site</strong>, dans le menu de toutes les pages<?php if ($site['public_url']): ?> : <a href="<?= e(rtrim($site['public_url'], '/') . '/' . basename($tab)) ?>" target="_blank" rel="noopener">voir la page</a><?php endif ?>.</p>
    <div class="actions mt-8">
    <form method="post" action="<?= e($base) ?>/onglet" class="inline"><?= csrf_field() ?><input type="hidden" name="quoi" value="<?= e($kind) ?>"><input type="hidden" name="do" value="ajouter">
        <button class="btn btn-small" type="submit" data-busy="Mise à jour…">Refaire la page <?= e($k['label']) ?></button></form>
    <form method="post" action="<?= e($base) ?>/onglet" class="inline" data-confirm="Retirer l'onglet « <?= e($k['label']) ?> » et sa page de votre site ?"><?= csrf_field() ?><input type="hidden" name="quoi" value="<?= e($kind) ?>"><input type="hidden" name="do" value="retirer">
        <button class="btn btn-small btn-quiet" type="submit">Retirer l'onglet de mon site</button></form>
    </div>
    <?php else: ?>
    <p><strong>Mettez-les sur votre site en un clic.</strong> Un onglet « <?= e($k['label']) ?> » est ajouté au menu de toutes vos pages, avec sa page, dans le style de votre site. Rien à copier.</p>
    <form method="post" action="<?= e($base) ?>/onglet" class="mt-8"><?= csrf_field() ?><input type="hidden" name="quoi" value="<?= e($kind) ?>"><input type="hidden" name="do" value="ajouter">
        <button class="btn btn-primary" type="submit" data-busy="Ajout sur votre site…"><?= icon('plus') ?> Ajouter l'onglet « <?= e($k['label']) ?> » à mon site</button></form>
    <?php endif ?>
    <details class="mt-12"><summary class="muted small">Autre possibilité : les mettre ailleurs dans une page</summary>
        <p class="small mt-8">Collez cette ligne à l'endroit voulu de votre site.</p>
        <div class="copy-row"><input type="text" readonly value="<?= e($line) ?>" class="code" aria-label="Ligne à coller dans votre site" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div>
    </details>
<?php else: ?>
    <p><strong>Pour les afficher sur votre site</strong>, collez cette ligne une seule fois à l'endroit voulu (ou demandez-le à la personne qui s'occupe de votre site).</p>
    <div class="copy-row"><input type="text" readonly value="<?= e($line) ?>" class="code" aria-label="Ligne à coller dans votre site" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div>
<?php endif ?>
