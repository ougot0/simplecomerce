<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Registry;
use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Schema;
use SimpleCommerce\Support\Log;

/**
 * Données de la démonstration : un administrateur, deux clients et deux sites construits différemment
 * (un fichier content.json comme chez un hébergeur, un site Astro en Markdown/YAML).
 * Supprimer le dossier storage/ suffit à repartir de zéro.
 */
final class Demo
{
    public const ACCOUNTS = [
        ['email' => 'alex@simplecommerce.demo', 'password' => 'atelier-demo-2026', 'name' => 'Alex (administrateur)', 'admin' => true],
        ['email' => 'marie@patisserie-lune.fr', 'password' => 'tarte-citron-2026', 'name' => 'Marie Lefort', 'admin' => false],
        ['email' => 'paul@atelier-brun.fr', 'password' => 'maison-bois-2026', 'name' => 'Paul Brun', 'admin' => false],
    ];

    public static function ensure(): void
    {
        if (!Config::demo()) {
            return;
        }
        if (!Schema::isInstalled()) {
            Schema::install();
        }
        if (Db::value('SELECT COUNT(*) FROM sc_sites') > 0) {
            return;
        }
        try {
            self::copyDir(SC_ROOT . '/demo/sites', Registry::demoRoot());
            $ids = [];
            foreach (self::ACCOUNTS as $a) {
                $ids[] = (Repo::userByEmail($a['email']) ?? Repo::createUser($a['email'], $a['name'], $a['password'], $a['admin']))['id'];
            }
            [, $marie, $paul] = $ids;
            $sites = [
                [Repo::createSite(['slug' => 'patisserie-lune', 'name' => 'Pâtisserie Lune', 'public_url' => '/demo-sites/patisserie-lune', 'connector' => 'demo',
                    'config' => ['folder' => 'patisserie-lune', 'contentDir' => 'auto', 'mediaDir' => 'images/simplecommerce', 'mediaPublicPrefix' => '/images/simplecommerce']], $marie), $marie],
                [Repo::createSite(['slug' => 'atelier-brun', 'name' => 'Atelier Brun architectes', 'public_url' => '/demo-sites/atelier-brun', 'connector' => 'demo',
                    'config' => ['folder' => 'atelier-brun', 'contentDir' => 'auto', 'mediaDir' => 'auto']], $paul), $paul],
            ];
            foreach ($sites as [$site, $owner]) {
                Sites::storeSecrets($site['id'], [], $owner);
                Repo::saveSchema($site['id'], Sites::discover($site)['schema'], $owner);
                Repo::updateSite($site['id'], ['last_check_at' => Db::now(), 'last_check_ok' => true]);
                Repo::log($owner, null, $site['id'], 'site_created', ['name' => $site['name']]);
            }
        } catch (\Throwable $e) {
            Log::error('initialisation de la démo impossible', ['err' => $e]);
            throw $e;
        }
    }

    private static function copyDir(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0750, true);
        }
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? self::copyDir("$from/$f", "$to/$f") : (is_file("$to/$f") || copy("$from/$f", "$to/$f"));
        }
    }
}
