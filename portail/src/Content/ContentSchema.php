<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

/**
 * Le schéma de contenu décrit ce que le client voit et a le droit de modifier.
 * Il est stocké en JSON ; cette classe le valide (forme, types, chemins sûrs).
 *
 * Section : key, label, kind (collection|singleton), itemLabel, titleField, imageField, subtitleField,
 *           idField, orderable, allowCreate, allowDelete, hidden, source, remote, fields.
 * Champ   : key, label, type, help, required, hidden, readOnly, maxLength, min, max, options, aspect,
 *           maxWidth, priceFormat, fields, itemLabel, fixedRows.
 */
final class ContentSchema
{
    public const FIELD_TYPES = ['text', 'textarea', 'richtext', 'markdown', 'price', 'number', 'boolean', 'select', 'date', 'image',
        'gallery', 'url', 'email', 'phone', 'list', 'group', 'repeater'];

    /** @throws \InvalidArgumentException avec un message en français */
    public static function parse(mixed $input): array
    {
        if (!is_array($input) || ($input['version'] ?? null) !== 1 || !is_array($input['sections'] ?? null)) {
            throw new \InvalidArgumentException('Schéma invalide : il faut « version: 1 » et une liste « sections ».');
        }
        if (count($input['sections']) > 60) {
            throw new \InvalidArgumentException('Trop de rubriques (60 au maximum).');
        }
        $seen = [];
        $sections = [];
        foreach (array_values($input['sections']) as $i => $s) {
            $sections[] = self::section($s, "sections.$i");
            if (isset($seen[$s['key']])) {
                throw new \InvalidArgumentException("Rubrique en double : {$s['key']}");
            }
            $seen[$s['key']] = true;
        }
        $out = ['version' => 1, 'sections' => $sections];
        if (isset($input['contentDir'])) {
            $out['contentDir'] = $input['contentDir'] === '' ? '' : self::relPath($input['contentDir'], 'contentDir');
        }
        if (isset($input['media'])) {
            $m = $input['media'];
            if (!is_array($m) || !is_string($m['publicPrefix'] ?? null)) {
                throw new \InvalidArgumentException('media : dir et publicPrefix attendus.');
            }
            $out['media'] = ['dir' => self::relPath($m['dir'] ?? '', 'media.dir'), 'publicPrefix' => substr($m['publicPrefix'], 0, 300)];
        }
        return $out;
    }

    private static function section(mixed $s, string $at): array
    {
        if (!is_array($s)) {
            throw new \InvalidArgumentException("$at : rubrique invalide.");
        }
        if (!is_string($s['key'] ?? null) || !preg_match('/^[a-z0-9-]{1,80}$/', $s['key'])) {
            throw new \InvalidArgumentException("$at.key : minuscules, chiffres et tirets.");
        }
        if (!is_string($s['label'] ?? null) || $s['label'] === '' || mb_strlen($s['label']) > 80) {
            throw new \InvalidArgumentException("$at.label : nom affiché obligatoire (80 caractères au plus).");
        }
        if (!in_array($s['kind'] ?? null, ['collection', 'singleton'], true)) {
            throw new \InvalidArgumentException("$at.kind : « collection » ou « singleton ».");
        }
        if (!is_array($s['fields'] ?? null) || !$s['fields']) {
            throw new \InvalidArgumentException("$at.fields : au moins un champ.");
        }
        $out = ['key' => $s['key'], 'label' => $s['label'], 'kind' => $s['kind']];
        foreach (['itemLabel', 'titleField', 'imageField', 'subtitleField', 'idField'] as $k) {
            if (isset($s[$k])) {
                if (!is_string($s[$k]) || mb_strlen($s[$k]) > 80) {
                    throw new \InvalidArgumentException("$at.$k invalide.");
                }
                $out[$k] = $s[$k];
            }
        }
        foreach (['orderable', 'allowCreate', 'allowDelete', 'hidden'] as $k) {
            if (isset($s[$k])) {
                $out[$k] = (bool) $s[$k];
            }
        }
        if (isset($s['source'])) {
            $out['source'] = self::source($s['source'], "$at.source");
        }
        if (isset($s['remote']) && is_array($s['remote'])) {
            $out['remote'] = $s['remote'];
        }
        $out['fields'] = array_map(fn ($f, $i) => self::field($f, "$at.fields.$i"), array_values($s['fields']), array_keys(array_values($s['fields'])));
        return $out;
    }

    private static function source(mixed $src, string $at): array
    {
        if (!is_array($src)) {
            throw new \InvalidArgumentException("$at invalide.");
        }
        if (($src['type'] ?? '') === 'file') {
            $out = ['type' => 'file', 'file' => self::relPath($src['file'] ?? '', "$at.file")];
            if (isset($src['format'])) {
                if (!in_array($src['format'], ['json', 'yaml'], true)) {
                    throw new \InvalidArgumentException("$at.format : json ou yaml.");
                }
                $out['format'] = $src['format'];
            }
            if (isset($src['path'])) {
                $out['path'] = substr((string) $src['path'], 0, 200);
            }
            return $out;
        }
        if (($src['type'] ?? '') === 'folder') {
            if (!in_array($src['format'] ?? null, ['markdown', 'json', 'yaml'], true)) {
                throw new \InvalidArgumentException("$at.format : markdown, json ou yaml.");
            }
            $out = ['type' => 'folder', 'folder' => self::relPath($src['folder'] ?? '', "$at.folder", true), 'format' => $src['format']];
            foreach (['extension', 'bodyField', 'orderField'] as $k) {
                if (isset($src[$k])) {
                    $out[$k] = substr((string) $src[$k], 0, 80);
                }
            }
            return $out;
        }
        throw new \InvalidArgumentException("$at.type : file ou folder.");
    }

