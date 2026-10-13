<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Files\DbBackend;
use SimpleCommerce\Adapters\Status;
use SimpleCommerce\Config;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;

/**
 * Pages publiques des catalogues hébergés : la ligne collée dans le site du client charge
 * /catalogue/{clé}.js, qui affiche les produits. Lecture seule, sans session ni cookie.
 */
final class CatalogueController
{
    private static function site(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
            return null;
        }
        foreach (Repo::sites() as $s) {
            if ($s['connector'] === 'catalogue' && ($s['config']['publicKey'] ?? '') === $key && $s['status'] === 'active') {
                return $s;
            }
        }
        return null;
    }

    /** Produits visibles, prêts à afficher. */
    public static function data(array $site): array
    {
        $backend = new DbBackend($site['id']);
        $content = json_decode($backend->read(DbBackend::CONTENT)['content'] ?? '{}', true) ?: [];
        $status = $backend->read(\SimpleCommerce\Adapters\Files\FileSiteAdapter::STATUS_FILE);
        $closed = $status ? Status::fromFile(json_decode($status['content'], true)) : Status::OPEN;
        $products = [];
        foreach ((array) ($content['produits'] ?? []) as $p) {
            if (!is_array($p) || (array_key_exists('disponible', $p) && $p['disponible'] === false)) {
                continue;
            }
            $products[] = [
                'nom' => (string) ($p['nom'] ?? ''),
                'photos' => array_values(array_filter((array) ($p['photos'] ?? []), 'is_string')),
                'prix' => is_numeric($p['prix'] ?? null) ? (float) $p['prix'] : null,
                'ancienPrix' => is_numeric($p['ancienPrix'] ?? null) ? (float) $p['ancienPrix'] : null,
                'description' => (string) ($p['description'] ?? ''),
                'epuise' => isset($p['stock']) && is_numeric($p['stock']) && (int) $p['stock'] <= 0,
                'pastille' => mb_substr(trim((string) ($p['pastille'] ?? '')), 0, 24),
                'enAvant' => ($p['enAvant'] ?? false) === true,
                'lien' => is_string($p['lien'] ?? null) && preg_match('#^https?://#i', $p['lien']) ? $p['lien'] : '',
            ];
        }
        // Produits mis en avant d'abord, sans changer l'ordre des autres.
        $featured = array_values(array_filter($products, fn ($p) => $p['enAvant']));
        $products = array_merge($featured, array_values(array_filter($products, fn ($p) => !$p['enAvant'])));
        return ['produits' => $products, 'ferme' => !empty($closed['closed']), 'message' => (string) ($closed['message'] ?? '')];
    }

    public static function script(array $p): Response
    {
        $site = self::site($p['key']);
        $data = $site ? self::data($site) : ['produits' => [], 'ferme' => false, 'message' => ''];
        $js = '(function(){var D=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
            . file_get_contents(SC_ROOT . '/public/assets/catalogue-widget.js') . '})();';
        return new Response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=60', 'Access-Control-Allow-Origin' => '*']);
    }

    public static function json(array $p): Response
    {
        $site = self::site($p['key']);
        return new Response(json_encode($site ? self::data($site) : null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $site ? 200 : 404,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'public, max-age=60', 'Access-Control-Allow-Origin' => '*']);
    }

    public static function photo(array $p): Response
    {
        $site = self::site($p['key']);
        $raw = $site ? (new DbBackend($site['id']))->read(DbBackend::PHOTOS . '/' . $p['file']) : null;
        if (!$raw) {
            return new Response('', 404, ['Content-Type' => 'text/plain']);
        }
        $mime = match (strtolower(pathinfo($p['file'], PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => 'image/webp',
        };
        return new Response($raw['content'], 200, ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=86400', 'Access-Control-Allow-Origin' => '*']);
    }

    /** Horaires d'ouverture, pour tout site (ligne à coller donnée dans Réglages → Horaires). */
    public static function hours(array $p): Response
    {
        $site = \SimpleCommerce\Services\Sites::byPublicKey($p['key']);
        $js = $site ? \SimpleCommerce\Services\Hours::script($site) : '';
        return new Response($js, $site ? 200 : 404, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
    }

    /** La ligne à coller dans le site. */
    public static function embedLine(array $site): string
    {
        return '<script src="' . Config::url('/catalogue/' . ($site['config']['publicKey'] ?? '') . '.js') . '"></script>';
    }
}
