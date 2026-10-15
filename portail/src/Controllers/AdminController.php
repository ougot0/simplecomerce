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
        return Response::page('admin/index', ['signupOpen' => Repo::meta('signup_open', '0') === '1', 'viewer' => $v, 'sites' => $sites, 'users' => $users, 'members' => $members, 'count' => $count, 'stats' => $stats, 'wide' => true, 'title' => 'Administration'], 'plain');
    }

    /** Sauvegarde complète du portail (base + config.php), à garder en lieu sûr. */
    public static function portalBackup(): Response
    {
        $v = Access::requireAdmin();
        Repo::log($v['user']['id'], null, null, 'portal_backup');
        return Response::file(\SimpleCommerce\Services\Backups::portal(), 'application/zip', 'simplecommerce-sauvegarde-complete-' . date('Y-m-d') . '.zip', 'no-store');
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

    /** Ouvrir ou fermer les inscriptions (fermées : seuls l'administrateur et les personnes invitées). */
    public static function setSignup(): Response
    {
        $v = Access::requireAdmin();
        $open = Req::str('open') === '1';
        Repo::setMeta('signup_open', $open ? '1' : '0');
        Access::audit($v, $open ? 'signup_opened' : 'signup_closed');
        Session::flash($open ? 'Inscriptions ouvertes : tout le monde peut créer un compte et relier son site.' : 'Inscriptions fermées : seuls vous et les personnes invitées peuvent entrer.');
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

    // --- E-mails (Brevo) ---

    public static function emails(array $p = [], array $state = []): Response
    {
        $v = Access::requireAdmin();
        $key = \SimpleCommerce\Support\Mailer::brevoKey();
        return Response::page('admin/emails', $state + ['viewer' => $v, 'settings' => \SimpleCommerce\Support\Mailer::settings(),
            'keyHint' => $key !== '' ? \SimpleCommerce\Support\Vault::fingerprint($key) : '', 'title' => 'E-mails'], 'plain');
    }

    public static function saveEmails(): Response
    {
        $v = Access::requireAdmin();
        if (Req::str('intent') === 'test') {
            $ok = \SimpleCommerce\Support\Mailer::send($v['user']['email'], 'Simple Commerce : e-mail de test',
                "Bonjour,\n\nCet e-mail de test est bien arrivé : les e-mails de Simple Commerce fonctionnent.\n\nSimple Commerce");
            $err = \SimpleCommerce\Support\Mailer::$lastError;
            Session::flash($ok && $err === null ? "E-mail de test envoyé à {$v['user']['email']}. Regardez aussi dans les indésirables."
                : ($err !== null ? 'Brevo a refusé l\'envoi : ' . $err . ($ok ? ' (envoyé quand même par l\'hébergeur)' : '') : 'L\'envoi a échoué.'), $ok && $err === null ? 'ok' : 'error');
            return Response::redirect('/admin/emails');
        }
        $key = trim(Req::raw('brevoKey'));
        $sender = mb_strtolower(Req::str('senderEmail', 200));
        if ($key !== '' && !preg_match('/^xkeysib-[A-Za-z0-9-]{20,}$/', $key)) {
            return self::emails([], ['error' => 'La clé Brevo commence par « xkeysib- » (Brevo → votre nom en haut à droite → SMTP et API → Clés API).']);
        }
        if ($sender !== '' && !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return self::emails([], ['error' => 'Adresse d\'expéditeur invalide.']);
        }
        $patch = ['senderEmail' => $sender, 'senderName' => Req::str('senderName', 80) ?: 'Simple Commerce'];
        if ($key !== '') {
            $patch['brevoKey'] = $key;
        }
        if (Req::str('intent') === 'retirer') {
            $patch = ['brevoKey' => ''];
        }
        \SimpleCommerce\Support\Mailer::saveSettings($patch);
        Repo::log($v['user']['id'], null, null, 'mail_settings');
        Session::flash(Req::str('intent') === 'retirer' ? 'Brevo retiré : les e-mails repartent par l\'hébergeur.' : 'Réglages enregistrés. Envoyez-vous un e-mail de test.');
        return Response::redirect('/admin/emails');
    }

    /** L'administrateur confirme lui-même l'adresse d'un client (e-mail jamais reçu, par exemple). */
    public static function confirmEmail(): Response
    {
        $v = Access::requireAdmin();
        $u = Repo::user(Req::str('userId', 64));
        if (!$u) {
            throw Halt::notFound();
        }
        \SimpleCommerce\Services\Auth::markVerified($u['id']);
        Repo::log($v['user']['id'], null, null, 'email_confirmed_by_admin', ['email' => $u['email']]);
        Session::flash("Adresse de {$u['email']} confirmée.");
        return Response::redirect('/admin');
    }

    // --- Rédaction automatique (Claude) ---

    public static function writer(array $p = [], array $state = []): Response
    {
        $v = Access::requireAdmin();
        $key = \SimpleCommerce\Services\Writer::key();
        return Response::page('admin/writer', $state + ['viewer' => $v, 'keyHint' => $key !== '' ? \SimpleCommerce\Support\Vault::fingerprint($key) : '',
            'title' => 'Rédaction automatique'], 'plain');
    }

    public static function saveWriter(): Response
    {
        $v = Access::requireAdmin();
        if (Req::str('intent') === 'retirer') {
            \SimpleCommerce\Services\Writer::saveKey('');
            Repo::log($v['user']['id'], null, null, 'ai_settings', ['on' => false]);
            Session::flash('Rédaction automatique désactivée : les boutons « Écrire pour moi » disparaissent.');
            return Response::redirect('/admin/redaction');
        }
        if (Req::str('intent') === 'test') {
            try {
                $t = \SimpleCommerce\Services\Writer::ask('Réponds en une phrase courte, en français.', 'Dis bonjour aux commerçants qui utilisent Simple Commerce.', 200);
                Session::flash('Ça marche. Réponse : « ' . mb_substr($t, 0, 200) . ' »');
            } catch (\RuntimeException $e) {
                Session::flash($e->getMessage(), 'error');
            }
            return Response::redirect('/admin/redaction');
        }
        $key = trim(Req::raw('apiKey'));
        if (!preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', $key)) {
            return self::writer([], ['error' => 'La clé commence par « sk-ant- ». Copiez-la depuis platform.claude.com → API keys.']);
        }
        \SimpleCommerce\Services\Writer::saveKey($key);
        Repo::log($v['user']['id'], null, null, 'ai_settings', ['on' => true]);
        Session::flash('Clé enregistrée. Cliquez sur « Tester » pour vérifier.');
        return Response::redirect('/admin/redaction');
    }

    // --- Options du portail (allumer / éteindre des fonctions pour tous) ---

    public static function features(): Response
    {
        $v = Access::requireAdmin();
        return Response::page('admin/features', ['viewer' => $v, 'on' => \SimpleCommerce\Services\Features::all(), 'title' => 'Options du portail'], 'plain');
    }

    public static function saveFeatures(): Response
    {
        $v = Access::requireAdmin();
        $keep = array_keys((array) Req::arr('on'));
        $off = array_values(array_diff(array_keys(\SimpleCommerce\Services\Features::LIST), $keep));
        \SimpleCommerce\Services\Features::save($off);
        Repo::log($v['user']['id'], null, null, 'features', ['off' => $off]);
        Session::flash($off ? 'Options enregistrées. Éteintes pour tous les clients : ' . implode(', ', array_map(fn ($k) => \SimpleCommerce\Services\Features::LIST[$k][0], $off)) . '.' : 'Options enregistrées : tout est allumé.');
        return Response::redirect('/admin/options');
    }
}
