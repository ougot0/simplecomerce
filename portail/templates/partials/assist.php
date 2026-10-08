<?php /** @var ?array $viewer */ if (!empty($viewer['assist'])): ?>
<form method="post" action="/admin/fin-assistance" class="assist-bar">
    <?= csrf_field() ?>
    <span>Vous agissez pour <?= e($viewer['effective']['name'] ?: $viewer['effective']['email']) ?>. Chaque modification est enregistrée à votre nom.</span>
    <button class="btn btn-small" type="submit">Revenir à mon compte</button>
</form>
<?php endif;
