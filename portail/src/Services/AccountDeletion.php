<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Db;
use SimpleCommerce\Repo;

/**
 * Suppression d'un compte par la personne elle-même (exigée par l'App Store, et droit RGPD).
 * Les sites des clients ne sont jamais touchés : seuls les liens avec Simple Commerce disparaissent.
 */
final class AccountDeletion
{
    /** Raison qui empêche la suppression, ou null si elle est possible. */
    public static function problem(array $user): ?string
    {
        if (!empty($user['is_admin']) && (int) Db::value('SELECT COUNT(*) FROM sc_users WHERE is_admin = 1') <= 1) {
            return "Vous êtes le seul administrateur du portail : votre compte ne peut pas être supprimé.";
        }
        return null;
    }

    /** Ce qui arrivera à chaque site : « retire » (plus personne) ou « transmis » (quelqu'un d'autre reste). */
    public static function plan(string $userId): array
    {
        $plan = [];
        foreach (Repo::sitesForUser($userId) as $site) {
            $others = array_values(array_filter(Repo::members($site['id']), fn ($m) => $m['user_id'] !== $userId && $m['user']));
            $plan[] = ['site' => $site, 'role' => $site['role'], 'others' => $others,
                'fate' => !$others ? 'retire' : ($site['role'] === 'owner' ? 'transmis' : 'quitte')];
        }
        return $plan;
    }

    public static function delete(array $user): void
    {
        $id = $user['id'];
        Billing::cancelForDeletion($id); // d'abord : si Stripe refuse, rien n'est supprimé
        $plan = self::plan($id);
        Db::transaction(function () use ($id, $user, $plan) {
            foreach ($plan as $p) {
                $siteId = $p['site']['id'];
                if ($p['fate'] === 'retire') {
                    continue; // supprimé hors transaction (Repo::deleteSite a la sienne)
                }
                Repo::removeMember($siteId, $id);
                $hasOwner = array_filter($p['others'], fn ($m) => $m['role'] === 'owner');
                if ($p['fate'] === 'transmis' && !$hasOwner) {
                    Repo::addMember($siteId, $p['others'][0]['user_id'], 'owner'); // la personne arrivée la première prend le relais
                }
            }
            Db::run('DELETE FROM sc_tokens WHERE user_id = ?', [$id]);
            Db::run('DELETE FROM sc_billing WHERE user_id = ?', [$id]);
            Db::run('DELETE FROM sc_invitations WHERE email = ? AND accepted_at IS NULL', [$user['email']]);
            Db::run('DELETE FROM sc_meta WHERE name IN (?, ?, ?)', ['unverified:' . $id, 'referredBy:' . $id, 'refRewarded:' . $id]);
            Db::run("DELETE FROM sc_meta WHERE name LIKE 'ref:%' AND value = ?", [$id]);
            Db::run('DELETE FROM sc_users WHERE id = ?', [$id]);
        });
        foreach ($plan as $p) {
            if ($p['fate'] === 'retire') {
                Repo::deleteSite($p['site']['id']);
            }
        }
    }
}
