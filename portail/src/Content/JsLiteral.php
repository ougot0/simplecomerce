<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

/**
 * Sites faits main : leurs produits sont souvent écrits dans un fichier JavaScript, sous la forme
 * « const PRODUITS = [ { name: "…", price: "45 €" }, … ]; » (clés sans guillemets, virgules finales, commentaires).
 * Cette classe trouve ces déclarations, lit leur valeur et sait réécrire une seule déclaration
 * dans le même style, sans toucher au reste du fichier (code, commentaires, autres variables).
 */
final class JsLiteral
{
    private string $s;
    private int $i = 0;
    private int $n;

    private function __construct(string $s, int $offset)
    {
        $this->s = $s;
        $this->i = $offset;
        $this->n = strlen($s);
    }

    /**
     * Déclarations de premier niveau dont la valeur est un tableau ou un objet littéral.
     * @return array<string, array{start: int, end: int, value: mixed}>
     */
    public static function declarations(string $src): array
    {
        $out = [];
        if (!preg_match_all('/^[ \t]*(?:export[ \t]+)?(?:(?:const|let|var)[ \t]+([A-Za-z_$][\w$]*)|(?:window|globalThis)\.([A-Za-z_$][\w$]*))[ \t]*=[ \t]*(?=[\[{])/m', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($m as $match) {
            $name = ($match[1][0] ?? '') !== '' ? $match[1][0] : $match[2][0];
            $start = $match[0][1] + strlen($match[0][0]);
            $p = new self($src, $start);
            try {
                $value = $p->value();
            } catch (\RuntimeException) {
                continue;
            }
            if (!isset($out[$name])) {
                $out[$name] = ['start' => $start, 'end' => $p->i, 'value' => $value, 'items' => is_array($value) && array_is_list($value) ? self::itemSpans($src, $start) : null];
            }
        }
        return $out;
    }

    /** Début et fin de chaque élément d'une liste, pour ne réécrire que les éléments modifiés. @return array<int, array{0: int, 1: int}> */
    private static function itemSpans(string $src, int $start): array
    {
        $p = new self($src, $start + 1);
        $spans = [];
        while (true) {
            $p->skip();
            if (($p->s[$p->i] ?? '') === ']') {
                return $spans;
            }
            $from = $p->i;
            $p->value();
            $spans[] = [$from, $p->i];
            $p->skip();
            if (($p->s[$p->i] ?? '') === ',') {
                $p->i++;
            }
        }
    }

    /** Lit un littéral complet (pour les tests). */
    public static function decode(string $text): mixed
    {
        $p = new self($text, 0);
        $v = $p->value();
        $p->skip();
        if ($p->i < $p->n && trim(substr($text, $p->i), " \t\r\n;") !== '') {
            throw new \RuntimeException('Texte en trop après la valeur.');
        }
        return $v;
    }

    // ------------------------------------------------------------ lecture

    private function skip(): void
    {
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->i++;
            } elseif ($c === '/' && ($this->s[$this->i + 1] ?? '') === '/') {
                $e = strpos($this->s, "\n", $this->i);
                $this->i = $e === false ? $this->n : $e + 1;
            } elseif ($c === '/' && ($this->s[$this->i + 1] ?? '') === '*') {
                $e = strpos($this->s, '*/', $this->i + 2);
                if ($e === false) {
                    throw new \RuntimeException('Commentaire non fermé.');
                }
                $this->i = $e + 2;
            } else {
                return;
            }
        }
    }

    private function value(): mixed
    {
        $this->skip();
        $c = $this->s[$this->i] ?? '';
        return match (true) {
            $c === '{' => $this->object(),
            $c === '[' => $this->array(),
            $c === '"' || $c === "'" || $c === '`' => $this->string(),
            $c === '-' || $c === '+' || $c === '.' || ctype_digit($c) => $this->number(),
            default => $this->word(),
        };
    }

    private function word(): mixed
    {
        if (!preg_match('/\G(true|false|null|undefined)\b/', $this->s, $m, 0, $this->i)) {
            throw new \RuntimeException('Valeur non lisible.');
        }
        $this->i += strlen($m[1]);
        return match ($m[1]) { 'true' => true, 'false' => false, default => null };
    }

