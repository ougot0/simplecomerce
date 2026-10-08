<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

/** Petits formats et accords en français. */
final class Text
{
    private const FEMININE = ['actualité', 'création', 'réalisation', 'prestation', 'page', 'photo', 'ligne', 'collection', 'catégorie', 'recette',
        'formule', 'boisson', 'entrée', 'salade', 'pizza', 'tarte', 'boutique', 'offre', 'question', 'image', 'œuvre', 'chambre', 'activité',
        'visite', 'déclinaison', 'carte', 'annonce', 'vidéo', 'référence', 'équipe', 'personne', 'news', 'bougie', 'savonnette', 'pièce'];

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function plural(int $n, string $one, ?string $many = null): string
    {
        $auto = preg_match('/(eau|eu)$/u', $one) ? $one . 'x' : (preg_match('/[sxz]$/u', $one) ? $one : $one . 's');
        return $n . ' ' . ($n > 1 ? ($many ?? $auto) : $one);
    }

    public static function article(string $noun): string
    {
        $first = mb_strtolower(explode(' ', trim($noun))[0] ?? '');
        return in_array($first, self::FEMININE, true) ? 'une' : 'un';
    }

    public static function addLabel(?string $item): string
    {
        return $item ? 'Ajouter ' . self::article($item) . ' ' . $item : 'Ajouter';
    }

    public static function listOf(string $label): string
    {
        $l = mb_strtolower($label);
        return preg_match('/^[aeiouyhâàéèêëîïôöûüœ]/u', $l) ? "Liste d'$l" : "Liste de $l";
    }

    public static function when(?string $iso): string
    {
        if (!$iso) {
            return '';
        }
        $t = strtotime($iso);
        $diff = time() - $t;
        if ($diff < 60) {
            return "à l'instant";
        }
        if ($diff < 3600) {
            return 'il y a ' . intdiv($diff, 60) . ' min';
        }
        $time = date('G \h i', $t);
        if (date('Y-m-d', $t) === date('Y-m-d')) {
            return "aujourd'hui à $time";
        }
        if (date('Y-m-d', $t) === date('Y-m-d', time() - 86400)) {
            return "hier à $time";
        }
        return self::date($iso, date('Y', $t) !== date('Y')) . " à $time";
    }

    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public static function date(?string $iso, bool $withYear = true, bool $withDay = false): string
    {
        if (!$iso) {
            return '';
        }
        $t = strtotime($iso);
        $s = (int) date('j', $t) . ' ' . self::MONTHS[(int) date('n', $t) - 1] . ($withYear ? ' ' . date('Y', $t) : '');
        return $withDay ? self::DAYS[(int) date('w', $t)] . ' ' . $s : $s;
    }

    public static function price(mixed $n): string
    {
        if ($n === null || $n === '') {
            return '';
        }
        $v = (float) $n;
        $dec = floor($v) == $v ? 0 : 2;
        return number_format($v, $dec, ',', "\u{202f}") . ' €';
    }

    public static function parseDecimal(string $text): ?float
    {
        $c = preg_replace('/[^\d,.\-]/', '', $text);
        if ($c === '' || $c === null) {
            return null;
        }
        $lastComma = strrpos($c, ',');
        $lastDot = strrpos($c, '.');
        $c = ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) ? str_replace(',', '.', str_replace('.', '', $c)) : str_replace(',', '', $c);
        return is_numeric($c) ? (float) $c : null;
    }

    public static function slugify(string $text): string
    {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: strtolower($text);
        $t = trim((string) preg_replace('/[^a-z0-9]+/', '-', $t), '-');
        return substr($t, 0, 60) ?: 'element';
    }

    public static function stripHtml(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    public static function excerpt(string $text, int $len): string
    {
        return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
    }
}
