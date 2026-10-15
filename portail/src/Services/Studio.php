<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Repo;
use SimpleCommerce\Support\Session;

/**
 * « Studio » du propriétaire du portail (/studio) : nom affiché, couleurs, options et message pour tous les clients.
 * Les changements sont d'abord un brouillon, visible seulement par l'administrateur en mode aperçu ;
 * « Publier pour tous » les met en ligne pour tout le monde.
 */
final class Studio
{
    public const COLORS = [
        'bg' => ['Fond des pages', '#f4efe6'],
        'surface' => ['Cartes et formulaires', '#ffffff'],
        'beige' => ['Couleur douce (étiquettes, survols)', '#ebe3d5'],
        'ink' => ['Couleur principale (menu, boutons, textes)', '#141210'],
    ];

    private static ?array $current = null;

    public static function defaults(): array
    {
        return ['name' => 'Simple Commerce', 'colors' => array_map(fn ($c) => $c[1], self::COLORS), 'off' => [], 'notice' => ''];
    }

    private static function read(string $key): array
    {
        $v = json_decode((string) Repo::meta($key, ''), true);
        $d = self::defaults();
        if (!is_array($v)) {
            // Avant le Studio, les options éteintes étaient gardées à part.
            $off = json_decode((string) Repo::meta('features_off', '[]'), true);
            return ['off' => is_array($off) ? $off : []] + $d;
        }
        $v += $d;
        $v['colors'] = array_intersect_key((array) $v['colors'] + $d['colors'], $d['colors']);
        return $v;
    }

    public static function live(): array
    {
        return self::read('studio_live');
    }

    public static function draft(): array
    {
        return Repo::meta('studio_draft', '') !== '' ? self::read('studio_draft') : self::live();
    }

    public static function hasDraft(): bool
    {
        return Repo::meta('studio_draft', '') !== '' && self::draft() != self::live();
    }

    /** Réglages en vigueur pour cette page : le brouillon si l'administrateur est en mode aperçu. */
    public static function current(): array
    {
        if (self::$current === null) {
            $preview = false;
            try {
                $preview = (bool) Session::get('studio_apercu');
            } catch (\Throwable) {
            }
            self::$current = $preview ? self::draft() : self::live();
        }
        return self::$current;
    }

    public static function name(): string
    {
        return (string) (self::current()['name'] ?: 'Simple Commerce');
    }

    public static function saveDraft(array $s): void
    {
        Repo::setMeta('studio_draft', json_encode($s + self::defaults(), JSON_UNESCAPED_UNICODE));
        self::$current = null;
    }

    public static function publish(): void
    {
        $d = self::draft();
        Repo::setMeta('studio_live', json_encode($d, JSON_UNESCAPED_UNICODE));
        Repo::setMeta('features_off', json_encode(array_values($d['off'])));
        Repo::setMeta('studio_draft', '');
        self::$current = null;
    }

    public static function discard(): void
    {
        Repo::setMeta('studio_draft', '');
        self::$current = null;
    }

    /** Options éteintes tout de suite (page Administration → Options du portail) : en ligne et dans le brouillon. */
    public static function setOff(array $off): void
    {
        foreach (['studio_live', 'studio_draft'] as $k) {
            if ($k === 'studio_draft' && Repo::meta($k, '') === '') {
                continue;
            }
            $v = self::read($k);
            $v['off'] = array_values($off);
            Repo::setMeta($k, json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        Repo::setMeta('features_off', json_encode(array_values($off)));
        self::$current = null;
    }

    /** Feuille de style des couleurs choisies (chargée après app.css). */
    public static function css(): string
    {
        $c = self::current()['colors'];
        $mix = fn (string $hex, float $t) => self::mix($hex, $c['bg'], $t);
        return ":root{--bg:{$c['bg']};--surface:{$c['surface']};--surface-2:" . $mix($c['surface'], .5) . ";--beige:{$c['beige']};"
            . "--beige-strong:" . self::mix($c['beige'], $c['ink'], .12) . ";--line:" . self::mix($c['beige'], $c['bg'], .45) . ";"
            . "--line-strong:" . self::mix($c['beige'], $c['ink'], .18) . ";--ink:{$c['ink']};--focus:{$c['ink']};"
            . "--ink-2:" . self::mix($c['ink'], $c['bg'], .28) . ";--ink-3:" . self::mix($c['ink'], $c['bg'], .5) . ";}\n";
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $pa = sscanf(ltrim($a, '#'), '%02x%02x%02x');
        $pb = sscanf(ltrim($b, '#'), '%02x%02x%02x');
        $o = [];
        for ($i = 0; $i < 3; $i++) {
            $o[] = (int) round(($pa[$i] ?? 0) * (1 - $t) + ($pb[$i] ?? 0) * $t);
        }
        return vsprintf('#%02x%02x%02x', $o);
    }

    public static function version(): string
    {
        return substr(md5(json_encode(self::current())), 0, 8);
    }
}
