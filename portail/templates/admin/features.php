<?php
/** @var array<string, bool> $on */
use SimpleCommerce\Services\Features;
?>
<div class="page-head"><div><h1>Options du portail</h1><p class="page-intro"><a href="/admin">Administration</a> · Allumez ou éteignez une option pour tous vos clients. Éteinte, elle disparaît des menus ; rien n'est effacé, et la rallumer remet tout comme avant.</p></div></div>
<form method="post" action="/admin/options" class="form panel narrow-block"><?= csrf_field() ?>
    <?php foreach (Features::LIST as $key => [$label, $help]): ?>
    <div class="field">
        <label class="switch"><input type="checkbox" name="on[<?= e($key) ?>]" value="1"<?= $on[$key] ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span><strong><?= e($label) ?></strong></span></label>
        <span class="help"><?= e($help) ?></span>
    </div>
    <?php endforeach ?>
    <div class="actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
</form>
<p class="muted mt-12">Pour changer l'apparence du portail ou ajouter une nouvelle option, demandez-le simplement à Claude dans votre conversation Simple Commerce.</p>
