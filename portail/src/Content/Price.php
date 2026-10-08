<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use SimpleCommerce\Support\Text;

/** Les prix sont réécrits dans le format du site : nombre, centimes, ou texte « 4,50 € ». */
final class Price
{
    public static function toNumber(array $field, mixed $stored): ?float
    {
        $store = $field['priceFormat']['store'] ?? 'number';
        if ($stored === null || $stored === '') {
            return null;
        }
        if (is_int($stored) || is_float($stored)) {
            return $store === 'cents' ? $stored / 100 : (float) $stored;
        }
        return is_string($stored) ? Text::parseDecimal($stored) : null;
    }

    public static function toStored(array $field, ?float $value): mixed
    {
        $fmt = $field['priceFormat'] ?? ['store' => 'number'];
        if ($value === null) {
            return $fmt['store'] === 'string' ? '' : null;
        }
        $r = round($value, 2);
        if ($fmt['store'] === 'cents') {
            return (int) round($r * 100);
        }
        if ($fmt['store'] === 'number') {
            return floor($r) == $r ? (int) $r : $r;
        }
        $text = number_format($r, (floor($r) == $r && empty($fmt['decimal'])) ? 0 : 2, '.', '');
        if (($fmt['decimal'] ?? '.') === ',') {
            $text = str_replace('.', ',', $text);
        }
        return ($fmt['prefix'] ?? '') . $text . ($fmt['suffix'] ?? '');
    }

    public static function detect(array $samples): array
    {
        $first = null;
        foreach ($samples as $s) {
            if ($s !== null && $s !== '') {
                $first = $s;
                break;
            }
        }
        if (is_int($first) || is_float($first)) {
            $ints = array_filter($samples, fn ($s) => is_int($s) || is_float($s));
            $allInt = !array_filter($ints, fn ($s) => floor($s) != $s);
            $cents = $allInt && array_filter($ints, fn ($s) => $s >= 100) && !array_filter($ints, fn ($s) => ((int) $s) % 5 !== 0);
            return ['store' => $cents ? 'cents' : 'number'];
        }
        if (is_string($first) && preg_match('/^(\D*?)\s*([\d\s.,]+?)\s*(\D*)$/u', $first, $m)) {
            $out = ['store' => 'string', 'decimal' => str_contains($first, ',') ? ',' : '.'];
            if ($m[1] !== '') {
                $out['prefix'] = $m[1];
            }
            if ($m[3] !== '') {
                $out['suffix'] = str_contains($first, ' ' . $m[3]) ? ' ' . $m[3] : $m[3];
            }
            return $out;
        }
        return ['store' => 'number'];
    }
}
