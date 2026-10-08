<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use Symfony\Component\Yaml\Yaml;

/**
 * Lecture / écriture des fichiers de contenu (JSON, YAML, Markdown avec en-tête),
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
            default => "---\n" . self::yamlDump($data ?: []) . "---\n\n" . rtrim($body ?? $parsed['body'] ?? '') . "\n",
        };
        return ($style['bom'] ? "\u{FEFF}" : '') . $out;
    }

    public static function create(string $format, mixed $data, ?string $body = null): string
    {
        return self::serialize(['format' => $format, 'style' => ['bom' => false, 'finalNewline' => true, 'indent' => 2], 'body' => ''], $data, $body);
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
