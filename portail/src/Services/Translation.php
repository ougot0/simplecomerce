<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Content\HtmlTranslate;
use SimpleCommerce\Repo;

/**
 * Version anglaise d'un site fait de pages HTML : chaque page « x.html » reçoit une copie traduite « x-en.html »
 * dans le même dossier (photos et styles restent valables), et un petit bouton « English / Français ».
 * La traduction se fait par petits paquets (une demande à Claude par paquet, pour ne jamais dépasser le temps
 * permis par l'hébergeur) et chaque texte traduit est gardé : une mise à jour ne retraduit que ce qui a changé.
 */
final class Translation
{
    public const FILE_CONNECTORS = ['sftp', 'ftp', 'github', 'gitlab', 'bitbucket', 'netlify', 'demo'];
    private const CHUNK_CHARS = 5000;
    private const CHUNK_ITEMS = 80;
    private const MAX_PAGES = 40;

    public static function supported(array $site): bool
    {
        return in_array($site['connector'], self::FILE_CONNECTORS, true);
    }

    public static function state(array $site): array
    {
        return (array) (Sites::extras($site)['english'] ?? []) + ['on' => false, 'pages' => 0, 'at' => null];
    }

    private static function dir(): string
    {
        $d = SC_STORAGE . '/traductions';
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        return $d;
    }

    private static function file(string $siteId, string $kind): string
    {
        return self::dir() . '/' . preg_replace('/[^a-f0-9-]/', '', $siteId) . "-$kind.json";
    }

    /** @return array<string, string> texte français → anglais */
    private static function memory(string $siteId): array
    {
        $m = json_decode((string) @file_get_contents(self::file($siteId, 'memoire')), true);
        return is_array($m) ? $m : [];
    }

