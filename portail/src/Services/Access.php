<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Session;

/**
 * Qui fait la demande, et à quoi a-t-il droit. Chaque page et chaque action commence par ces fonctions.
 * Mode assistance : l'administrateur voit l'espace d'un client tel que celui-ci le voit, une heure au plus,
 * et tout ce qu'il fait est enregistré à son propre nom.
 */
final class Access
{
    public const ASSIST_MINUTES = 60;
    private static ?array $viewer = null;
    private static bool $resolved = false;

    /** @return array{user: array, effective: array, assist: ?string}|null */
    public static function viewer(): ?array
    {
        if (self::$resolved) {
            return self::$viewer;
        }
        self::$resolved = true;
        $user = Repo::user(Auth::userId());
        if (!$user) {
            return self::$viewer = null;
        }
        $assistId = Session::get('assist');
        if ($assistId && $user['is_admin']) {
            $imp = Repo::impersonation($assistId);
            if ($imp && !$imp['ended_at'] && $imp['expires_at'] > gmdate('Y-m-d\TH:i:s\Z') && $imp['admin_id'] === $user['id']) {
                $target = Repo::user($imp['target_id']);
                if ($target) {
                    return self::$viewer = ['user' => $user, 'effective' => $target, 'assist' => $imp['id']];
                }
            }
            Session::forget('assist');
        }
        return self::$viewer = ['user' => $user, 'effective' => $user, 'assist' => null];
    }

    public static function reset(): void
    {
        self::$resolved = false;
        self::$viewer = null;
    }

    public static function requireViewer(): array
    {
        $v = self::viewer();
        if (!$v) {
            throw Halt::redirect('/connexion?suite=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
        }
        return $v;
    }

    public static function isAdmin(?array $v = null): bool
    {
        $v ??= self::viewer();
        return $v && $v['user']['is_admin'] && !$v['assist'];
    }

    public static function requireAdmin(): array
    {
        $v = self::requireViewer();
        if (!self::isAdmin($v)) {
            throw Halt::notFound();
        }
        return $v;
    }

    /**
     * Accès à un site. 404 (et non 403) si la personne n'y a pas droit, pour ne pas révéler que le site existe.
     * @return array{viewer: array, site: array, role: string, schema: array, sections: array}
     */
    public static function site(string $slug, string $minimum = 'editor'): array
    {
        $v = self::requireViewer();
        if (!preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
            throw Halt::notFound();
        }
        $site = Repo::siteBySlug($slug);
        if (!$site) {
            throw Halt::notFound();
        }
        $role = self::isAdmin($v) ? 'admin' : (Repo::membership($site['id'], $v['effective']['id'])['role'] ?? null);
        if (!$role || ($minimum === 'owner' && $role === 'editor') || ($site['status'] === 'suspended' && $role !== 'admin')) {
            throw Halt::notFound();
        }
        $schema = Repo::schema($site['id']) ?? ['version' => 1, 'sections' => []];
        return ['viewer' => $v, 'site' => $site, 'role' => $role, 'schema' => $schema, 'sections' => array_values(array_filter($schema['sections'], fn ($s) => empty($s['hidden'])))];
    }

    public static function section(array $ctx, string $key): array
    {
        $section = ContentSchema::find($ctx['schema'], $key);
        if (!$section || !empty($section['hidden'])) {
            throw Halt::notFound();
        }
        return $section;
    }

    public static function startAssist(array $admin, string $targetId): void
    {
        $imp = Repo::createImpersonation($admin['id'], $targetId, self::ASSIST_MINUTES);
        Session::set('assist', $imp['id']);
        self::reset();
    }

    public static function stopAssist(): ?string
    {
        $id = Session::get('assist');
        Session::forget('assist');
        if ($id) {
            Repo::endImpersonation($id);
        }
        self::reset();
        return $id;
    }

    public static function audit(?array $v, string $kind, array $details = [], ?string $siteId = null): void
    {
        Repo::log($v['user']['id'] ?? null, $v && $v['effective']['id'] !== $v['user']['id'] ? $v['effective']['id'] : null, $siteId, $kind, $details);
    }
}
