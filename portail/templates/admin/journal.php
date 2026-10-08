<?php /** @var array $events @var array $names @var array $sites @var string $siteId */
$kinds = ['login' => 'Connexion', 'signup' => 'Création de compte', 'site_created' => 'Site relié', 'site_removed' => 'Site retiré', 'site_suspended' => 'Site suspendu',
    'site_reactivated' => 'Site réactivé', 'schema_detected' => 'Contenu détecté', 'schema_updated' => 'Rubriques modifiées', 'connection_updated' => 'Accès modifiés',
    'member_invited' => 'Invitation envoyée', 'invitation_accepted' => 'Invitation acceptée', 'invitation_revoked' => 'Invitation annulée', 'member_removed' => 'Accès retiré',
    'impersonation_started' => "Début d'assistance", 'impersonation_ended' => "Fin d'assistance", 'draft_discarded' => 'Brouillon jeté', 'password_changed' => 'Mot de passe changé',
    'site_closed' => 'Site fermé temporairement', 'site_reopened' => 'Site rouvert', 'backup_downloaded' => 'Sauvegarde téléchargée', 'scheduled_published' => 'Publication programmée effectuée'];
?>
<div class="page-head">
    <div><div class="crumbs"><a href="/admin">Administration</a></div><h1>Journal d'activité</h1>
    <p class="page-intro">Connexions, accès, invitations, assistance. Les modifications de contenu sont dans l'historique de chaque site.</p></div>
    <form method="get" class="inline filter-form"><label class="visually-hidden" for="site">Site</label>
        <select id="site" name="site" data-autosubmit><option value="">Tous les sites</option><?php foreach ($sites as $s): ?><option value="<?= e($s['id']) ?>"<?= $siteId === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach ?></select>
        <noscript><button class="btn btn-small" type="submit">Filtrer</button></noscript></form>
</div>
<div class="ledger-wrap"><table class="ledger">
    <thead><tr><th>Quand</th><th>Quoi</th><th>Qui</th><th class="hide-small">Site</th><th class="hide-small">Détail</th></tr></thead>
    <tbody>
    <?php foreach ($events as $ev): $s = $ev['site_id'] ? ($sites[$ev['site_id']] ?? null) : null; ?>
    <tr>
        <td class="small nowrap"><?= e(when($ev['at'])) ?></td>
        <td><?= e($kinds[$ev['kind']] ?? $ev['kind']) ?></td>
        <td class="small"><?= e($ev['actor_id'] ? ($names[$ev['actor_id']] ?? '?') : '—') ?><?= $ev['on_behalf_of'] ? '<span class="muted"> pour ' . e($names[$ev['on_behalf_of']] ?? '?') . '</span>' : '' ?></td>
        <td class="hide-small small"><?= $s ? '<a href="/s/' . e($s['slug']) . '">' . e($s['name']) . '</a>' : '—' ?></td>
        <td class="hide-small small muted code"><?= $ev['details'] ? e(mb_substr(json_encode($ev['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 140)) : '' ?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
</table></div>
