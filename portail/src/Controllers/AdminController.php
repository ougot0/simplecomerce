<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Db;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Support\Session;

/** Administration : tous les sites, tous les comptes, journal, assistance. */
final class AdminController
{
    public static function index(): Response
    {
        $v = Access::requireAdmin();
        $sites = Repo::sites();
        $users = Repo::users();
        $members = [];
        $count = [];
        foreach ($sites as $s) {
            $list = Repo::members($s['id']);
            $members[$s['id']] = array_values(array_filter(array_map(fn ($m) => $m['user'] && !$m['user']['is_admin'] ? ($m['user']['name'] ?: $m['user']['email']) : null, $list)));
            foreach ($list as $m) {
                $count[$m['user_id']] = ($count[$m['user_id']] ?? 0) + 1;
            }
        }
        $week = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
        $stats = [
            'sites' => count($sites),
            'users' => count($users),
            'week' => Repo::countChangesSince($week),
            'failing' => count(array_filter($sites, fn ($s) => $s['last_check_ok'] === false)),
            'failedChanges' => (int) Db::value("SELECT COUNT(*) FROM sc_changes WHERE status = 'failed' AND created_at >= ?", [$week]),
        ];
        return Response::page('admin/index', ['viewer' => $v, 'sites' => $sites, 'users' => $users, 'members' => $members, 'count' => $count, 'stats' => $stats, 'wide' => true, 'title' => 'Administration'], 'plain');
    }

    public static function journal(): Response
    {
        $v = Access::requireAdmin();
        $siteId = Req::query('site', 64);
        $events = Repo::events(300, $siteId !== '' ? $siteId : null);
        $names = [];
        foreach ($events as $e) {
            foreach (['actor_id', 'on_behalf_of'] as $k) {
                if ($e[$k] && !isset($names[$e[$k]])) {
                    $u = Repo::user($e[$k]);
                    $names[$e[$k]] = $u ? ($u['name'] ?: $u['email']) : '?';
                }
            }
        }
        $sites = [];
        foreach (Repo::sites() as $s) {
            $sites[$s['id']] = $s;
        }
        return Response::page('admin/journal', ['viewer' => $v, 'events' => $events, 'names' => $names, 'sites' => $sites, 'siteId' => $siteId, 'wide' => true, 'title' => "Journal d'activité"], 'plain');
    }

    public static function startAssist(): Response
    {
        $v = Access::requireAdmin();
        $target = Repo::user(Req::str('userId', 64));
        if (!$target || $target['id'] === $v['user']['id']) {
            return Response::redirect('/admin');
        }
        Access::startAssist($v['user'], $target['id']);
        Access::audit(['user' => $v['user'], 'effective' => $target, 'assist' => 'x'], 'impersonation_started', ['target' => $target['email']]);
        return Response::redirect('/sites?liste=1');
    }

    public static function stopAssist(): Response
    {
        $v = Access::requireViewer();
        $target = $v['effective'];
        if (Access::stopAssist()) {
            Access::audit(['user' => $v['user'], 'effective' => $v['user'], 'assist' => null], 'impersonation_ended', ['target' => $target['email']]);
        }
        return Response::redirect('/admin');
    }

    public static function setStatus(): Response
    {
        $v = Access::requireAdmin();
        $site = Repo::site(Req::str('siteId', 64));
        if (!$site) {
            throw Halt::notFound();
        }
        $status = Req::str('status') === 'suspended' ? 'suspended' : 'active';
        Repo::updateSite($site['id'], ['status' => $status]);
        Access::audit($v, $status === 'suspended' ? 'site_suspended' : 'site_reactivated', ['name' => $site['name']], $site['id']);
        Session::flash($status === 'suspended' ? "« {$site['name']} » est suspendu : ses clients n'y ont plus accès." : "« {$site['name']} » est réactivé.");
        return Response::redirect('/admin');
    }
}
