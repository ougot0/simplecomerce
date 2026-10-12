<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use Symfony\Component\Yaml\Yaml;

/**
 * Lecture / écriture des fichiers de contenu (JSON, YAML, Markdown avec en-tête, et fichiers JavaScript
 * de la forme « window.CONTENU = { … JSON … }; » pour les sites sans serveur qui chargent leurs données par une balise script),
 * en gardant le style du fichier : indentation JSON, retour à la ligne final, dates YAML non entre guillemets.
 */
final class Formats
{
    public static function fromPath(string $path): ?string
    {
        $p = strtolower($path);
        return match (true) {
            str_ends_with($p, '.json') => 'json',
            str_ends_with($p, '.yml'), str_ends_with($p, '.yaml') => 'yaml',
            str_ends_with($p, '.md'), str_ends_with($p, '.mdx'), str_ends_with($p, '.markdown') => 'markdown',
            str_ends_with($p, '.js') && !str_ends_with($p, '.min.js') => 'js',
            str_ends_with($p, '.html'), str_ends_with($p, '.htm') => 'html',
            default => null,
        };
    }

    /** @return array{format: string, data: mixed, body: ?string, style: array} */
    public static function parse(string $text, string $format): array
    {
        $bom = str_starts_with($text, "\u{FEFF}");
        $src = $bom ? substr($text, 3) : $text;
        $style = ['bom' => $bom, 'finalNewline' => str_ends_with($src, "\n"), 'indent' => 2];
        if ($format === 'json') {
            if (preg_match('/^[\[{]\s*\n([ \t]+)/', $src, $m)) {
                $style['indent'] = str_contains($m[1], "\t") ? "\t" : strlen($m[1]);
            }
            $data = trim($src) === '' ? null : self::jsonDecode($src);
            return ['format' => 'json', 'data' => $data, 'body' => null, 'style' => $style];
        }
        if ($format === 'js') {
            // Seul le JSON après « = » est du contenu ; le texte autour (commentaires, déclaration) est conservé tel quel.
            if (preg_match('/^(.*?(?:^|\n)[ \t]*(?:window\.[\w$]+|globalThis\.[\w$]+|(?:var|let|const)\s+[\w$]+)\s*=\s*)([\[{].*[\]}])(\s*;?\s*)$/s', $src, $m)) {
                try {
                    $data = self::jsonDecode($m[2]);
                    if (preg_match('/^[\[{]\s*\n([ \t]+)/', $m[2], $i)) {
                        $style['indent'] = str_contains($i[1], "\t") ? "\t" : strlen($i[1]);
                    }
                    $style += ['prefix' => $m[1], 'suffix' => $m[3]];
                    return ['format' => 'js', 'data' => $data, 'body' => null, 'style' => $style];
                } catch (\JsonException) {
                    // Pas du JSON pur : on lit le fichier comme du JavaScript ci-dessous.
                }
            }
            return self::parseJsVars($src, $style);
        }
        if ($format === 'html') {
            $groups = HtmlCards::find($src);
            $data = [];
            foreach ($groups as $key => $g) {
                $data[$key] = array_map(fn ($c) => HtmlCards::values($src, $c), $g['cards']);
            }
            return ['format' => 'html', 'data' => $data, 'body' => null, 'style' => $style + ['source' => $src, 'groups' => $groups,
                'labels' => array_map(fn ($g) => $g['label'], $groups), 'orig' => $data]];
        }
        if ($format === 'yaml') {
            return ['format' => 'yaml', 'data' => self::yamlParse($src), 'body' => null, 'style' => $style];
        }
        // Markdown : en-tête YAML entre deux lignes « --- », puis le texte.
        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $src, $m)) {
            return ['format' => 'markdown', 'data' => self::yamlParse($m[1]) ?? [], 'body' => ltrim($m[2], "\r\n"), 'style' => $style];
        }
        return ['format' => 'markdown', 'data' => [], 'body' => $src, 'style' => $style];
    }

    public static function serialize(array $parsed, mixed $data, ?string $body = null): string
    {
        $style = $parsed['style'];
        $out = match ($parsed['format']) {
            'json' => self::jsonEncode($data, $style['indent']) . ($style['finalNewline'] ? "\n" : ''),
            'yaml' => self::yamlDump($data),
            'js' => ($style['prefix'] ?? 'window.CONTENU = ') . self::jsonEncode($data, $style['indent']) . ($style['suffix'] ?? ";\n"),
            'jsvars' => self::serializeJsVars($style, (array) $data),
            'html' => self::serializeHtml($style, (array) $data),
            default => "---\n" . self::yamlDump($data ?: []) . "---\n\n" . rtrim($body ?? $parsed['body'] ?? '') . "\n",
        };
        return ($style['bom'] ? "\u{FEFF}" : '') . $out;
    }

    public static function create(string $format, mixed $data, ?string $body = null): string
    {
        if ($format === 'js') {
            throw new \RuntimeException('Création de fichier JavaScript non prise en charge.');
        }
        return self::serialize(['format' => $format, 'style' => ['bom' => false, 'finalNewline' => true, 'indent' => 2], 'body' => ''], $data, $body);
    }

    /**
     * Fichier JavaScript d'un site fait main : chaque « const NOM = [ … ] » ou « { … } » lisible devient une entrée NOM.
     * Le reste du fichier (code, commentaires) n'est jamais réécrit.
     */
    private static function parseJsVars(string $src, array $style): array
    {
        $decls = JsLiteral::declarations($src);
        if (!$decls) {
            throw new \RuntimeException('Fichier JavaScript sans données lisibles.');
        }
        $data = [];
        $meta = [];
        foreach ($decls as $name => $d) {
            $lineStart = strrpos(substr($src, 0, $d['start']), "\n");
            $line = substr($src, $lineStart === false ? 0 : $lineStart + 1, 200);
            preg_match('/^[ \t]*/', $line, $ind);
            $data[$name] = $d['value'];
            $meta[$name] = ['start' => $d['start'], 'end' => $d['end'], 'orig' => json_encode($d['value']), 'items' => $d['items'],
                'origItems' => is_array($d['items']) ? array_map('json_encode', $d['value']) : null,
                'style' => JsLiteral::style(substr($src, $d['start'], $d['end'] - $d['start']), $ind[0])];
        }
        return ['format' => 'jsvars', 'data' => $data, 'body' => null, 'style' => $style + ['source' => $src, 'decls' => $meta]];
    }

    /** Pages HTML : chaque fiche modifiée est réécrite sur place ; une fiche ajoutée est copiée sur la dernière. */
    private static function serializeHtml(array $style, array $data): string
    {
        $src = $style['source'];
        $changes = [];
        foreach ($style['groups'] as $key => $g) {
            $new = array_values((array) ($data[$key] ?? []));
            $old = $style['orig'][$key];
            if (json_encode($new) === json_encode($old)) {
                continue;
            }
            $cards = $g['cards'];
            $sep = count($cards) > 1 ? substr($src, $cards[0]['end'], $cards[1]['start'] - $cards[0]['end']) : "\n";
            $oldJson = array_map('json_encode', $old);
            $used = [];
            $chunks = [];
            foreach ($new as $i => $item) {
                $same = array_search(json_encode($item), array_diff_key($oldJson, $used), true);
                if ($same !== false) {
                    $used[$same] = true;
                    $c = $cards[$same];
                    $chunks[] = substr($src, $c['start'], $c['end'] - $c['start']);
                } elseif (isset($cards[$i]) && !isset($used[$i])) {
                    $used[$i] = true;
                    $chunks[] = HtmlCards::patch($src, $cards[$i], $item);
                } else {
                    $chunks[] = HtmlCards::patch($src, $cards[count($cards) - 1], $item, true);
                }
            }
            $first = $cards[0]['start'];
            $last = $cards[count($cards) - 1]['end'];
            $changes[$first] = [$last, implode($sep, $chunks)];
        }
        krsort($changes);
        foreach ($changes as $start => [$end, $text]) {
            $src = substr($src, 0, $start) . $text . substr($src, $end);
        }
        return $src;
    }

    private static function serializeJsVars(array $style, array $data): string
    {
        $src = $style['source'];
        $changes = [];
        foreach ($style['decls'] as $name => $m) {
            if (!array_key_exists($name, $data) || json_encode($data[$name]) === $m['orig']) {
                continue;
            }
            $new = $data[$name];
            $items = $m['items'];
            // Liste : on ne réécrit que les éléments modifiés, ajoutés ou retirés ; les commentaires des autres restent.
            if (is_array($items) && $items && is_array($new) && array_is_list($new)) {
                $itemStyle = ['base' => $m['style']['base'] . $m['style']['indent']] + $m['style'];
                $old = $m['origItems'];
                $newJson = array_map('json_encode', $new);
                $sameStart = 0;
                while ($sameStart < count($old) && $sameStart < count($newJson) && $old[$sameStart] === $newJson[$sameStart]) {
                    $sameStart++;
                }
                $sameEnd = 0;
                while ($sameEnd < count($old) - $sameStart && $sameEnd < count($newJson) - $sameStart && $old[count($old) - 1 - $sameEnd] === $newJson[count($newJson) - 1 - $sameEnd]) {
                    $sameEnd++;
                }
                $oldFrom = $sameStart;
                $oldTo = count($old) - $sameEnd;          // éléments d'origine [oldFrom, oldTo) remplacés
                $insert = array_slice($new, $sameStart, count($new) - $sameEnd - $sameStart);
                $text = implode(",\n" . $itemStyle['base'], array_map(fn ($x) => JsLiteral::encode($x, ['base' => $itemStyle['base']] + $m['style'], 0), $insert));
                if ($oldFrom < $oldTo) {
                    // Remplacement (ou suppression) d'éléments consécutifs.
                    $from = $items[$oldFrom][0];
                    $to = $items[$oldTo - 1][1];
                    if ($text === '') {
                        // Suppression : on retire aussi la virgule qui suit, et la fin de ligne.
                        $after = substr($src, $to);
                        if (preg_match('/^\s*,[ \t]*(\/\/[^\n]*)?\r?\n?/', $after, $c)) {
                            $to += strlen($c[0]);
                        }
                        $lineStart = strrpos(substr($src, 0, $from), "\n");
                        if ($lineStart !== false && trim(substr($src, $lineStart + 1, $from - $lineStart - 1)) === '') {
                            $from = $lineStart + 1;
                        }
                    }
                    $changes[$from] = [$to, $text];
                } else {
                    // Ajout pur : après l'élément précédent (ou avant le suivant).
                    if ($oldFrom > 0) {
                        $at = $items[$oldFrom - 1][1];
                        $comma = preg_match('/^\s*,/', substr($src, $at)) ? '' : ',';
                        $changes[$at] = [$at, $comma . "\n" . $itemStyle['base'] . $text . ($comma === '' ? ',' : '')];
                        if ($comma === '') {
                            // « a, » existe déjà : on insère après la virgule.
                            $pos = $at + strpos(substr($src, $at), ',') + 1;
                            unset($changes[$at]);
                            $changes[$pos] = [$pos, "\n" . $itemStyle['base'] . $text . ','];
                        }
                    } else {
                        $at = $items[0][0];
                        $changes[$at] = [$at, $text . ",\n" . $itemStyle['base']];
                    }
                }
                continue;
            }
            $changes[$m['start']] = [$m['end'], JsLiteral::encode($new, $m['style'])];
        }
        krsort($changes);
        foreach ($changes as $start => [$end, $text]) {
            $src = substr($src, 0, $start) . $text . substr($src, $end);
        }
        // Le BOM éventuel est remis par serialize().
        return $style['bom'] ? substr($src, 0) : $src;
    }

    /** JSON → tableaux PHP, mais un objet vide reste un objet (sinon il deviendrait [] à la réécriture). */
    public static function jsonDecode(string $json): mixed
    {
        return self::fromObjects(json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    private static function fromObjects(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $arr = get_object_vars($v);
            if (!$arr) {
                return new \stdClass();
            }
            return array_map([self::class, 'fromObjects'], $arr);
        }
        if (is_array($v)) {
            return array_map([self::class, 'fromObjects'], $v);
        }
        return $v;
    }

    public static function jsonEncode(mixed $data, int|string $indent = 2): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if ($indent === 4) {
            return $json;
        }
        $unit = $indent === "\t" ? "\t" : str_repeat(' ', (int) $indent);
        return (string) preg_replace_callback('/^( {4})+/m', fn ($m) => str_repeat($unit, intdiv(strlen($m[0]), 4)), $json);
    }

    private static function yamlParse(string $text): mixed
    {
        try {
            $data = Yaml::parse($text, Yaml::PARSE_DATETIME);
        } catch (\Throwable $e) {
            throw new \RuntimeException('YAML invalide : ' . $e->getMessage());
        }
        return self::datesToStrings($data);
    }

    private static function datesToStrings(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format('c');
        }
        return is_array($v) ? array_map([self::class, 'datesToStrings'], $v) : $v;
    }

    private static function yamlDump(mixed $data): string
    {
        $yaml = Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        // Les dates restent des dates YAML (sans guillemets), comme dans le fichier d'origine.
        return (string) preg_replace("/: '(\d{4}-\d{2}-\d{2}(?:T[\d:+\-]+)?)'$/m", ': $1', $yaml);
    }
}
