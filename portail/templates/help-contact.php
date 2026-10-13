<?php /** @var array $viewer @var string $from */ ?>
<div class="page-head"><div><h1>Besoin d'aide ?</h1><p class="page-intro">Écrivez-nous votre question : nous vous répondons par e-mail à <strong><?= e($viewer['effective']['email']) ?></strong>, en général dans la journée. Vous pouvez aussi consulter <a href="/aide">l'aide</a>.</p></div></div>
<?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
<form method="post" action="/aide/contact" class="form panel narrow-block"><?= csrf_field() ?>
    <input type="hidden" name="from" value="<?= e($from) ?>">
    <div class="field"><label for="help-message">Votre question</label>
        <textarea id="help-message" name="message" rows="7" maxlength="4000" required placeholder="Exemple : je n'arrive pas à changer la photo de mon gâteau au chocolat."><?= e($message ?? '') ?></textarea>
        <span class="help">Dites ce que vous vouliez faire et ce qui s'est passé. La page où vous étiez est jointe automatiquement.</span></div>
    <div class="actions"><button class="btn btn-primary" type="submit" data-busy="Envoi…">Envoyer</button> <a class="btn btn-quiet" href="<?= e($from) ?>">Annuler</a></div>
</form>
