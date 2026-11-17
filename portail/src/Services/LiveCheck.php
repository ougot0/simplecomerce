<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Adapters\Registry;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Http;
use SimpleCommerce\Support\Log;

/**
 * Le dossier relié est-il bien celui qu'affiche l'adresse du site ? (Sinon le portail modifie une copie et rien ne change
 * en ligne.) La page d'accueil en ligne est comparée à celle du dossier relié ; si elles diffèrent, les dossiers de
 * l'hébergement sont parcourus : si un seul contient la page affichée en ligne, le site y est relié tout seul.
 */
final class LiveCheck
{
    private const HOSTING = ['sftp', 'ftp'];
    private const HOMES = ['index.html', 'index.htm', 'index.php', 'accueil.html', 'home.html'];

    /** @return array{state: string, folder?: string} state : ok | fixed | mismatch | unknown */
    public static function run(array $site): array
    {
        if (!in_array($site['connector'], self::HOSTING, true) || empty($site['public_url'])) {
            return ['state' => 'unknown'];
        }
        try {
            $live = self::fetch((string) $site['public_url']);
            if ($live === null) {
                return ['state' => 'unknown'];
            }
            $mine = Sites::with($site, fn ($a) => $a instanceof FileSiteAdapter ? self::home($a->backend, '') : null);
            if ($mine !== null && self::same($live, $mine)) {
                return ['state' => 'ok'];
            }
            // Les autres dossiers de l'hébergement, vus depuis le dossier principal du compte.
            $config = ['remoteRoot' => '/'] + $site['config'];
            $root = Registry::create($site['connector'], $config, Sites::secrets($site), ['siteId' => $site['id'], 'publicUrl' => $site['public_url'], 'schema' => null]);
            try {
                $backend = $root instanceof FileSiteAdapter ? $root->backend : null;
                $dirs = [];
                foreach ($backend ? $backend->list('', 3) : [] as $f) {
                    if (preg_match('#^((?:[^/]+/){1,2})(index|accueil|home)\.(html?|php)$#i', $f, $m)) {
                        $dirs[rtrim($m[1], '/')] = true;
                    }
                }
                $matches = [];
                foreach (array_slice(array_keys($dirs), 0, 30) as $dir) {
                    $html = self::home($backend, $dir);
                    if ($html !== null && self::same($live, $html)) {
                        $matches[] = $dir;
                    }
                }
            } finally {
                $root->close();
            }
            $current = trim((string) ($site['config']['remoteRoot'] ?? ''), '/');
            $matches = array_values(array_filter($matches, fn ($d) => $d !== $current));
            if (count($matches) === 1) {
                $folder = '/' . $matches[0];
                Repo::updateSite($site['id'], ['config' => ['remoteRoot' => $folder] + $site['config']]);
                Log::info('dossier du site corrigé', ['site' => $site['slug'], 'from' => $current, 'to' => $folder]);
                return ['state' => 'fixed', 'folder' => $folder];
            }
            return ['state' => 'mismatch'];
        } catch (\Throwable $e) {
            Log::warn('vérification du site en ligne impossible', ['site' => $site['slug'], 'err' => $e]);
            return ['state' => 'unknown'];
        }
    }

    /** Page d'accueil d'un dossier (null si aucune). */
    private static function home(object $backend, string $dir): ?string
    {
        foreach (self::HOMES as $name) {
            $raw = $backend->read(($dir !== '' ? "$dir/" : '') . $name);
            if ($raw) {
                return $raw['content'];
            }
        }
        return null;
    }

    /** Page d'accueil en ligne (suit au plus 3 redirections : http → https, avec ou sans www). */
    private static function fetch(string $url): ?string
    {
        for ($i = 0; $i < 4; $i++) {
            $r = Http::request('GET', $url, ['Accept' => 'text/html', 'Cache-Control' => 'no-cache'], null, 15);
            $location = $r['headers']['location'] ?? null;
            if ($r['status'] >= 300 && $r['status'] < 400 && is_string($location) && $location !== '') {
                $url = preg_match('#^https?://#i', $location) ? $location : rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $url), '/') . '/' . ltrim($location, '/');
                continue;
            }
            return $r['status'] === 200 ? $r['body'] : null;
        }
        return null;
    }

    /** Texte visible d'une page, pour comparer deux versions (sans scripts, styles, ni bandeau du portail). */
    public static function text(string $html): string
    {
        $html = (string) preg_replace('#<!-- simplecommerce-fermeture -->.*?<!-- /simplecommerce-fermeture -->#s', '', $html);
        $html = (string) preg_replace('#<\?(php|=).*?(\?>|$)#s', ' ', $html);
        $html = (string) preg_replace('#<(script|style|noscript|template)\b.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text))), 0, 1500);
    }

    public static function same(string $a, string $b): bool
    {
        [$ta, $tb] = [self::text($a), self::text($b)];
        if ($ta === '' || $tb === '') {
            return false;
        }
        similar_text($ta, $tb, $pct);
        return $pct >= 70;
    }
}
