<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Catalog;
use SimpleCommerce\Adapters\Registry;
use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Http\View;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Text;

/** Liste des sites d'une personne et assistant « Relier un site ». */
final class SitesController
{
    public static function index(): Response
    {
        $v = Access::requireViewer();
        $sites = Repo::sitesForUser($v['effective']['id']);
        // Un seul site : on y va directement, c'est le cas de la plupart des clients.
        if (count($sites) === 1 && Req::query('liste') === '' && !Access::isAdmin($v)) {
            return Response::redirect('/s/' . $sites[0]['slug']);
        }
        return Response::page('sites/index', ['viewer' => $v, 'sites' => $sites], 'plain');
    }

    public static function newForm(array $p, array $state = []): Response
    {
        $v = Access::requireViewer();
        $type = $state['connector'] ?? Req::query('type', 40);
        $def = $type !== '' ? Catalog::get($type) : null;
        return Response::page('sites/new', $state + ['viewer' => $v, 'def' => $def, 'values' => [], 'errors' => [], 'report' => null, 'isAdmin' => Access::isAdmin($v)], 'plain', $state ? 422 : 200);
    }

    /** Valeurs du formulaire de connexion (champs préfixés c_). */
    public static function connectorValues(): array
    {
        $out = [];
        foreach ($_POST as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'c_')) {
                $out[substr($k, 2)] = mb_substr($v, 0, 20000);
            }
        }
        return $out;
    }

    public static function checkPublicUrl(string $raw): ?string
    {
        if (Config::demo() && str_starts_with($raw, '/demo-sites/')) {
            return $raw;
        }
        $url = preg_match('#^https?://#i', $raw) ? $raw : "https://$raw";
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !str_contains($parts['host'], '.')) {
            return null;
        }
        return rtrim($url, '/');
    }

    public static function create(array $p): Response
    {
        $v = Access::requireViewer();
        $connector = Req::str('connector', 40);
        $def = Catalog::get($connector);
        if (!$def) {
            return Response::redirect('/sites/nouveau');
        }
        $name = Req::str('name', 200);
        $publicRaw = Req::str('publicUrl', 500);
        $values = self::connectorValues();
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Indiquez le nom de votre site ou de votre commerce.';
        }
        $publicUrl = self::checkPublicUrl($publicRaw);
        if (!$publicUrl) {
            $errors['publicUrl'] = "Indiquez l'adresse de votre site, par exemple https://www.mon-site.fr";
        }
        $parsed = Registry::parse($connector, $values, null);
        foreach ($parsed['errors'] as $k => $m) {
            $errors["c_$k"] = $m;
        }
        $state = ['connector' => $connector, 'values' => ['name' => $name, 'publicUrl' => $publicRaw] + $values, 'errors' => $errors];
        if ($errors) {
            return Req::wantsJson() ? Response::json(['errors' => $errors], 422) : self::newForm($p, $state);
        }
        $config = $parsed['config'];
        $t = Sites::test($connector, $config, $parsed['secrets'], $publicUrl);
        if (Req::str('intent') === 'test') {
            return Req::wantsJson() ? Response::json(['report' => $t['report'], 'html' => View::partial('partials/report', ['report' => $t['report']])]) : self::newForm($p, $state + ['report' => $t['report']]);
        }
        if (!$t['report']['ok'] && !(Req::bool('force') && Access::isAdmin($v))) {
            return self::newForm($p, $state + ['report' => $t['report'], 'error' => 'La connexion ne fonctionne pas encore. Corrigez les points signalés, puis réessayez.']);
        }
        if ($t['fingerprint'] && $connector === 'sftp' && empty($config['hostFingerprint'])) {
            $config['hostFingerprint'] = $t['fingerprint'];
        }
        unset($config['hostPreset']);

        $base = substr(Text::slugify($name), 0, 50) ?: 'site';
        $slug = $base;
        for ($n = 2; Repo::slugExists($slug); $n++) {
            $slug = "$base-$n";
        }
        $site = Repo::createSite(['slug' => $slug, 'name' => $name, 'public_url' => $publicUrl, 'connector' => $connector, 'config' => $config], $v['effective']['id']);
        Sites::storeSecrets($site['id'], $parsed['secrets'], $v['user']['id']);
        Repo::updateSite($site['id'], ['last_check_at' => Db::now(), 'last_check_ok' => $t['report']['ok']]);
        Access::audit($v, 'site_created', ['name' => $name, 'connector' => $connector], $site['id']);
        try {
            $found = Sites::discover($site);
            Repo::saveSchema($site['id'], $found['schema'], $v['user']['id']);
            $notes = $found['notes'];
        } catch (\Throwable $e) {
            Log::warn('détection du contenu impossible', ['site' => $slug, 'err' => $e]);
            $notes = ["Le contenu n'a pas pu être lu automatiquement. Votre administrateur peut définir les rubriques à la main."];
        }
        Access::audit($v, 'schema_detected', ['notes' => $notes], $site['id']);
        return Response::redirect("/s/$slug?bienvenue=1");
    }
}
