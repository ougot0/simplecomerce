<?php
use SimpleCommerce\Support\Text;
/** @var array $stats @var int $days @var bool $enabled @var bool $inserted @var bool $canInsert @var string $line @var string $base @var string $role */
$max = max(1, ...array_map(fn ($d) => $d['visits'], array_values($stats['perDay'])));
$trend = $stats['previousVisits'] > 0 ? (int) round(($stats['visits'] - $stats['previousVisits']) * 100 / $stats['previousVisits']) : null;
?>
<div class="page-head">
    <div><h1>Statistiques</h1><p class="page-intro">Combien de personnes visitent votre site, quelles pages elles regardent et d'où elles viennent. Sans Google, sans cookie : rien ne permet de reconnaître un visiteur.</p></div>
    <?php if ($enabled): ?>
    <nav class="tabs" aria-label="Période">
        <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '3 mois'] as $d => $l): ?><a href="<?= e($base) ?>/statistiques?jours=<?= $d ?>"<?= $days === $d ? ' aria-current="page"' : '' ?>><?= $l ?></a><?php endforeach ?>
    </nav>
    <?php endif ?>
</div>

<?php if ($enabled && !$stats['any']): ?>
<div class="notice"><p><strong>Le compteur est en place.</strong> Les chiffres apparaîtront ici dès les premières visites de votre site.</p>
    <?php if (!$inserted && $role !== 'editor'): ?><p class="small">Ligne à coller dans votre site si ce n'est pas encore fait :</p>
    <div class="copy-row"><input type="text" readonly value="<?= e($line) ?>" class="code" aria-label="Ligne à coller dans votre site" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div><?php endif ?>
</div>
<?php elseif (!$enabled): ?>
<div class="panel">
    <?php if ($role === 'editor'): ?>
    <p>Les statistiques ne sont pas encore en place. Demandez au propriétaire du site de les activer.</p>
    <?php elseif ($canInsert): ?>
    <p><strong>Un clic suffit</strong> : Simple Commerce ajoute un petit compteur invisible à toutes les pages de votre site.</p>
    <form method="post" action="<?= e($base) ?>/statistiques/action" class="mb-12"><?= csrf_field() ?><input type="hidden" name="do" value="inserer">
        <button class="btn btn-primary" type="submit" data-busy="Ajout en cours…"><?= icon('chart') ?> Compter les visites de mon site</button></form>
    <p class="muted small">Vous pouvez aussi coller cette ligne vous-même dans vos pages :</p>
    <?php else: ?>
    <p><strong>Pour compter les visites</strong>, collez cette ligne une seule fois dans votre site (dans le modèle commun à toutes les pages si possible), puis activez les statistiques.</p>
    <?php endif ?>
    <?php if ($role !== 'editor'): ?>
    <div class="copy-row"><input type="text" readonly value="<?= e($line) ?>" class="code" aria-label="Ligne à coller dans votre site" data-select><button type="button" class="btn btn-small" data-copy><?= icon('copy') ?> Copier</button></div>
    <form method="post" action="<?= e($base) ?>/statistiques/action" class="mt-6"><?= csrf_field() ?><input type="hidden" name="do" value="activer">
        <button class="btn<?= $canInsert ? ' btn-small btn-quiet' : ' btn-primary' ?>" type="submit">J'ai collé la ligne : activer les statistiques</button></form>
    <?php endif ?>
</div>
<?php endif ?>

<?php if ($enabled && $stats['any']): ?>
<div class="stats">
    <div class="stat"><span class="stat-num"><?= $stats['visits'] ?></span><span class="muted"><?= $stats['visits'] > 1 ? 'visites' : 'visite' ?></span>
        <?php if ($trend !== null): ?><span class="small muted"><?= $trend >= 0 ? '+' : '−' ?><?= abs($trend) ?> % par rapport aux <?= $days ?> jours d'avant</span><?php endif ?></div>
    <div class="stat"><span class="stat-num"><?= $stats['views'] ?></span><span class="muted"><?= $stats['views'] > 1 ? 'pages vues' : 'page vue' ?></span></div>
    <div class="stat"><span class="stat-num"><?= $stats['visits'] ? number_format($stats['views'] / $stats['visits'], 1, ',', '') : '0' ?></span><span class="muted">pages par visite</span></div>
</div>

<section class="section-block">
    <h2>Visites par jour</h2>
    <div class="bars" role="img" aria-label="Visites par jour sur <?= $days ?> jours">
        <?php foreach ($stats['perDay'] as $day => $d): ?>
        <div class="bar" title="<?= e(Text::date($day, false, true)) ?> : <?= $d['visits'] ?> visite<?= $d['visits'] > 1 ? 's' : '' ?>"><span class="bar-fill bar-h<?= (int) round($d['visits'] * 20 / $max) ?>"></span></div>
        <?php endforeach ?>
    </div>
    <div class="bars-legend muted small"><span><?= e(Text::date(array_key_first($stats['perDay']), false)) ?></span><span>Aujourd'hui</span></div>
</section>

<div class="two-cols">
    <section class="section-block">
        <h2>Pages les plus vues</h2>
        <?php if (!$stats['pages']): ?><p class="muted">Rien pour cette période.</p><?php else: ?>
        <table class="ledger"><tbody>
            <?php foreach ($stats['pages'] as $r): ?><tr><td><?= e($r['item'] === '/' ? 'Accueil' : $r['item']) ?></td><td class="shrink num"><?= $r['n'] ?></td></tr><?php endforeach ?>
        </tbody></table>
        <?php endif ?>
    </section>
    <section class="section-block">
        <h2>D'où viennent vos visiteurs</h2>
        <?php if (!$stats['sources']): ?><p class="muted">Surtout en tapant directement l'adresse de votre site, ou depuis un favori.</p><?php else: ?>
        <table class="ledger"><tbody>
            <?php foreach ($stats['sources'] as $r): ?><tr><td><?= e($r['item']) ?></td><td class="shrink num"><?= $r['n'] ?></td></tr><?php endforeach ?>
        </tbody></table>
        <p class="muted small">Les autres visiteurs ont tapé l'adresse directement ou viennent d'un favori.</p>
        <?php endif ?>
    </section>
</div>

<?php if ($role !== 'editor'): ?>
<details class="mt-6"><summary class="muted">Arrêter les statistiques</summary>
    <div class="actions mt-6">
        <?php if ($inserted): ?>
        <form method="post" action="<?= e($base) ?>/statistiques/action" class="inline" data-confirm="Retirer le compteur de toutes les pages de votre site ?"><?= csrf_field() ?><input type="hidden" name="do" value="retirer"><button class="btn btn-small" type="submit">Retirer le compteur de mes pages</button></form>
        <?php endif ?>
        <form method="post" action="<?= e($base) ?>/statistiques/action" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="desactiver"><button class="btn btn-small btn-quiet" type="submit">Ne plus compter</button></form>
    </div>
</details>
<?php endif ?>
<?php endif ?>
