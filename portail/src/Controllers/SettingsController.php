<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\Catalog;
use SimpleCommerce\Adapters\Registry;
use SimpleCommerce\Config;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Db;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Http\View;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Changes;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Mailer;
use SimpleCommerce\Support\Session;

/** Réglages d'un site (propriétaire ou administrateur). */
final class SettingsController
{
    private const INVITE_DAYS = 14;

    public static function show(array $p, array $state = []): Response
    {
        $ctx = Access::site($p['slug'], 'owner');
        $site = $ctx['site'];
        $closure = false; // false = illisible, null = non pris en charge
        try {
            $closure = Sites::with($site, fn (Adapter $a) => $a->getStatus());
        } catch (\Throwable) {
        }
        $config = $site['config'];
        if ($site['connector'] === 'github' && !empty($config['owner'])) {
            $config['repository'] = "https://github.com/{$config['owner']}/{$config['repo']}";
        }
        if ($site['connector'] === 'bitbucket' && !empty($config['workspace'])) {
            $config['repository'] = "https://bitbucket.org/{$config['workspace']}/{$config['repo']}";
        }
        if ($site['connector'] === 'gitlab' && !empty($config['project'])) {
            $config['repository'] = ($config['baseUrl'] ?? 'https://gitlab.com') . '/' . $config['project'];
        }
        return SiteController::page($ctx, 'site/settings', $state + [
            'members' => Repo::members($site['id']),
            'invitations' => Repo::invitations($site['id']),
            'creds' => Repo::credentials($site['id']),
            'def' => Catalog::get($site['connector']),
            'closure' => $closure,
            'config' => $config,
            'errors' => [],
            'report' => null,
            'inviteLink' => null,
        ], 'Réglages', array_filter($state, fn ($v, $k) => $v && (str_ends_with($k, 'Error') || $k === 'errors'), ARRAY_FILTER_USE_BOTH) ? 422 : 200);
    }

    public static function action(array $p): Response
    {
        $ctx = Access::site($p['slug'], 'owner');
        $site = $ctx['site'];
        $v = $ctx['viewer'];
        $back = "/s/{$site['slug']}/reglages";
        switch ($p['action']) {
            case 'inviter':
                $email = mb_strtolower(Req::str('email', 200));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return self::show($p, ['inviteError' => 'Adresse e-mail invalide.']);
                }
                $role = Req::str('role') === 'owner' ? 'owner' : 'editor';
                $existing = Repo::userByEmail($email);
                if ($existing && Repo::membership($site['id'], $existing['id'])) {
                    return self::show($p, ['inviteError' => 'Cette personne a déjà accès au site.']);
                }
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                Repo::createInvitation(['site_id' => $site['id'], 'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $token), 'invited_by' => $v['user']['id'],
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::INVITE_DAYS * 86400)]);
                $link = Config::url("/invitation/$token");
                $inviter = $v['effective']['name'] ?: $v['effective']['email'];
                $sent = Mailer::send($email, "$inviter vous invite à modifier le site {$site['name']}",
                    "Bonjour,\n\n$inviter vous invite à mettre à jour le site « {$site['name']} » avec Simple Commerce.\n\nPour accepter, ouvrez ce lien (valable " . self::INVITE_DAYS . " jours) :\n$link\n\nSi vous ne vous attendiez pas à cette invitation, ignorez simplement ce message.");
                Access::audit($v, 'member_invited', ['email' => $email, 'role' => $role, 'sent' => $sent], $site['id']);
                return self::show($p, ['inviteInfo' => $sent && !Config::demo() ? "Invitation envoyée à $email." : "Invitation créée. Envoyez ce lien à $email (par e-mail ou SMS) : il est valable " . self::INVITE_DAYS . ' jours et ne sera plus affiché.', 'inviteLink' => $link]);

            case 'retirer-membre':
                $uid = Req::str('userId', 64);
                $members = Repo::members($site['id']);
                $target = current(array_filter($members, fn ($m) => $m['user_id'] === $uid));
                if (!$target) {
                    throw Halt::notFound();
                }
                if ($target['role'] === 'owner' && count(array_filter($members, fn ($m) => $m['role'] === 'owner')) <= 1) {
                    Session::flash('Le site doit garder au moins un propriétaire.', 'error');
                    return Response::redirect($back);
                }
                Repo::removeMember($site['id'], $uid);
                Access::audit($v, 'member_removed', ['email' => $target['user']['email'] ?? '?'], $site['id']);
                Session::flash(($target['user']['name'] ?? 'Cette personne') . " n'a plus accès au site.");
                return Response::redirect($back);

