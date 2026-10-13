<?php /** @var string $email */ ?>
<div class="page-head"><div><h1>Confirmez votre adresse e-mail</h1></div></div>
<div class="panel narrow-block">
    <p>Pour relier votre site, confirmez d'abord votre adresse : nous avons envoyé un lien à <strong><?= e($email) ?></strong>.</p>
    <p class="muted">Pas reçu ? Regardez dans les indésirables (ou « Promotions »), puis renvoyez-le.</p>
    <form method="post" action="/verifier/renvoyer"><?= csrf_field() ?><input type="hidden" name="next" value="/sites/nouveau">
        <button class="btn btn-primary" type="submit" data-busy="Envoi…">Renvoyer l'e-mail de confirmation</button></form>
    <p class="small muted mt-12">Mauvaise adresse ? Créez un nouveau compte avec la bonne, ou écrivez à <a href="mailto:<?= e(\SimpleCommerce\Config::contactEmail()) ?>"><?= e(\SimpleCommerce\Config::contactEmail()) ?></a>.</p>
</div>
