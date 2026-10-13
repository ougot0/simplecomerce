<?php
/** @var array $settings @var string $keyHint */
?>
<div class="page-head"><div><h1>E-mails</h1><p class="page-intro"><a href="/admin">Administration</a> · Pour que les e-mails du portail (connexion, invitations, commandes, rappels) arrivent bien dans la boîte de réception.</p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
<div class="notice <?= $keyHint ? 'notice-ok' : 'notice-warn' ?>"><p><?= $keyHint ? '<strong>Brevo est branché</strong> (clé ' . e($keyHint) . ').' : '<strong>Les e-mails partent par l\'hébergeur</strong> : ils arrivent parfois dans les indésirables. Branchez Brevo (gratuit jusqu\'à 300 e-mails par jour).' ?></p></div>
<div class="two-cols">
<section class="section-block">
    <h2>Brancher Brevo</h2>
    <ol class="steps-list">
        <li>Créez un compte gratuit sur <strong>brevo.com</strong>.</li>
        <li>Dans Brevo : <strong>Expéditeurs, domaines et IP dédiées → Domaines → Ajouter un domaine</strong> : <code>simplecommercess.com</code>. Brevo vous donne 3 ou 4 lignes à recopier dans OVH (<strong>Noms de domaine → simplecommercess.com → Zone DNS → Ajouter une entrée</strong>). C'est ce qui évite les indésirables.</li>
        <li>Dans Brevo : <strong>votre nom en haut à droite → SMTP et API → Clés API → Générer une clé</strong>. Copiez-la (elle commence par <code>xkeysib-</code>).</li>
        <li>Collez-la ici, avec l'adresse d'expédition (par exemple <code>contact@simplecommercess.com</code>), puis envoyez-vous un e-mail de test.</li>
    </ol>
    <form method="post" action="/admin/emails" class="form panel"><?= csrf_field() ?>
        <div class="field"><label for="brevo-key">Clé Brevo <?= $keyHint ? '<span class="optional">(enregistrée ' . e($keyHint) . ' — laissez vide pour la garder)</span>' : '' ?></label>
            <input id="brevo-key" name="brevoKey" type="password" autocomplete="off" class="code" placeholder="xkeysib-…"><span class="help">Chiffrée, jamais réaffichée.</span></div>
        <div class="field"><label for="sender">Adresse d'expédition</label>
            <input id="sender" name="senderEmail" type="email" value="<?= e($settings['senderEmail']) ?>" placeholder="contact@simplecommercess.com">
            <span class="help">Une adresse de votre domaine, validée dans Brevo. Les réponses des clients arrivent sur <?= e(\SimpleCommerce\Config::contactEmail()) ?>.</span></div>
        <div class="field"><label for="sender-name">Nom affiché</label><input id="sender-name" name="senderName" type="text" maxlength="80" value="<?= e($settings['senderName']) ?>"></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>
</section>
<section class="section-block">
    <h2>Vérifier</h2>
    <form method="post" action="/admin/emails" class="panel"><?= csrf_field() ?><input type="hidden" name="intent" value="test">
        <p>Un e-mail de test part vers votre adresse d'administrateur.</p>
        <button class="btn" type="submit" data-busy="Envoi…">Envoyer un e-mail de test</button></form>
    <?php if ($keyHint): ?>
    <form method="post" action="/admin/emails" class="mt-12" data-confirm="Retirer Brevo ? Les e-mails repartiront par l'hébergeur."><?= csrf_field() ?><input type="hidden" name="intent" value="retirer">
        <button class="btn btn-small btn-quiet" type="submit">Retirer Brevo</button></form>
    <?php endif ?>
</section>
</div>