            case 'annuler-invitation':
                $inv = current(array_filter(Repo::invitations($site['id']), fn ($i) => $i['id'] === Req::str('invitationId', 64)));
                if (!$inv) {
                    throw Halt::notFound();
                }
                Repo::deleteInvitation($inv['id']);
                Access::audit($v, 'invitation_revoked', ['email' => $inv['email']], $site['id']);
                Session::flash('Invitation annulée.');
                return Response::redirect($back);

            case 'rubriques':
                $labels = Req::arr('label');
                $visible = Req::arr('visible');
                $schema = $ctx['schema'];
                foreach ($schema['sections'] as &$s) {
                    $l = is_string($labels[$s['key']] ?? null) ? mb_substr(trim($labels[$s['key']]), 0, 80) : '';
                    $s['label'] = $l !== '' ? $l : $s['label'];
                    $s['hidden'] = !isset($visible[$s['key']]);
                }
                unset($s);
                Repo::saveSchema($site['id'], ContentSchema::parse($schema), $v['user']['id']);
                Access::audit($v, 'schema_updated', ['via' => 'rubriques'], $site['id']);
                Session::flash('Rubriques enregistrées.');
                return Response::redirect($back . '#rubriques');

            case 'relire':
                try {
                    $found = Sites::discover($site);
                } catch (\Throwable $e) {
                    Session::flash(AdapterError::userMessageFor($e), 'error');
                    return Response::redirect($back . '#rubriques');
                }
                $old = [];
                foreach ($ctx['schema']['sections'] as $s) {
                    $old[$s['key']] = $s;
                }
                $merged = $found['schema'];
                foreach ($merged['sections'] as &$s) {
                    if (!isset($old[$s['key']])) {
                        continue;
                    }
                    $o = $old[$s['key']];
                    $s['label'] = $o['label'];
                    $s['hidden'] = $o['hidden'] ?? false;
                    if (isset($o['itemLabel'])) {
                        $s['itemLabel'] = $o['itemLabel'];
                    }
                    $oldFields = array_column($o['fields'], null, 'key');
                    foreach ($s['fields'] as &$f) {
                        if (isset($oldFields[$f['key']])) {
                            foreach (['label', 'hidden', 'help'] as $k) {
                                if (isset($oldFields[$f['key']][$k])) {
                                    $f[$k] = $oldFields[$f['key']][$k];
                                }
                            }
                        }
                    }
                    unset($f);
                }
                unset($s);
                $merged = ContentSchema::parse($merged);
                Repo::saveSchema($site['id'], $merged, $v['user']['id']);
                Access::audit($v, 'schema_detected', ['notes' => $found['notes']], $site['id']);
                Session::flash('Contenu relu : ' . \SimpleCommerce\Support\Text::plural(count($merged['sections']), 'rubrique') . '. ' . implode(' ', $found['notes']));
                return Response::redirect($back . '#rubriques');

            case 'schema':
                if ($ctx['role'] !== 'admin') {
                    throw Halt::notFound();
                }
                try {
                    $schema = ContentSchema::parse(json_decode(Req::raw('schema'), true, 64, JSON_THROW_ON_ERROR));
                } catch (\Throwable $e) {
                    return self::show($p, ['schemaError' => 'Schéma invalide : ' . mb_substr($e->getMessage(), 0, 200), 'schemaText' => Req::raw('schema')]);
                }
                Repo::saveSchema($site['id'], $schema, $v['user']['id']);
                Access::audit($v, 'schema_updated', ['via' => 'json'], $site['id']);
                Session::flash('Schéma enregistré (nouvelle version).');
                return Response::redirect($back . '#schema');

            case 'connexion':
                return self::connection($p, $ctx);

