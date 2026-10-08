<?php /** @var array $inv @var array $site @var ?array $inviter @var ?array $viewer @var string $token */
$next = '/invitation/' . $token; ?>
<h2><?= e($site['name']) ?></h2>
<p><?= e($inviter['name'] ?? "Quelqu'un") ?> vous invite à <?= $inv['role'] === 'owner' ? 'gérer' : 'mettre à jour' ?> ce site avec Simple Commerce.</p>
<?php if (!empty($error)): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif ?>
<?php if (!$viewer): ?>
<div class="actions mt-16">
    <a class="btn btn-primary" href="/inscription?next=<?= e(rawurlencode($next)) ?>&amp;email=<?= e(rawurlencode($inv['email'])) ?>">Créer mon compte</a>
    <a class="btn" href="/connexion?next=<?= e(rawurlencode($next)) ?>">J'ai déjà un compte</a>
</div>
<?php elseif (mb_strtolower($viewer['effective']['email']) !== mb_strtolower($inv['email'])): ?>
<div class="notice notice-warn">
    <p>Cette invitation est destinée à <strong><?= e($inv['email']) ?></strong>, mais vous êtes connecté avec <?= e($viewer['effective']['email']) ?>. Déconnectez-vous puis rouvrez ce lien.</p>
    <form action="/deconnexion" method="post"><?= csrf_field() ?><button class="btn btn-small" type="submit">Se déconnecter</button></form>
</div>
<?php else: ?>
<form method="post" class="mt-16"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Accepter l'invitation</button></form>
<?php endif ?>