    private static function field(mixed $f, string $at): array
    {
        if (!is_array($f) || !is_string($f['key'] ?? null) || !preg_match('/^[A-Za-z0-9_$@\-.]{1,80}$/', $f['key'])) {
            throw new \InvalidArgumentException("$at.key : clé de champ invalide.");
        }
        if (!is_string($f['label'] ?? null) || $f['label'] === '') {
            throw new \InvalidArgumentException("$at.label obligatoire.");
        }
        if (!in_array($f['type'] ?? null, self::FIELD_TYPES, true)) {
            throw new \InvalidArgumentException("$at.type : type inconnu.");
        }
        $out = ['key' => $f['key'], 'label' => mb_substr($f['label'], 0, 80), 'type' => $f['type']];
        foreach (['help' => 200, 'aspect' => 10, 'itemLabel' => 40] as $k => $max) {
            if (isset($f[$k])) {
                $out[$k] = mb_substr((string) $f[$k], 0, $max);
            }
        }
        if (isset($out['aspect']) && !preg_match('/^(\d+:\d+|libre)$/', $out['aspect'])) {
            throw new \InvalidArgumentException("$at.aspect : « 4:3 », « 1:1 » ou « libre ».");
        }
        foreach (['required', 'hidden', 'readOnly', 'fixedRows'] as $k) {
            if (isset($f[$k])) {
                $out[$k] = (bool) $f[$k];
            }
        }
        foreach (['maxLength', 'maxWidth'] as $k) {
            if (isset($f[$k])) {
                $out[$k] = max(1, min(100000, (int) $f[$k]));
            }
        }
        foreach (['min', 'max'] as $k) {
            if (isset($f[$k]) && is_numeric($f[$k])) {
                $out[$k] = $f[$k] + 0;
            }
        }
        if (isset($f['options']) && is_array($f['options'])) {
            $out['options'] = array_values(array_map(fn ($o) => ['value' => (string) ($o['value'] ?? ''), 'label' => (string) ($o['label'] ?? $o['value'] ?? '')], $f['options']));
        }
        if (isset($f['priceFormat']) && is_array($f['priceFormat'])) {
            $pf = $f['priceFormat'];
            if (!in_array($pf['store'] ?? null, ['number', 'cents', 'string'], true)) {
                throw new \InvalidArgumentException("$at.priceFormat.store : number, cents ou string.");
            }
            $out['priceFormat'] = array_filter(['store' => $pf['store'], 'decimal' => in_array($pf['decimal'] ?? null, [',', '.'], true) ? $pf['decimal'] : null,
                'prefix' => isset($pf['prefix']) ? mb_substr((string) $pf['prefix'], 0, 10) : null, 'suffix' => isset($pf['suffix']) ? mb_substr((string) $pf['suffix'], 0, 10) : null], fn ($v) => $v !== null);
        }
        if (isset($f['fields']) && is_array($f['fields'])) {
            $out['fields'] = array_map(fn ($sub, $i) => self::field($sub, "$at.fields.$i"), array_values($f['fields']), array_keys(array_values($f['fields'])));
        }
        return $out;
    }

    private static function relPath(mixed $p, string $at, bool $allowEmpty = false): string
    {
        if (!is_string($p) || ($p === '' && !$allowEmpty) || strlen($p) > 300 || str_contains($p, "\0") || str_starts_with($p, '/')
            || in_array('..', preg_split('#[\\\\/]#', $p), true)) {
            throw new \InvalidArgumentException("$at : chemin invalide.");
        }
        return $p;
    }

    public static function find(array $schema, string $key): ?array
    {
        foreach ($schema['sections'] ?? [] as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }
        return null;
    }

    public static function title(array $section, ?array $data): string
    {
        $key = $section['titleField'] ?? null;
        if (!$key) {
            foreach ($section['fields'] as $f) {
                if ($f['type'] === 'text') {
                    $key = $f['key'];
                    break;
                }
            }
        }
        $v = $key ? ($data[$key] ?? null) : null;
        if (is_string($v) && trim($v) !== '') {
            return mb_substr(trim($v), 0, 120);
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        return $section['kind'] === 'singleton' ? $section['label'] : ucfirst($section['itemLabel'] ?? 'élément') . ' sans titre';
    }

    /** Le champ « visible / en vente / disponible » d'une rubrique, s'il existe. */
    public static function visibilityField(array $section): ?string
    {
        foreach ($section['fields'] as $f) {
            if ($f['type'] === 'boolean' && empty($f['hidden']) && preg_match('/(disponible|available|envente|publie|published|visible|active|enligne|instock|vitrine)/i', $f['key'])) {
                return $f['key'];
            }
        }
        return null;
    }
}
