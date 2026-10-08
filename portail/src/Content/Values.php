<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use SimpleCommerce\Support\Html;

/**
 * Validation côté serveur des valeurs d'un formulaire.
 * Les champs masqués ou en lecture seule gardent toujours leur valeur actuelle ;
 * les propriétés inconnues du schéma sont conservées telles quelles.
 */
final class Values
{
    public const MEDIA_PREFIX = 'sc-media:';
    private const URL_RE = '#^(https?://\S+|/\S*|\#\S*|mailto:\S+|tel:\S+)$#i';
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}([T ][\d:.]+(Z|[+\-]\d{2}:?\d{2})?)?$/';
    private const IMAGE_RE = '#^(https?://|/|\.{0,2}/?[\w\-./]+|sc-media:[\w\-]+$)#';

    /** @return array{0: array, 1: array<string, string>} [données, erreurs] */
    public static function normalize(array $fields, array $input, array $current, string $prefix = ''): array
    {
        $data = $current;
        $errors = [];
        foreach ($fields as $f) {
            $path = $prefix . $f['key'];
            if (!empty($f['hidden']) || !empty($f['readOnly']) || !array_key_exists($f['key'], $input)) {
                continue;
            }
            [$value, $error, $nested] = self::value($f, $input[$f['key']], $current[$f['key']] ?? null, $path);
            if ($error !== null) {
                $errors[$path] = $error;
            } else {
                $data[$f['key']] = $value;
            }
            $errors += $nested;
        }
        foreach ($fields as $f) {
            $path = $prefix . $f['key'];
            if (!empty($f['required']) && empty($f['hidden']) && !isset($errors[$path]) && self::isEmpty($data[$f['key']] ?? null)) {
                $errors[$path] = 'Ce champ est obligatoire.';
            }
        }
        return [$data, $errors];
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    /** @return array{0: mixed, 1: ?string, 2: array} */
    private static function value(array $f, mixed $raw, mixed $current, string $path): array
    {
        $max = $f['maxLength'] ?? null;
        $tooLong = fn (string $s) => $max !== null && mb_strlen($s) > $max;
        $ok = fn ($v) => [$v, null, []];
        $ko = fn (string $m) => [null, $m, []];
        switch ($f['type']) {
            case 'text':
            case 'textarea':
            case 'markdown':
                if ($raw === null) {
                    return $ok('');
                }
                if (!is_string($raw)) {
                    return $ko('Texte attendu.');
                }
                $v = $f['type'] === 'text' ? preg_replace('/[\r\n]+/', ' ', $raw) : str_replace("\r\n", "\n", $raw);
                return $tooLong($v) ? $ko("{$max} caractères au maximum (actuellement " . mb_strlen($v) . ').') : $ok($v);
            case 'richtext':
                if ($raw === null) {
                    return $ok('');
                }
                if (!is_string($raw)) {
                    return $ko('Texte attendu.');
                }
                $v = Html::sanitize($raw);
                $visible = strip_tags($v);
                return $tooLong($visible) ? $ko("{$max} caractères au maximum (actuellement " . mb_strlen($visible) . ').') : $ok($v);
            case 'price':
                $store = $f['priceFormat']['store'] ?? 'number';
                if ($raw === null || $raw === '') {
                    return $ok($store === 'string' ? '' : null);
                }
                if ($store === 'string') {
                    return is_string($raw) && preg_match('/\d/', $raw) && strlen($raw) <= 30 ? $ok($raw) : $ko('Prix invalide : écrivez par exemple 4,50.');
                }
                if (!is_int($raw) && !is_float($raw) || $raw < 0 || $raw > 10000000 || ($store === 'cents' && !is_int($raw))) {
                    return $ko('Prix invalide : écrivez par exemple 4,50.');
                }
                return $ok($raw);
            case 'number':
                if ($raw === null || $raw === '') {
                    return $ok(null);
                }
                $n = is_string($raw) ? str_replace(',', '.', $raw) : $raw;
                if (!is_numeric($n)) {
                    return $ko('Nombre attendu.');
                }
                $n += 0;
                if (isset($f['min']) && $n < $f['min']) {
                    return $ko("Minimum : {$f['min']}.");
                }
                if (isset($f['max']) && $n > $f['max']) {
                    return $ko("Maximum : {$f['max']}.");
                }
                return $ok($n);
            case 'boolean':
                return is_bool($raw) ? $ok($raw) : $ko('Oui ou non attendu.');
            case 'select':
                if ($raw === null || $raw === '') {
                    return $ok('');
                }
                $values = array_column($f['options'] ?? [], 'value');
                return is_string($raw) && in_array($raw, $values, true) ? $ok($raw) : $ko('Choix invalide.');
            case 'date':
                if ($raw === null || $raw === '') {
                    return $ok('');
                }
                return is_string($raw) && preg_match(self::DATE_RE, $raw) ? $ok($raw) : $ko('Date invalide.');
            case 'image':
                if ($raw === null || $raw === '') {
                    return $ok('');
                }
                return is_string($raw) && strlen($raw) <= 1000 && preg_match(self::IMAGE_RE, $raw) && !preg_match('/^javascript:/i', $raw) ? $ok($raw) : $ko('Image invalide.');
            case 'gallery':
            case 'list':
                if ($raw === null) {
                    return $ok([]);
                }
                if (!is_array($raw) || !array_is_list($raw) || count($raw) > 200) {
                    return $ko('Liste invalide.');
                }
                foreach ($raw as $item) {
                    if (!is_string($item) || strlen($item) > 1000 || ($f['type'] === 'gallery' && (!preg_match(self::IMAGE_RE, $item) || preg_match('/^javascript:/i', $item)))) {
                        return $ko($f['type'] === 'gallery' ? 'Image invalide.' : 'Liste invalide.');
                    }
                }
                return $ok($raw);
            case 'url':
                if ($raw === null || $raw === '') {
                    return $ok('');
                }
                return is_string($raw) && preg_match(self::URL_RE, trim($raw)) ? $ok(trim($raw)) : $ko('Adresse invalide (elle doit commencer par https://).');
            case 'email':
                if ($raw === null || $raw === '') {
                    return $ok('');
                }
                return is_string($raw) && filter_var(trim($raw), FILTER_VALIDATE_EMAIL) ? $ok(trim($raw)) : $ko('Adresse e-mail invalide.');
            case 'phone':
                if ($raw === null || $raw === '') {
                    return $ok('');
                }
                return is_string($raw) && preg_match('/^[+()\d\s.\-]{4,25}$/', trim($raw)) ? $ok(trim($raw)) : $ko('Numéro invalide.');
            case 'group':
                if ($raw === null) {
                    return $ok($current ?? []);
                }
                if (!is_array($raw)) {
                    return $ko('Bloc invalide.');
                }
                [$d, $e] = self::normalize($f['fields'] ?? [], $raw, is_array($current) ? $current : [], "$path.");
                return [$d, null, $e];
            case 'repeater':
                if ($raw === null) {
                    return $ok([]);
                }
                if (!is_array($raw) || !array_is_list($raw) || count($raw) > 500) {
                    return $ko('Liste invalide.');
                }
                $curList = is_array($current) && array_is_list($current) ? $current : [];
                if (!empty($f['fixedRows']) && count($raw) !== count($curList)) {
                    return $ko('Le nombre de lignes ne peut pas être modifié ici.');
                }
                $out = [];
                $nested = [];
                foreach ($raw as $i => $row) {
                    if (!is_array($row)) {
                        $nested["$path.$i"] = 'Ligne invalide.';
                        continue;
                    }
                    // __index : position d'origine de la ligne, pour garder ses données cachées.
                    $origin = isset($row['__index']) && is_int($row['__index']) ? ($curList[$row['__index']] ?? []) : [];
                    unset($row['__index']);
                    [$d, $e] = self::normalize($f['fields'] ?? [], $row, is_array($origin) ? $origin : [], "$path.$i.");
                    $nested += $e;
                    $out[] = $d;
                }
                return [$out, null, $nested];
        }
        return $ko('Champ inconnu.');
    }

    /** Ne garde que les champs du schéma : c'est ce qu'on montre et ce qu'on historise. */
    public static function pick(array $section, array $data): array
    {
        $out = [];
        foreach ($section['fields'] as $f) {
            if (array_key_exists($f['key'], $data)) {
                $out[$f['key']] = $data[$f['key']];
            }
        }
        return $out;
    }

    public static function equal(mixed $a, mixed $b): bool
    {
        if ($a instanceof \stdClass) {
            $a = (array) $a;
        }
        if ($b instanceof \stdClass) {
            $b = (array) $b;
        }
        if ($a === $b) {
            return true;
        }
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) && is_array($b)) {
            if (array_is_list($a) !== array_is_list($b) && ($a || $b)) {
                return false;
            }
            $keys = array_unique([...array_keys($a), ...array_keys($b)]);
            foreach ($keys as $k) {
                if (!self::equal($a[$k] ?? null, $b[$k] ?? null)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** @return string[] identifiants des photos en attente (« sc-media:… ») */
    public static function mediaTokens(mixed $v, array &$out = []): array
    {
        if (is_string($v) && str_starts_with($v, self::MEDIA_PREFIX)) {
            $out[substr($v, strlen(self::MEDIA_PREFIX))] = true;
        } elseif (is_array($v)) {
            foreach ($v as $x) {
                self::mediaTokens($x, $out);
            }
        }
        return array_keys($out);
    }

    public static function replaceMedia(mixed $v, array $map): mixed
    {
        if (is_string($v) && str_starts_with($v, self::MEDIA_PREFIX)) {
            return $map[substr($v, strlen(self::MEDIA_PREFIX))] ?? $v;
        }
        return is_array($v) ? array_map(fn ($x) => self::replaceMedia($x, $map), $v) : $v;
    }
}
