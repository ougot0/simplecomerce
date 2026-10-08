<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

final class Paths
{
    /** @return string[] */
    public static function split(?string $path): array
    {
        return $path ? array_values(array_filter(explode('.', $path), fn ($p) => $p !== '')) : [];
    }

    public static function get(mixed $root, ?string $path): mixed
    {
        $cur = $root;
        foreach (self::split($path) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return null;
            }
            $cur = $cur[$p];
        }
        return $cur;
    }

    public static function set(mixed $root, ?string $path, mixed $value): mixed
    {
        $parts = self::split($path);
        if (!$parts) {
            return $value;
        }
        $head = array_shift($parts);
        $base = is_array($root) ? $root : [];
        $base[$head] = self::set($base[$head] ?? null, implode('.', $parts), $value);
        return $base;
    }

    /** Joint des chemins relatifs en refusant toute sortie du dossier racine. */
    public static function join(string ...$parts): string
    {
        $segments = [];
        foreach ($parts as $part) {
            foreach (preg_split('#[\\\\/]+#', $part) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..' || str_contains($seg, "\0")) {
                    throw new \InvalidArgumentException('Chemin interdit');
                }
                $segments[] = $seg;
            }
        }
        return implode('/', $segments);
    }
}
