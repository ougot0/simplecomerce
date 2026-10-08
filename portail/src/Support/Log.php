<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

/** Journal technique (storage/app.log), avec masquage systématique des secrets. */
final class Log
{
    private const SECRET_KEY = '/token|password|passwd|secret|authorization|private.?key|passphrase|api.?key|consumer.?key|cookie/i';
    private const SECRET_VALUE = '/\b(ghp_|github_pat_|gho_|glpat-|shpat_|shpca_|shppa_|sk_live_|sk_test_)[A-Za-z0-9_\-]+/';

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[…]';
        }
        if (is_string($value)) {
            return preg_replace_callback(self::SECRET_VALUE, fn ($m) => substr($m[0], 0, 4) . '…[masqué]', $value);
        }
        if ($value instanceof \Throwable) {
            return ['type' => get_class($value), 'message' => self::redact($value->getMessage(), $depth + 1)];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && preg_match(self::SECRET_KEY, $k) ? '[masqué]' : self::redact($v, $depth + 1);
            }
            return $out;
        }
        return $value;
    }

    public static function write(string $level, string $message, mixed $data = null): void
    {
        $line = sprintf("%s [%s] %s %s\n", gmdate('c'), $level, $message, $data === null ? '' : json_encode(self::redact($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @file_put_contents(SC_STORAGE . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, mixed $d = null): void { self::write('info', $m, $d); }
    public static function warn(string $m, mixed $d = null): void { self::write('warn', $m, $d); }
    public static function error(string $m, mixed $d = null): void { self::write('error', $m, $d); }
}
