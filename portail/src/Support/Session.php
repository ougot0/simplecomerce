<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

use SimpleCommerce\Config;

/** Session PHP avec des cookies sûrs, jeton anti-falsification et messages d'une page à l'autre. */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = str_starts_with(Config::url(), 'https://');
        session_name('sc_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200');
        if (is_dir(SC_STORAGE . '/sessions') || @mkdir(SC_STORAGE . '/sessions', 0750, true)) {
            session_save_path(SC_STORAGE . '/sessions');
        }
        session_start();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function get(string $k, mixed $default = null): mixed
    {
        return $_SESSION[$k] ?? $default;
    }

    public static function set(string $k, mixed $v): void
    {
        $_SESSION[$k] = $v;
    }

    public static function forget(string $k): void
    {
        unset($_SESSION[$k]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('sc_session', '', ['expires' => 1, 'path' => '/']);
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    /** Message affiché une fois sur la page suivante. */
    public static function flash(string $text, string $kind = 'ok'): void
    {
        $_SESSION['flash'][] = ['text' => $text, 'kind' => $kind];
    }

    /** @return array<int, array{text: string, kind: string}> */
    public static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    /** Valeurs d'un formulaire à réafficher après une erreur. */
    public static function old(?array $values = null): array
    {
        if ($values !== null) {
            $_SESSION['old'] = $values;
            return $values;
        }
        $v = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        return $v;
    }
}
