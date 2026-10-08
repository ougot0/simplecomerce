<?php
declare(strict_types=1);

namespace SimpleCommerce;

/**
 * Lecture / écriture des données du portail. Aucune méthode ne vérifie les droits :
 * c'est le rôle de Services\Access, appelé au début de chaque page et de chaque action.
 */
final class Repo
{
    private static function j(mixed $v): ?string
    {
        return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function dj(?string $v): mixed
    {
        return $v === null ? null : json_decode($v, true);
    }

    // ------------------------------------------------------------ comptes

    public static function user(?string $id): ?array
    {
        return $id ? self::userRow(Db::one('SELECT * FROM sc_users WHERE id = ?', [$id])) : null;
    }

    public static function userByEmail(string $email): ?array
    {
        return self::userRow(Db::one('SELECT * FROM sc_users WHERE email = ?', [mb_strtolower(trim($email))]));
    }

    private static function userRow(?array $r): ?array
    {
        if (!$r) {
            return null;
        }
        $r['is_admin'] = (bool) $r['is_admin'];
        $r['notify'] = (bool) $r['notify'];
        return $r;
    }

    public static function users(): array
    {
        return array_map([self::class, 'userRow'], Db::all('SELECT * FROM sc_users ORDER BY name'));
    }

    public static function createUser(string $email, string $name, string $password, bool $admin = false): array
    {
        $row = ['id' => Db::uuid(), 'email' => mb_strtolower(trim($email)), 'name' => trim($name), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => $admin ? 1 : 0, 'notify' => 1, 'created_at' => Db::now()];
        Db::insert('sc_users', $row);
        return self::user($row['id']);
    }

    public static function updateUser(string $id, array $patch): void
    {
        Db::update('sc_users', $patch, 'id = :id', [':id' => $id]);
    }

    // ------------------------------------------------------------ sites

    private static function siteRow(?array $r): ?array
    {
        if (!$r) {
            return null;
        }
        $r['config'] = self::dj($r['config']) ?? [];
        $r['last_check_ok'] = $r['last_check_ok'] === null ? null : (bool) $r['last_check_ok'];
        return $r;
    }

    public static function site(string $id): ?array
    {
        return self::siteRow(Db::one('SELECT * FROM sc_sites WHERE id = ?', [$id]));
    }

    public static function siteBySlug(string $slug): ?array
    {
        return self::siteRow(Db::one('SELECT * FROM sc_sites WHERE slug = ?', [$slug]));
    }

    public static function sites(): array
    {
        return array_map([self::class, 'siteRow'], Db::all('SELECT * FROM sc_sites ORDER BY name'));
    }

    public static function sitesForUser(string $userId): array
    {
        return array_map(fn ($r) => self::siteRow($r), Db::all('SELECT s.*, m.role FROM sc_sites s JOIN sc_members m ON m.site_id = s.id WHERE m.user_id = ? ORDER BY s.name', [$userId]));
    }

    public static function slugExists(string $slug): bool
    {
        return Db::value('SELECT 1 FROM sc_sites WHERE slug = ?', [$slug]) !== null;
    }

    public static function createSite(array $site, string $ownerId): array
    {
        $now = Db::now();
        $id = Db::uuid();
        Db::transaction(function () use ($site, $ownerId, $now, $id) {
            Db::insert('sc_sites', ['id' => $id, 'slug' => $site['slug'], 'name' => $site['name'], 'public_url' => $site['public_url'], 'connector' => $site['connector'],
                'config' => self::j($site['config']), 'status' => 'active', 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now]);
            Db::insert('sc_members', ['site_id' => $id, 'user_id' => $ownerId, 'role' => 'owner', 'created_at' => $now]);
        });
        return self::site($id);
    }

    public static function updateSite(string $id, array $patch): void
    {
        if (array_key_exists('config', $patch)) {
            $patch['config'] = self::j($patch['config']);
        }
        if (array_key_exists('last_check_ok', $patch) && $patch['last_check_ok'] !== null) {
            $patch['last_check_ok'] = $patch['last_check_ok'] ? 1 : 0;
        }
        $patch['updated_at'] = Db::now();
        Db::update('sc_sites', $patch, 'id = :id', [':id' => $id]);
    }

    public static function deleteSite(string $id): void
    {
        Db::transaction(function () use ($id) {
            foreach (['sc_members', 'sc_credentials', 'sc_drafts', 'sc_invitations', 'sc_schemas'] as $t) {
                Db::run("DELETE FROM $t WHERE site_id = ?", [$id]);
            }
            Db::run('DELETE FROM sc_sites WHERE id = ?', [$id]);
        });
    }

    // ------------------------------------------------------------ membres

    public static function members(string $siteId): array
    {
        return array_map(fn ($r) => $r + ['user' => self::user($r['user_id'])], Db::all('SELECT * FROM sc_members WHERE site_id = ? ORDER BY created_at', [$siteId]));
    }

    public static function membership(string $siteId, string $userId): ?array
    {
        return Db::one('SELECT * FROM sc_members WHERE site_id = ? AND user_id = ?', [$siteId, $userId]);
    }

    public static function addMember(string $siteId, string $userId, string $role): void
    {
        if (self::membership($siteId, $userId)) {
            Db::run('UPDATE sc_members SET role = ? WHERE site_id = ? AND user_id = ?', [$role, $siteId, $userId]);
        } else {
            Db::insert('sc_members', ['site_id' => $siteId, 'user_id' => $userId, 'role' => $role, 'created_at' => Db::now()]);
        }
    }

    public static function removeMember(string $siteId, string $userId): void
    {
        Db::run('DELETE FROM sc_members WHERE site_id = ? AND user_id = ?', [$siteId, $userId]);
    }

    // ------------------------------------------------------------ identifiants (chiffrés)

    public static function credentials(string $siteId): ?array
    {
        $r = Db::one('SELECT * FROM sc_credentials WHERE site_id = ?', [$siteId]);
        if (!$r) {
            return null;
        }
        $r['fingerprints'] = self::dj($r['fingerprints']) ?? [];
        return $r;
    }

    public static function setCredentials(string $siteId, string $sealed, array $fingerprints, string $by): void
    {
        Db::run('DELETE FROM sc_credentials WHERE site_id = ?', [$siteId]);
        Db::insert('sc_credentials', ['site_id' => $siteId, 'sealed' => $sealed, 'fingerprints' => self::j($fingerprints), 'updated_at' => Db::now(), 'updated_by' => $by]);
    }

    // ------------------------------------------------------------ schémas

    public static function schema(string $siteId): ?array
    {
        $r = Db::one('SELECT * FROM sc_schemas WHERE site_id = ? ORDER BY version DESC LIMIT 1', [$siteId]);
        return $r ? self::dj($r['definition']) : null;
    }

    public static function saveSchema(string $siteId, array $definition, string $by): void
    {
        $version = (int) Db::value('SELECT MAX(version) FROM sc_schemas WHERE site_id = ?', [$siteId]) + 1;
        Db::insert('sc_schemas', ['id' => Db::uuid(), 'site_id' => $siteId, 'version' => $version, 'definition' => self::j($definition), 'created_by' => $by, 'created_at' => Db::now()]);
    }

    // ------------------------------------------------------------ historique

    private static function changeRow(?array $r): ?array
    {
        if (!$r) {
            return null;
        }
        foreach (['before_json', 'after_json', 'before_order', 'after_order'] as $k) {
            $r[$k] = self::dj($r[$k]);
        }
        return $r;
    }

    public static function insertChange(array $c): array
    {
        $row = $c + ['id' => Db::uuid(), 'created_at' => Db::now(), 'status' => 'pending'];
        foreach (['before_json', 'after_json', 'before_order', 'after_order'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = self::j($row[$k]);
            }
        }
        Db::insert('sc_changes', $row);
        return self::change($row['id']);
    }

    public static function updateChange(string $id, array $patch): void
    {
        foreach (['before_json', 'after_json', 'before_order', 'after_order'] as $k) {
            if (array_key_exists($k, $patch)) {
                $patch[$k] = self::j($patch[$k]);
            }
        }
        Db::update('sc_changes', $patch, 'id = :id', [':id' => $id]);
    }

    public static function change(string $id): ?array
    {
        return self::changeRow(Db::one('SELECT * FROM sc_changes WHERE id = ?', [$id]));
    }

    public static function changes(string $siteId, int $limit = 100, ?string $sectionKey = null): array
    {
        $sql = 'SELECT * FROM sc_changes WHERE site_id = ?' . ($sectionKey ? ' AND section_key = ?' : '') . ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
        return array_map([self::class, 'changeRow'], Db::all($sql, $sectionKey ? [$siteId, $sectionKey] : [$siteId]));
    }

    public static function countChangesSince(string $since, ?string $siteId = null): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM sc_changes WHERE status = \'applied\' AND created_at >= ?' . ($siteId ? ' AND site_id = ?' : ''), $siteId ? [$since, $siteId] : [$since]);
    }

    // ------------------------------------------------------------ brouillons

    private static function draftRow(?array $r): ?array
    {
        if (!$r) {
            return null;
        }
        $r['data'] = self::dj($r['data']) ?? [];
        $r['base_data'] = self::dj($r['base_data']);
        return $r;
    }

    public static function drafts(string $siteId): array
    {
        return array_map([self::class, 'draftRow'], Db::all('SELECT * FROM sc_drafts WHERE site_id = ? ORDER BY updated_at DESC', [$siteId]));
    }

    public static function draft(string $id): ?array
    {
        return self::draftRow(Db::one('SELECT * FROM sc_drafts WHERE id = ?', [$id]));
    }

    public static function findDraft(string $siteId, string $sectionKey, ?string $entryId): ?array
    {
        if ($entryId === null) {
            return null;
        }
        return self::draftRow(Db::one('SELECT * FROM sc_drafts WHERE site_id = ? AND section_key = ? AND entry_id = ? LIMIT 1', [$siteId, $sectionKey, $entryId]));
    }

    public static function saveDraft(array $d): array
    {
        $now = Db::now();
        $row = ['site_id' => $d['site_id'], 'section_key' => $d['section_key'], 'entry_id' => $d['entry_id'], 'label' => mb_substr($d['label'], 0, 250),
            'data' => self::j($d['data']), 'base_data' => self::j($d['base_data']), 'publish_at' => $d['publish_at'] ?? null, 'updated_by' => $d['updated_by'], 'updated_at' => $now];
        if (!empty($d['id']) && self::draft($d['id'])) {
            Db::update('sc_drafts', $row, 'id = :id', [':id' => $d['id']]);
            return self::draft($d['id']);
        }
        $id = Db::uuid();
        Db::insert('sc_drafts', $row + ['id' => $id, 'created_by' => $d['updated_by'], 'created_at' => $now]);
        return self::draft($id);
    }

    public static function deleteDraft(string $id): void
    {
        Db::run('DELETE FROM sc_drafts WHERE id = ?', [$id]);
    }

    public static function dueDrafts(string $now): array
    {
        return array_map([self::class, 'draftRow'], Db::all('SELECT * FROM sc_drafts WHERE publish_at IS NOT NULL AND publish_at <= ? ORDER BY publish_at', [$now]));
    }

    // ------------------------------------------------------------ photos

    public static function insertMedia(array $m): array
    {
        Db::insert('sc_media', $m + ['created_at' => Db::now()]);
        return self::media($m['id']);
    }

    public static function media(string $id): ?array
    {
        return Db::one('SELECT * FROM sc_media WHERE id = ?', [$id]);
    }

    public static function setMediaPublicValue(string $id, string $value): void
    {
        Db::run('UPDATE sc_media SET public_value = ? WHERE id = ?', [$value, $id]);
    }

    /** Photos déjà envoyées sur le site, réutilisables (« Mes photos »). */
    public static function siteMedia(string $siteId, int $limit = 120): array
    {
        return Db::all('SELECT * FROM sc_media WHERE site_id = ? AND public_value IS NOT NULL ORDER BY created_at DESC LIMIT ' . (int) $limit, [$siteId]);
    }

    // ------------------------------------------------------------ invitations

    public static function createInvitation(array $inv): void
    {
        Db::insert('sc_invitations', $inv + ['id' => Db::uuid(), 'created_at' => Db::now()]);
    }

    public static function invitationByHash(string $hash): ?array
    {
        return Db::one('SELECT * FROM sc_invitations WHERE token_hash = ?', [$hash]);
    }

    public static function invitations(string $siteId): array
    {
        return Db::all('SELECT * FROM sc_invitations WHERE site_id = ? AND accepted_at IS NULL ORDER BY created_at DESC', [$siteId]);
    }

    public static function acceptInvitation(array $inv, string $userId): void
    {
        Db::transaction(function () use ($inv, $userId) {
            Db::run('UPDATE sc_invitations SET accepted_at = ?, accepted_by = ? WHERE id = ? AND accepted_at IS NULL', [Db::now(), $userId, $inv['id']]);
            if (!self::membership($inv['site_id'], $userId)) {
                self::addMember($inv['site_id'], $userId, $inv['role']);
            }
        });
    }

    public static function deleteInvitation(string $id): void
    {
        Db::run('DELETE FROM sc_invitations WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------ journal

    public static function log(?string $actor, ?string $onBehalf, ?string $siteId, string $kind, array $details = []): void
    {
        Db::insert('sc_events', ['id' => Db::uuid(), 'at' => Db::now(), 'actor_id' => $actor, 'on_behalf_of' => $onBehalf, 'site_id' => $siteId, 'kind' => $kind, 'details' => self::j($details)]);
    }

    public static function events(int $limit = 300, ?string $siteId = null): array
    {
        $rows = Db::all('SELECT * FROM sc_events' . ($siteId ? ' WHERE site_id = ?' : '') . ' ORDER BY at DESC LIMIT ' . (int) $limit, $siteId ? [$siteId] : []);
        return array_map(fn ($r) => ['details' => self::dj($r['details']) ?? []] + $r, $rows);
    }

    // ------------------------------------------------------------ assistance

    public static function createImpersonation(string $adminId, string $targetId, int $minutes): array
    {
        $row = ['id' => Db::uuid(), 'admin_id' => $adminId, 'target_id' => $targetId, 'started_at' => Db::now(), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $minutes * 60)];
        Db::insert('sc_impersonations', $row);
        return $row;
    }

    public static function impersonation(string $id): ?array
    {
        return Db::one('SELECT * FROM sc_impersonations WHERE id = ?', [$id]);
    }

    public static function endImpersonation(string $id): void
    {
        Db::run('UPDATE sc_impersonations SET ended_at = ? WHERE id = ? AND ended_at IS NULL', [Db::now(), $id]);
    }

    // ------------------------------------------------------------ jetons (lien de connexion, « rester connecté »)

    public static function createToken(string $userId, string $kind, int $seconds, ?array $extra = null): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::insert('sc_tokens', ['id' => Db::uuid(), 'user_id' => $userId, 'kind' => $kind, 'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $seconds), 'created_at' => Db::now(), 'extra' => self::j($extra)]);
        return $token;
    }

    /** Vérifie un jeton ; les liens de connexion ne servent qu'une fois. */
    public static function useToken(string $token, string $kind, bool $consume): ?array
    {
        $r = Db::one('SELECT * FROM sc_tokens WHERE token_hash = ? AND kind = ?', [hash('sha256', $token), $kind]);
        if (!$r || $r['expires_at'] < Db::now()) {
            return null;
        }
        if ($consume) {
            Db::run('DELETE FROM sc_tokens WHERE id = ?', [$r['id']]);
        }
        $r['extra'] = self::dj($r['extra']);
        return $r;
    }

    public static function deleteToken(string $token): void
    {
        Db::run('DELETE FROM sc_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    // ------------------------------------------------------------ limitation des tentatives

    public static function tooManyAttempts(string $bucket, int $max, int $seconds): bool
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
        Db::run('DELETE FROM sc_attempts WHERE at < ?', [gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
        return (int) Db::value('SELECT COUNT(*) FROM sc_attempts WHERE bucket = ? AND at >= ?', [$bucket, $since]) >= $max;
    }

    public static function recordAttempt(string $bucket): void
    {
        Db::insert('sc_attempts', ['id' => Db::uuid(), 'bucket' => $bucket, 'at' => Db::now()]);
    }
}
