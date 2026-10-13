<?php
/** Bandeau « Confirmez votre adresse » pour un compte non confirmé. @var ?array $viewer */
if (!empty($viewer) && !$viewer['assist'] && !$viewer['user']['is_admin'] && !\SimpleCommerce\Services\Auth::isVerified($viewer['user'])): ?>
<form method="post" action="/verifier/renvoyer" class="notice notice-warn billing-banner"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e(\SimpleCommerce\Http\Req::path()) ?>">
    <p><strong>Confirmez votre adresse e-mail</strong> avec le lien envoyé à <?= e($viewer['user']['email']) ?>. <button class="btn-link" type="submit">Renvoyer l'e-mail</button></p></form>
<?php endif ?>