    private function number(): int|float
    {
        if (!preg_match('/\G[+-]?(?:\d[\d_]*(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $this->s, $m, 0, $this->i)) {
            throw new \RuntimeException('Nombre non lisible.');
        }
        $this->i += strlen($m[0]);
        $t = str_replace('_', '', ltrim($m[0], '+'));
        return preg_match('/[.eE]/', $t) ? (float) $t : (int) $t;
    }

    private function string(): string
    {
        $q = $this->s[$this->i++];
        $out = '';
        while ($this->i < $this->n) {
            $c = $this->s[$this->i++];
            if ($c === $q) {
                return $out;
            }
            if ($q === '`' && $c === '$' && ($this->s[$this->i] ?? '') === '{') {
                throw new \RuntimeException('Texte calculé.');
            }
            if ($c === '\\') {
                $e = $this->s[$this->i++] ?? '';
                $out .= match ($e) {
                    'n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 'f' => "\f", 'v' => "\v", '0' => "\0",
                    "\n" => '',
                    'u' => $this->unicode(),
                    'x' => $this->hex(),
                    default => $e,
                };
                continue;
            }
            if (($c === "\n") && $q !== '`') {
                throw new \RuntimeException('Texte non fermé.');
            }
            $out .= $c;
        }
        throw new \RuntimeException('Texte non fermé.');
    }

    private function hex(): string
    {
        $h = substr($this->s, $this->i, 2);
        $this->i += 2;
        return mb_chr((int) hexdec($h), 'UTF-8');
    }

    private function unicode(): string
    {
        if (($this->s[$this->i] ?? '') === '{') {
            $e = strpos($this->s, '}', $this->i);
            $code = hexdec(substr($this->s, $this->i + 1, $e - $this->i - 1));
            $this->i = $e + 1;
            return mb_chr((int) $code, 'UTF-8');
        }
        $code = hexdec(substr($this->s, $this->i, 4));
        $this->i += 4;
        if ($code >= 0xD800 && $code <= 0xDBFF && substr($this->s, $this->i, 2) === '\\u') {
            $low = hexdec(substr($this->s, $this->i + 2, 4));
            $this->i += 6;
            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
        }
        return mb_chr((int) $code, 'UTF-8');
    }

    private function array(): array
    {
        $this->i++;
        $out = [];
        while (true) {
            $this->skip();
            if (($this->s[$this->i] ?? '') === ']') {
                $this->i++;
                return $out;
            }
            $out[] = $this->value();
            $this->skip();
            $c = $this->s[$this->i] ?? '';
            if ($c === ',') {
                $this->i++;
            } elseif ($c !== ']') {
                throw new \RuntimeException('Liste non lisible.');
            }
        }
    }

    private function object(): array|\stdClass
    {
        $this->i++;
        $out = [];
        while (true) {
            $this->skip();
            $c = $this->s[$this->i] ?? '';
            if ($c === '}') {
                $this->i++;
                return $out ?: new \stdClass();
            }
            if ($c === '"' || $c === "'") {
                $key = $this->string();
            } elseif (preg_match('/\G[A-Za-z_$][\w$]*|\G\d+/', $this->s, $m, 0, $this->i)) {
                $key = $m[0];
                $this->i += strlen($m[0]);
            } else {
                throw new \RuntimeException('Clé non lisible.');
            }
            $this->skip();
            if (($this->s[$this->i] ?? '') !== ':') {
                throw new \RuntimeException('Objet non lisible.');
            }
            $this->i++;
            $out[$key] = $this->value();
            $this->skip();
            $c = $this->s[$this->i] ?? '';
            if ($c === ',') {
                $this->i++;
            } elseif ($c !== '}') {
                throw new \RuntimeException('Objet non lisible.');
            }
        }
    }

    // ------------------------------------------------------------ écriture

    /** Style d'écriture du littéral d'origine : guillemets, clés sans guillemets, indentation, virgules finales. */
    public static function style(string $literal, string $lineIndent): array
    {
        $single = substr_count($literal, "'");
        $double = substr_count($literal, '"');
        preg_match_all('/[{,]\s*([A-Za-z_$][\w$]*)\s*:/', $literal, $bare);
        preg_match_all('/[{,]\s*["\']([^"\']+)["\']\s*:/', $literal, $quoted);
        $indent = preg_match('/\n([ \t]+)\S/', $literal, $m) ? substr($m[1], strlen($lineIndent)) : '  ';
        return [
            'quote' => $single > $double ? "'" : '"',
            'bareKeys' => count($bare[1]) >= count($quoted[1]),
            'indent' => $indent === '' ? '  ' : $indent,
            'trailing' => (bool) preg_match('/,\s*[\]}]/', $literal),
            'base' => $lineIndent,
        ];
    }

    public static function encode(mixed $v, array $style, int $depth = 0): string
    {
        $pad = $style['base'] . str_repeat($style['indent'], $depth);
        $inner = $pad . $style['indent'];
        $comma = $style['trailing'] ? ',' : '';
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
            if (!$v) {
                return '{}';
            }
        }
        if (is_array($v)) {
            if ($v === []) {
                return '[]';
            }
            if (array_is_list($v)) {
                $parts = array_map(fn ($x) => $inner . self::encode($x, $style, $depth + 1), $v);
                return "[\n" . implode(",\n", $parts) . "$comma\n$pad]";
            }
            $parts = [];
            foreach ($v as $k => $x) {
                $k = (string) $k;
                $key = $style['bareKeys'] && preg_match('/^[A-Za-z_$][\w$]*$/', $k) ? $k : self::quote($k, $style['quote']);
                $parts[] = $inner . $key . ': ' . self::encode($x, $style, $depth + 1);
            }
            return "{\n" . implode(",\n", $parts) . "$comma\n$pad}";
        }
        return match (true) {
            $v === null => 'null',
            $v === true => 'true',
            $v === false => 'false',
            is_int($v) => (string) $v,
            is_float($v) => json_encode($v, JSON_PRESERVE_ZERO_FRACTION),
            default => self::quote((string) $v, $style['quote']),
        };
    }

    private static function quote(string $s, string $q): string
    {
        $s = str_replace(['\\', $q, "\n", "\r", "\t"], ['\\\\', '\\' . $q, '\\n', '\\r', '\\t'], $s);
        return $q . $s . $q;
    }
}