    private static function save(string $siteId, string $kind, array $data): void
    {
        $f = self::file($siteId, $kind);
        file_put_contents("$f.tmp", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename("$f.tmp", $f);
    }

    /** @return array<string, array{content: string, version: string, clean: string}> pages françaises */
    private static function pages(FileSiteAdapter $a): array
    {
        $out = [];
        foreach (array_slice($a->pagePaths(), 0, self::MAX_PAGES) as $p) {
            $raw = $a->readFile($p);
            if ($raw && !HtmlTranslate::isOurs($raw['content'])) {
                $out[$p] = $raw + ['clean' => HtmlTranslate::markFrench($raw['content'], null)];
            }
        }
        return $out;
    }

    private static function adapter(Adapter $a): FileSiteAdapter
    {
        if (!$a instanceof FileSiteAdapter) {
            throw new \RuntimeException('Ce site ne peut pas recevoir de version anglaise automatique.');
        }
        return $a;
    }

    /** Relève les textes du site et prépare les paquets à traduire. @return array{pages: int, todo: int, steps: int} */
    public static function prepare(array $site): array
    {
        $pages = Sites::with($site, fn (Adapter $a) => self::pages(self::adapter($a)), null, true);
        if (!$pages) {
            throw new \RuntimeException('Aucune page HTML n\'a été trouvée sur votre site.');
        }
        $memory = self::memory($site['id']);
        $todo = [];
        foreach ($pages as $raw) {
            foreach (HtmlTranslate::segments($raw['clean']) as $t) {
                if (!isset($memory[$t])) {
                    $todo[$t] = true;
                }
            }
        }
        $todo = array_keys($todo);
        self::save($site['id'], 'travail', ['todo' => $todo]);
        return ['pages' => count($pages), 'todo' => count($todo), 'steps' => count(self::chunks($todo))];
    }

    /** @param string[] $texts @return array<int, string[]> */
    private static function chunks(array $texts): array
    {
        $out = [];
        $cur = [];
        $len = 0;
        foreach ($texts as $t) {
            if ($cur && ($len + mb_strlen($t) > self::CHUNK_CHARS || count($cur) >= self::CHUNK_ITEMS)) {
                $out[] = $cur;
                $cur = [];
                $len = 0;
            }
            $cur[] = $t;
            $len += mb_strlen($t);
        }
        if ($cur) {
            $out[] = $cur;
        }
        return $out;
    }

    /** Traduit le paquet suivant. @return array{todo: int, steps: int} ce qui reste */
    public static function step(array $site): array
    {
        $job = json_decode((string) @file_get_contents(self::file($site['id'], 'travail')), true);
        $todo = is_array($job['todo'] ?? null) ? $job['todo'] : [];
        $chunks = self::chunks($todo);
        if ($chunks) {
            $batch = $chunks[0];
            $done = Writer::translate($batch, (string) $site['name']);
            $memory = self::memory($site['id']);
            foreach ($batch as $i => $fr) {
                $memory[$fr] = $done[$i];
            }
            self::save($site['id'], 'memoire', $memory);
            $todo = array_slice($todo, count($batch));
            self::save($site['id'], 'travail', ['todo' => $todo]);
        }
        return ['todo' => count($todo), 'steps' => count(self::chunks($todo))];
    }

    /** Écrit les pages anglaises et les boutons de langue. @return array{pages: int, skipped: string[]} */
    public static function publish(array $site): array
    {
        $memory = self::memory($site['id']);
        $r = Sites::with($site, function (Adapter $a) use ($memory) {
            $a = self::adapter($a);
            $pages = self::pages($a);
            $writes = [];
            $expected = [];
            $skipped = [];
            foreach ($pages as $path => $raw) {
                $en = HtmlTranslate::enPath($path);
                $old = $a->readFile($en);
                if ($old && !HtmlTranslate::isOurs($old['content'])) {
                    $skipped[] = $path; // une page « -en » existe déjà, écrite par quelqu'un d'autre : on n'y touche pas
                    continue;
                }
                $html = HtmlTranslate::render($raw['clean'], $memory, fn (string $href) => self::link($path, $href, array_keys($pages)), basename($path));
                if (!$old || $old['content'] !== $html) {
                    $writes[] = ['path' => $en, 'content' => $html];
                    $expected[$en] = $old['version'] ?? null;
                }
                $fr = HtmlTranslate::markFrench($raw['content'], basename($en));
                if ($fr !== $raw['content']) {
                    $writes[] = ['path' => $path, 'content' => $fr];
                    $expected[$path] = $raw['version'];
                }
            }
            $a->writeFiles($writes, $expected, 'Version anglaise du site');
            return ['pages' => count($pages) - count($skipped), 'skipped' => $skipped];
        }, null, true);
        Sites::setExtras($site, ['english' => ['on' => true, 'pages' => $r['pages'], 'at' => gmdate('Y-m-d\TH:i:s\Z')]]);
        @unlink(self::file($site['id'], 'travail'));
        return $r;
    }

    /** Retire les pages anglaises fabriquées par le portail et les boutons de langue. @return int pages retirées */
    public static function remove(array $site): int
    {
        $n = Sites::with($site, function (Adapter $a) {
            $a = self::adapter($a);
            $writes = [];
            $expected = [];
            $removed = 0;
            foreach ($a->pagePaths() as $path) {
                $raw = $a->readFile($path);
                if (!$raw) {
                    continue;
                }
                $fr = HtmlTranslate::markFrench($raw['content'], null);
                if ($fr !== $raw['content']) {
                    $writes[] = ['path' => $path, 'content' => $fr];
                    $expected[$path] = $raw['version'];
                }
                $en = HtmlTranslate::enPath($path);
                $old = $a->readFile($en);
                if ($old && HtmlTranslate::isOurs($old['content'])) {
                    $writes[] = ['path' => $en, 'content' => null];
                    $expected[$en] = $old['version'];
                    $removed++;
                }
            }
            $a->writeFiles($writes, $expected, 'Retrait de la version anglaise');
            return $removed;
        }, null, true);
        Sites::setExtras($site, ['english' => ['on' => false, 'pages' => 0, 'at' => null]]);
        return $n;
    }

    /** Lien d'une page traduite : vers la version anglaise si la page visée en a une. */
    private static function link(string $from, string $href, array $pages): ?string
    {
        if ($href === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|\#|\?)#i', $href) || !preg_match('/^([^?#]*)(.*)$/s', $href, $m)) {
            return null;
        }
        [$path, $rest] = [$m[1], $m[2]];
        $dir = $path !== '' && $path[0] === '/' ? [] : array_values(array_filter(explode('/', dirname($from)), fn ($s) => $s !== '.' && $s !== ''));
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($dir);
            } elseif ($seg !== '' && $seg !== '.') {
                $dir[] = $seg;
            }
        }
        $folder = $path === '' || str_ends_with($path, '/') || $path === '.' || str_ends_with($path, '/.');
        $target = implode('/', $dir) . ($folder ? ($dir ? '/' : '') . 'index.html' : '');
        foreach ($pages as $p) {
            if ($p === $target || ($path !== '' && $path[0] === '/' && str_ends_with($p, '/' . $target))) {
                return ($folder ? rtrim($path, '.') . 'index-en.html' : HtmlTranslate::enPath($path)) . $rest;
            }
        }
        return null;
    }
}
