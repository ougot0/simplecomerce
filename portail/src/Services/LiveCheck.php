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

    /** @return array{state: string, folder?: string, candidates?: string[]} state : ok | fixed | mismatch | unknown */
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
                $scores = [];
                $choices = [];
                foreach (array_slice(array_keys($dirs), 0, 30) as $dir) {
                    $html = self::home($backend, $dir);
                    if ($html === null) {
                        continue;
                    }
                    $sc = self::score($live, $html);
                    // Chaque site de l'hébergement, avec le nom de sa page d'accueil : le commerçant peut choisir le bon.
                    $choices[] = ['dir' => '/' . $dir, 'title' => self::title($html, $dir), 'score' => (int) round($sc)];
                    if ($sc >= 70) {
                        $matches[] = $dir;
                        $scores[$dir] = $sc;
                    }
                }
                usort($choices, fn ($a, $b) => $b['score'] <=> $a['score']);
                // Plusieurs copies du site : on garde la plus fidèle à la page en ligne, si elle se distingue nettement.
                if (count($matches) > 1) {
                    arsort($scores);
                    $best = array_slice($scores, 0, 2, true);
                    $vals = array_values($best);
                    if ($vals[0] - $vals[1] >= 5) {
                        $matches = [array_key_first($best)];
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
            return ['state' => 'mismatch', 'candidates' => array_map(fn ($d) => '/' . $d, $matches), 'choices' => array_slice($choices, 0, 12)];
        } catch (\Throwable $e) {
            Log::warn('vérification du site en ligne impossible', ['site' => $site['slug'], 'err' => $e]);
            return ['state' => 'unknown'];
        }
    }

    /** Nom d'un site d'après sa page d'accueil (balise <title>, sinon premier grand titre, sinon le dossier). */
    private static function title(string $html, string $dir): string
    {
        foreach (['#<title[^>]*>(.*?)</title>#is', '#<h1[^>]*>(.*?)</h1>#is'] as $re) {
            if (preg_match($re, $html, $m) && ($t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== '') {
                return mb_strimwidth((string) preg_replace('/\s+/u', ' ', $t), 0, 70, '…');
            }
        }
        return $dir;
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
        return self::score($a, $b) >= 70;
    }

    /** Ressemblance du texte visible de deux pages, de 0 à 100. */
    public static function score(string $a, string $b): float
    {
        [$ta, $tb] = [self::text($a), self::text($b)];
        if ($ta === '' || $tb === '') {
            return 0.0;
        }
        similar_text($ta, $tb, $pct);
        return $pct;
    }
}
