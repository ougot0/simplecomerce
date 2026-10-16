<?php /** @var string $claim */ ?>
<h2>Merci, votre adresse est confirmée</h2>
<p>Vous pouvez <strong>retourner dans l'application</strong> (ou sur la page où vous avez créé votre compte) : vous y êtes toujours connecté, et vous pouvez relier votre site.</p>
<p><a class="btn btn-primary" href="/sites">Continuer ici</a></p>
<details class="mt-12"><summary class="muted small">Ce n'est pas vous qui avez créé ce compte ?</summary>
    <p class="small mt-8">Quelqu'un a peut-être utilisé votre adresse. Reprenez le compte : son mot de passe sera annulé, tous les appareils seront déconnectés, et vous choisirez le vôtre.</p>
    <form method="post" action="/verifier/reprendre/<?= e($claim) ?>"><?= csrf_field() ?>
        <button class="btn btn-small" type="submit">Reprendre ce compte</button></form>
</details>
