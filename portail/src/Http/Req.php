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

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
    }
}
