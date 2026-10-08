<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use SimpleCommerce\Support\Text;

/**
 * Convertit les valeurs d'un formulaire HTML (toujours du texte) en valeurs typées, avant validation par Values::normalize.
 * Conventions des gabarits : case à cocher précédée d'un champ caché « 0 » ; listes précédées d'un élément vide ;
 * lignes répétées précédées d'une clé « _ » et portant leur position d'origine dans « __index ».
 */
final class FormInput
{
    public static function coerce(array $fields, array $raw): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f['key'], $raw)) {
                continue;
            }
            $v = $raw[$f['key']];
            $out[$f['key']] = match ($f['type']) {
                'boolean' => is_string($v) && $v === '1',
                'price' => self::price($f, $v),
                'list', 'gallery' => is_array($v) ? array_values(array_filter($v, fn ($x) => is_string($x) && trim($x) !== '')) : $v,
                'group' => is_array($v) ? self::coerce($f['fields'] ?? [], $v) : $v,
                'repeater' => is_array($v) ? self::rows($f, $v) : $v,
                default => $v,
            };
        }
        return $out;
    }

    private static function price(array $f, mixed $v): mixed
    {
        if (!is_string($v)) {
            return $v;
        }
        $v = trim($v);
        if ($v === '') {
            return Price::toStored($f, null);
        }
        $n = Text::parseDecimal($v);
        return $n === null ? $v : Price::toStored($f, $n);
    }

    private static function rows(array $f, array $v): array
    {
        unset($v['_']);
        $rows = [];
        foreach ($v as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = $row['__index'] ?? '';
            $r = self::coerce($f['fields'] ?? [], $row);
            if (is_string($index) && ctype_digit($index)) {
                $r['__index'] = (int) $index;
            }
            $rows[] = $r;
        }
        return $rows;
    }

    /** Empreinte du contenu affiché, pour repérer une modification faite entre-temps. */
    public static function fingerprint(array $section, array $data): string
    {
        return hash('sha256', (string) json_encode(self::canonical(Values::pick($section, $data)), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $v = (array) $v;
        }
        if (is_array($v)) {
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map([self::class, 'canonical'], $v);
        }
        if (is_float($v) && floor($v) == $v) {
            return (int) $v;
        }
        return $v === null ? '' : $v;
    }
}
