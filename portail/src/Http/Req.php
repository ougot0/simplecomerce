<?php
declare(strict_types=1);

namespace SimpleCommerce\Http;

/** Lecture prudente des paramètres de la requête. */
final class Req
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $p = rawurldecode($p);
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    public static function str(string $key, int $max = 2000): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    public static function raw(string $key): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public static function query(string $key, int $max = 500): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? mb_substr($v, 0, $max) : '';
    }

    public static function bool(string $key): bool
    {
        return in_array($_POST[$key] ?? '', ['1', 'on', 'true'], true);
    }

    public static function arr(string $key): array
    {
        $v = $_POST[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /** Page de retour sûre (chemin local uniquement). */
    public static function safeNext(string $next, string $fallback = '/sites'): string
    {
        return preg_match('#^/(?![/\\\\])[\w\-./?=&%]*$#', $next) ? $next : $fallback;
    }

    /** La page est-elle demandée en https ? (directement, ou derrière le relais de l'hébergeur) */
    /** Adresse du portail telle que le visiteur l'a tapée (https://domaine), pour les moteurs de recherche. */
    public static function origin(): string
    {
        $host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        return $host !== '' ? (self::isHttps() ? 'https://' : 'http://') . $host : rtrim(\SimpleCommerce\Config::url(), '/');
    }

    /**
     * Page ouverte dans l'application iPhone de Simple Commerce ? (elle ajoute « SimpleCommerceApp/x » à l'identité du navigateur.)
     * Sert seulement à adapter l'affichage (Apple interdit de proposer un paiement hors de l'App Store) : jamais à accorder un droit.
     */
    public static function inApp(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'SimpleCommerceApp/');
    }

    public static function isHttps(): bool
    {
        return in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
    }

    /** Adresse pour les limites anti-abus : une adresse IPv6 compte pour tout son bloc /64 (qu'une personne a en entier). */
    public static function ipBucket(): string
    {
        $ip = self::ip();
        $bin = @inet_pton($ip);
        return $bin !== false && strlen($bin) === 16 ? bin2hex(substr($bin, 0, 8)) . '::/64' : $ip;
    }
}