            case 'fermeture':
                $close = Req::str('intent') === 'close';
                $message = Req::str('message', 300);
                $reopen = Req::str('reopenOn', 10);
                $reopen = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reopen) ? $reopen : null;
                if ($close && $message === '') {
                    return self::show($p, ['closureError' => 'Écrivez le message que verront vos visiteurs.']);
                }
                if ($close && $reopen && $reopen < date('Y-m-d')) {
                    return self::show($p, ['closureError' => 'La date de réouverture est déjà passée.']);
                }
                $r = Changes::setClosure($v, $site, $close ? ['closed' => true, 'message' => $message, 'reopenOn' => $reopen] : ['closed' => false, 'message' => '', 'reopenOn' => null]);
                if (!$r['ok']) {
                    return self::show($p, ['closureError' => $r['error']]);
                }
                Access::audit($v, $close ? 'site_closed' : 'site_reopened', $close ? ['reopenOn' => $reopen] : [], $site['id']);
                Session::flash(($close ? 'Votre site est fermé temporairement. Vos visiteurs voient votre message. ' : 'Votre site est de nouveau ouvert. ') . Sites::delayText($site));
                return Response::redirect($back . '#fermeture');

            case 'retirer-site':
                if (Req::str('confirm', 200) !== $site['name']) {
                    return self::show($p, ['deleteError' => "Pour confirmer, recopiez exactement : {$site['name']}"]);
                }
                Access::audit($v, 'site_removed', ['name' => $site['name']]);
                Repo::deleteSite($site['id']);
                Session::flash("Le site « {$site['name']} » a été retiré de Simple Commerce. Le site lui-même n'a pas été touché.");
                return Response::redirect('/sites?liste=1');
        }
        throw Halt::notFound();
    }

    private static function connection(array $p, array $ctx): Response
    {
        $site = $ctx['site'];
        $v = $ctx['viewer'];
        $name = Req::str('name', 200);
        $publicUrl = SitesController::checkPublicUrl(Req::str('publicUrl', 500));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Nom invalide.';
        }
        if (!$publicUrl) {
            $errors['publicUrl'] = 'Adresse invalide.';
        }
        $previous = Sites::secrets($site);
        $values = SitesController::connectorValues();
        $parsed = Registry::parse($site['connector'], $values, $previous);
        foreach ($parsed['errors'] as $k => $m) {
            $errors["c_$k"] = $m;
        }
        if ($errors) {
            return Req::wantsJson() ? Response::json(['errors' => $errors], 422) : self::show($p, ['errors' => $errors, 'connError' => 'Certains champs sont à corriger.']);
        }
        $config = $parsed['config'];
        unset($config['hostPreset']);
        // Une empreinte SFTP déjà mémorisée ne se change pas par erreur : seul un champ rempli la remplace.
        if ($site['connector'] === 'sftp' && empty($config['hostFingerprint']) && !empty($site['config']['hostFingerprint'])) {
            $config['hostFingerprint'] = $site['config']['hostFingerprint'];
        }
        $t = Sites::test($site['connector'], $config, $parsed['secrets'], $publicUrl);
        if (Req::str('intent') === 'test') {
            return Req::wantsJson() ? Response::json(['report' => $t['report'], 'html' => View::partial('partials/report', ['report' => $t['report']])]) : self::show($p, ['report' => $t['report']]);
        }
        if ($t['fingerprint'] && $site['connector'] === 'sftp' && empty($config['hostFingerprint'])) {
            $config['hostFingerprint'] = $t['fingerprint'];
        }
        Repo::updateSite($site['id'], ['name' => $name, 'public_url' => $publicUrl, 'config' => $config, 'last_check_at' => Db::now(), 'last_check_ok' => $t['report']['ok']]);
        $changed = array_keys(array_filter($parsed['secrets'], fn ($val, $k) => ($previous[$k] ?? null) !== $val, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            Sites::storeSecrets($site['id'], $parsed['secrets'], $v['user']['id']);
        }
        Access::audit($v, 'connection_updated', ['fields' => $changed, 'ok' => $t['report']['ok']], $site['id']);
        Session::flash($t['report']['ok'] ? 'Enregistré. La connexion fonctionne.' : 'Enregistré, mais la connexion ne fonctionne pas encore.', $t['report']['ok'] ? 'ok' : 'warn');
        return Response::redirect("/s/{$site['slug']}/reglages#connexion");
    }
}
