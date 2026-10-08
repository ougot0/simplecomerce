<?php
declare(strict_types=1);

namespace SimpleCommerce;

/**
 * Réglages du portail, écrits par la page d'installation dans config.php (hors du dossier public).
 * Mode démonstration : variable d'environnement SC_DEMO=1, ou 'demo' => true dans config.php.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];
    private static bool $installed = false;

    public static function path(): string
    {
        return SC_ROOT . '/config.php';
    }

    public static function load(): void
    {
        $file = self::path();
        if (is_file($file)) {
            $values = require $file;
            self::$values = is_array($values) ? $values : [];
            self::$installed = true;
        }
        if (getenv('SC_DEMO') === '1') {
            self::$values['demo'] = true;
            self::$installed = true;
            self::$values += [
                'db' => ['driver' => 'sqlite', 'path' => SC_STORAGE . '/demo.sqlite'],
                'key' => base64_encode(str_repeat("\x07", 32)),
                'secret' => 'demo-only-secret-not-for-production-use-0000',
                'url' => getenv('SC_URL') ?: 'http://localhost:8080',
                'mail_from' => 'portail@simplecommerce.demo',
                'debug' => true,
            ];
        }
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    public static function demo(): bool
    {
        return (bool) (self::$values['demo'] ?? false);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function url(string $path = ''): string
    {
        return rtrim((string) self::get('url', ''), '/') . $path;
    }

    /** Écrit config.php (utilisé une seule fois, par l'installation). */
    public static function write(array $values): void
    {
        $php = "<?php\n// Réglages de Simple Commerce — généré par l'installation. Ne pas publier ce fichier.\nreturn " . var_export($values, true) . ";\n";
        if (file_put_contents(self::path(), $php, LOCK_EX) === false) {
            throw new \RuntimeException("Impossible d'écrire config.php : vérifiez les droits du dossier.");
        }
        @chmod(self::path(), 0640);
        self::$values = $values;
        self::$installed = true;
    }
}
