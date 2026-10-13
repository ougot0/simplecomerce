<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Db;

/**
 * Statistiques de visite sans Google et sans cookie : le petit script collé dans le site envoie la page vue
 * et le site d'où vient le visiteur. Aucune adresse IP ni identifiant n'est gardé, seulement des compteurs par jour.
 * kind : 'view' (page vue, item = chemin), 'visit' (début de visite, item = ''), 'ref' (provenance, item = domaine).
 */
final class Stats
{
    public const KEEP_DAYS = 400;

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    public static function hit(string $siteId, string $kind, string $item): void
    {
        $day = self::today();
        $item = mb_substr($item, 0, 180);
        $n = Db::run('UPDATE sc_hits SET hits = hits + 1 WHERE site_id = ? AND day = ? AND kind = ? AND item = ?', [$siteId, $day, $kind, $item]);
        if ($n === 0) {
            // Au plus 300 pages ou provenances différentes par jour : au-delà, regroupées (contre le remplissage volontaire).
            if ($kind !== 'visit' && (int) Db::value('SELECT COUNT(*) FROM sc_hits WHERE site_id = ? AND day = ? AND kind = ?', [$siteId, $day, $kind]) >= 300) {
                $item = '(autres)';
                if (Db::run('UPDATE sc_hits SET hits = hits + 1 WHERE site_id = ? AND day = ? AND kind = ? AND item = ?', [$siteId, $day, $kind, $item]) > 0) {
                    return;
                }
            }
            try {
                Db::insert('sc_hits', ['site_id' => $siteId, 'day' => $day, 'kind' => $kind, 'item' => $item, 'hits' => 1]);
            } catch (\PDOException) {
                Db::run('UPDATE sc_hits SET hits = hits + 1 WHERE site_id = ? AND day = ? AND kind = ? AND item = ?', [$siteId, $day, $kind, $item]);
            }
        }
    }

    /** Enregistre une page vue envoyée par le site. */
    public static function record(array $site, string $path, string $referrer, bool $newVisit): void
    {
        $path = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: '/'), '/');
        $path = preg_replace('#/index\.html?$#', '/', $path);
        self::hit($site['id'], 'view', $path);
        if ($newVisit) {
            self::hit($site['id'], 'visit', '');
            $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
            $own = strtolower((string) parse_url((string) $site['public_url'], PHP_URL_HOST));
            $host = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $host);
            if ($host !== '' && $host !== preg_replace('/^www\./', '', $own)) {
                self::hit($site['id'], 'ref', self::source($host));
            }
        }
        // Ménage de temps en temps.
        if (random_int(1, 500) === 1) {
            Db::run('DELETE FROM sc_hits WHERE day < ?', [(new \DateTimeImmutable('-' . self::KEEP_DAYS . ' days'))->format('Y-m-d')]);
        }
    }

    /** Nom lisible d'une provenance. */
    public static function source(string $host): string
    {
        $known = ['google.' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'qwant.com' => 'Qwant', 'ecosia.org' => 'Ecosia',
            'facebook.com' => 'Facebook', 'instagram.com' => 'Instagram', 't.co' => 'X (Twitter)', 'twitter.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)',
            'linkedin.com' => 'LinkedIn', 'pinterest.' => 'Pinterest', 'tiktok.com' => 'TikTok', 'youtube.com' => 'YouTube', 'yahoo.' => 'Yahoo'];
        foreach ($known as $k => $name) {
            if (str_starts_with($host, $k) || str_contains($host, '.' . $k) || ($k[strlen($k) - 1] === '.' && str_contains($host, $k))) {
                return $name;
            }
        }
        return $host;
    }

    /** Résumé pour le tableau : par jour, pages, provenances. */
    public static function summary(string $siteId, int $days = 30): array
    {
        $from = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $prevFrom = (new \DateTimeImmutable($from))->modify("-$days days")->format('Y-m-d');
        $perDay = [];
        for ($d = new \DateTimeImmutable($from), $i = 0; $i < $days; $i++, $d = $d->modify('+1 day')) {
            $perDay[$d->format('Y-m-d')] = ['views' => 0, 'visits' => 0];
        }
        foreach (Db::all("SELECT day, kind, SUM(hits) AS n FROM sc_hits WHERE site_id = ? AND day >= ? AND kind IN ('view', 'visit') GROUP BY day, kind", [$siteId, $from]) as $r) {
            if (isset($perDay[$r['day']])) {
                $perDay[$r['day']][$r['kind'] === 'view' ? 'views' : 'visits'] = (int) $r['n'];
            }
        }
        $top = fn (string $kind) => array_map(fn ($r) => ['item' => $r['item'], 'n' => (int) $r['n']],
            Db::all('SELECT item, SUM(hits) AS n FROM sc_hits WHERE site_id = ? AND day >= ? AND kind = ? GROUP BY item ORDER BY n DESC LIMIT 10', [$siteId, $from, $kind]));
        $prev = (int) Db::value("SELECT COALESCE(SUM(hits), 0) FROM sc_hits WHERE site_id = ? AND day >= ? AND day < ? AND kind = 'visit'", [$siteId, $prevFrom, $from]);
        return [
            'perDay' => $perDay,
            'visits' => array_sum(array_column($perDay, 'visits')),
            'views' => array_sum(array_column($perDay, 'views')),
            'previousVisits' => $prev,
            'pages' => $top('view'),
            'sources' => $top('ref'),
            'any' => (bool) Db::value('SELECT 1 FROM sc_hits WHERE site_id = ? LIMIT 1', [$siteId]),
        ];
    }

    /** Petit script de comptage à coller dans le site (ou ajouté automatiquement aux pages). */
    public static function script(string $endpoint): string
    {
        return '(function(){try{var n=!sessionStorage.getItem("sc_v");sessionStorage.setItem("sc_v","1");}catch(e){var n=false;}'
            . 'var b=JSON.stringify({p:location.pathname,r:n?document.referrer:"",n:n});'
            . 'if(navigator.sendBeacon){navigator.sendBeacon(' . json_encode($endpoint) . ',new Blob([b],{type:"text/plain"}));}'
            . 'else{fetch(' . json_encode($endpoint) . ',{method:"POST",body:b,keepalive:true,mode:"no-cors"});}})();';
    }
}
