<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;

/**
 * Sauvegardes des sites, gardées sur le portail (storage/backups/<site>/AAAAMMJJ-HHMMSS.zip).
 * - Sites reliés par leurs fichiers : copie des fichiers de texte (pages, données, styles), sans les photos
 *   ni les fichiers PHP (qui peuvent contenir des mots de passe). Remise en place possible en un clic.
 * - Autres sites (Shopify, WordPress…) : copie du contenu en JSON, à télécharger.
 * Une sauvegarde automatique par nuit et par site ; on garde les 14 dernières (plus celles faites à la main).
 */
final class Backups
{
    private const KEEP_AUTO = 14;
    private const KEEP_TOTAL = 30;
    private const TEXT = '/\.(html?|css|js|json|ya?ml|md|txt|xml|svg)$/i';
    private const SKIP = '#(^|/)(node_modules|vendor|\.git|\.well-known)(/|$)#i';
    private const MAX_FILES = 400;
    private const MAX_FILE = 2_000_000;
    private const MAX_TOTAL = 25_000_000;

    public static function dir(string $siteId): string
    {
        return SC_STORAGE . '/backups/' . preg_replace('/[^a-f0-9-]/', '', $siteId);
    }

    /** @return array<int, array{name: string, date: string, bytes: int, kind: string, files: int, mode: string}> du plus récent au plus ancien */
    public static function list(string $siteId): array
    {
        $out = [];
        foreach (glob(self::dir($siteId) . '/*.zip') ?: [] as $f) {
            $name = basename($f);
            if (!preg_match('/^(\d{8})-(\d{6})-(auto|main|avant)\.zip$/', $name, $m)) {
                continue;
            }
            $meta = self::manifest($f);
            $out[] = ['name' => $name, 'date' => \DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2], new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'bytes' => (int) filesize($f), 'kind' => $m[3], 'files' => count($meta['files'] ?? []), 'mode' => (string) ($meta['mode'] ?? 'fichiers')];
        }
        usort($out, fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function path(string $siteId, string $name): ?string
    {
        if (!preg_match('/^\d{8}-\d{6}-(auto|main|avant)\.zip$/', $name)) {
            return null;
        }
        $f = self::dir($siteId) . '/' . $name;
        return is_file($f) ? $f : null;
    }

    private static function manifest(string $zipFile): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [];
        }
        $m = json_decode((string) $zip->getFromName('simplecommerce-sauvegarde.json'), true);
        $zip->close();
        return is_array($m) ? $m : [];
    }

    /** Fait une sauvegarde du site. @return array{name: string, files: int} */
    public static function make(array $site, string $kind = 'main'): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException("L'extension zip de PHP manque sur l'hébergement.");
        }
        $schema = Repo::schema($site['id']) ?? ['sections' => []];
        $entries = Sites::with($site, function (Adapter $a) use ($schema) {
            if ($a instanceof FileSiteAdapter) {
                $files = [];
                $total = 0;
                foreach ($a->backend->list('', 4) as $p) {
                    if (count($files) >= self::MAX_FILES || !preg_match(self::TEXT, $p) || preg_match(self::SKIP, $p)) {
                        continue;
                    }
                    $raw = $a->backend->read($p);
                    if (!$raw || strlen($raw['content']) > self::MAX_FILE || $total + strlen($raw['content']) > self::MAX_TOTAL) {
                        continue;
                    }
                    $total += strlen($raw['content']);
                    $files[$p] = $raw['content'];
                }
                return ['mode' => 'fichiers', 'files' => $files];
            }
            $out = [];
            foreach ($schema['sections'] as $s) {
                $out[$s['label']] = ($s['kind'] ?? '') === 'singleton' ? $a->getSingleton($s) : array_map(fn ($e) => $e['data'], $a->listEntries($s));
            }
            return ['mode' => 'contenu', 'files' => ['contenu.json' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
        }, null, true);
        $dir = self::dir($site['id']);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier des sauvegardes impossible à créer.');
        }
        $name = gmdate('Ymd-His') . "-$kind.zip";
        $zip = new \ZipArchive();
        if ($zip->open("$dir/$name", \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Sauvegarde impossible à écrire.');
        }
        foreach ($entries['files'] as $p => $content) {
            $zip->addFromString($p, $content);
        }
        $zip->addFromString('simplecommerce-sauvegarde.json', json_encode([
            'site' => $site['name'], 'adresse' => $site['public_url'], 'date' => Db::now(), 'mode' => $entries['mode'],
            'files' => array_map('sha1', $entries['files']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();
        self::prune($site['id']);
        Sites::setExtras($site, ['lastBackup' => Db::now()]);
        return ['name' => $name, 'files' => count($entries['files'])];
    }

    private static function prune(string $siteId): void
    {
        $all = self::list($siteId);
        $auto = array_values(array_filter($all, fn ($b) => $b['kind'] === 'auto'));
        foreach (array_slice($auto, self::KEEP_AUTO) as $b) {
            @unlink(self::dir($siteId) . '/' . $b['name']);
        }
        foreach (array_slice(self::list($siteId), self::KEEP_TOTAL) as $b) {
            @unlink(self::dir($siteId) . '/' . $b['name']);
        }
    }

    /**
     * Remet une sauvegarde en place : seuls les fichiers qui ont changé depuis sont réécrits, en une seule fois.
     * Juste avant, l'état actuel est lui-même sauvegardé (« avant »), pour pouvoir revenir en arrière.
     * @return int nombre de fichiers remis
     */
    public static function restore(array $site, string $name): int
    {
        $file = self::path($site['id'], $name);
        $meta = $file ? self::manifest($file) : [];
        if (!$file || ($meta['mode'] ?? '') !== 'fichiers') {
            throw new \RuntimeException('Cette sauvegarde ne peut pas être remise automatiquement : téléchargez-la.');
        }
        self::make($site, 'avant');
        $zip = new \ZipArchive();
        $zip->open($file);
        $wanted = [];
        foreach (array_keys((array) $meta['files']) as $p) {
            $c = $zip->getFromName($p);
            if ($c !== false) {
                $wanted[$p] = $c;
            }
        }
        $zip->close();
        return Sites::with($site, function (Adapter $a) use ($wanted) {
            if (!$a instanceof FileSiteAdapter) {
                throw new \RuntimeException('Ce site ne peut pas être remis automatiquement.');
            }
            $writes = [];
            $expected = [];
            foreach ($wanted as $p => $content) {
                $cur = $a->backend->read($p);
                if ($cur && $cur['content'] === $content) {
                    continue;
                }
                $writes[] = ['path' => $p, 'content' => $content];
                $expected[$p] = $cur['version'] ?? null;
            }
            if ($writes) {
                $a->backend->commit($writes, 'Remise d\'une sauvegarde via Simple Commerce', $expected);
            }
            return count($writes);
        });
    }

    /** Sauvegardes de la nuit : un site après l'autre, au plus $max par passage (pour ne pas ralentir). */
    public static function nightly(int $max = 3): int
    {
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('G');
        if ($hour < 1 || $hour > 6) {
            return 0;
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $done = 0;
        foreach (Repo::sites() as $site) {
            if ($done >= $max || $site['status'] !== 'active' || $site['connector'] === 'demo') {
                continue;
            }
            $last = (string) (Sites::extras($site)['lastBackup'] ?? '');
            $lastDay = $last !== '' ? (new \DateTimeImmutable($last))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d') : '';
            $tried = (string) (Sites::extras($site)['backupTried'] ?? '');
            if ($lastDay === $today || $tried === $today) {
                continue;
            }
            Sites::setExtras($site, ['backupTried' => $today]);
            try {
                self::make(Repo::site($site['id']) ?? $site, 'auto');
                $done++;
            } catch (\Throwable $e) {
                Log::warn('sauvegarde automatique impossible', ['site' => $site['slug'], 'err' => $e]);
            }
        }
        return $done;
    }

    /** Sauvegarde complète du portail (administrateur) : base de données + config.php, dans un zip. */
    public static function portal(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'scb');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if (Db::driver() === 'sqlite') {
            $db = SC_STORAGE . '/simplecommerce.sqlite';
            $copy = $tmp . '.sqlite';
            // Copie cohérente de la base, même si quelqu'un l'utilise en même temps.
            Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($copy));
            $zip->addFile($copy, 'storage/simplecommerce.sqlite');
        } else {
            $tables = [];
            foreach (Db::all("SHOW TABLES LIKE 'sc\\_%'") as $row) {
                $t = (string) array_values($row)[0];
                $tables[$t] = Db::all("SELECT * FROM `$t`");
            }
            $zip->addFromString('base-de-donnees.json', json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if (is_file(\SimpleCommerce\Config::path())) {
            $zip->addFile(\SimpleCommerce\Config::path(), 'config.php');
        }
        $zip->addFromString('LISEZ-MOI.txt', "Sauvegarde complète de Simple Commerce du " . date('d/m/Y H:i') . ".\n\n"
            . "Pour tout remettre : mettez config.php et le dossier storage/ dans le dossier « simplecommerce-donnees », à côté du dossier du portail.\n"
            . "ATTENTION : config.php contient la clé qui protège les accès des sites. Gardez ce fichier en lieu sûr, ne l'envoyez à personne.\n");
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        @unlink($tmp . '.sqlite');
        return $bytes;
    }
}
