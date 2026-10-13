<?php
/** @var string $keyHint */
?>
<div class="page-head"><div><h1>Rédaction automatique</h1><p class="page-intro"><a href="/admin">Administration</a> · Le bouton « Écrire pour moi » sous les descriptions, et la version anglaise des sites, utilisent Claude (Anthropic).</p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
<div class="notice <?= $keyHint ? 'notice-ok' : 'notice-warn' ?>"><p><?= $keyHint ? '<strong>Activée</strong> (clé ' . e($keyHint) . '). Vos clients voient « Écrire pour moi » sous leurs champs de description.' : '<strong>Pas encore activée.</strong> Sans clé, les boutons n\'apparaissent pas.' ?></p></div>
<div class="two-cols">
<section class="section-block">
    <h2>Brancher Claude</h2>
    <ol class="steps-list">
        <li>Créez un compte sur <strong>platform.claude.com</strong> (celui des développeurs, différent de claude.ai).</li>
        <li><strong>Billing</strong> : ajoutez une carte et un peu de crédit (5 € suffisent pour des centaines de textes). Réglez une limite de dépense mensuelle si vous le souhaitez.</li>
        <li><strong>API keys → Create key</strong>, nommez-la « Simple Commerce », copiez-la (elle commence par <code>sk-ant-</code>).</li>
        <li>Collez-la ici, enregistrez, puis cliquez sur « Tester ».</li>
    </ol>
    <form method="post" action="/admin/redaction" class="form panel"><?= csrf_field() ?>
        <div class="field"><label for="ai-key">Clé Claude <?= $keyHint ? '<span class="optional">(enregistrée ' . e($keyHint) . ')</span>' : '' ?></label>
            <input id="ai-key" name="apiKey" type="password" autocomplete="off" class="code" placeholder="sk-ant-…"><span class="help">Chiffrée, jamais réaffichée, jamais envoyée au navigateur.</span></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
</section>
<section class="section-block">
    <h2>Vérifier</h2>
    <form method="post" action="/admin/redaction" class="panel"><?= csrf_field() ?><input type="hidden" name="intent" value="test">
        <p>Envoie une petite demande à Claude.</p>
        <button class="btn" type="submit" data-busy="Test…"<?= $keyHint ? '' : ' disabled' ?>>Tester</button></form>
    <p class="muted mt-12">Chaque personne peut demander <?= \SimpleCommerce\Services\Writer::PER_DAY ?> textes par jour au plus. Un texte coûte environ un centime, payé sur votre compte Claude.</p>
    <?php if ($keyHint): ?>
    <form method="post" action="/admin/redaction" class="mt-12" data-confirm="Désactiver la rédaction automatique ?"><?= csrf_field() ?><input type="hidden" name="intent" value="retirer">
        <button class="btn btn-small btn-quiet" type="submit">Désactiver</button></form>
    <?php endif ?>
</section>
</div>
