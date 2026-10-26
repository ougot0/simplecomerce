<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

/**
 * Choix proposés sur un produit du catalogue hébergé : tailles, formats, parfums… (« un » : le client en prend un)
 * et boîtes à composer (« plusieurs » : le client répartit un nombre donné d'articles entre les choix).
 * Chaque choix s'écrit sur une ligne, avec son prix s'il change le prix :
 *   « 40 cm · 55 € »  remplace le prix du produit ;   « Bougie · +2 € »  s'ajoute au prix.
 * Le prix d'une commande est toujours recalculé ici, jamais repris du navigateur du client.
 */
final class ProductOptions
{
    public const MAX_GROUPS = 10;
    public const MAX_CHOICES = 40;
    public const MAX_BOX = 100;

    /** « Libellé · 45 € » → [libellé, prix, ajout ?] */
    public static function parseLine(string $line): array
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{202F}"], ' ', $line)));
        if (preg_match('/^(.*?)[\s·•|:–—-]*(\+)?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)$/iu', $line, $m) && trim($m[1]) !== '') {
            return ['nom' => mb_substr(trim($m[1], " ·•|:–—-"), 0, 80), 'prix' => (float) str_replace(',', '.', $m[3]), 'ajout' => $m[2] === '+'];
        }
        return ['nom' => mb_substr($line, 0, 80), 'prix' => null, 'ajout' => false];
    }

    /** Groupes de choix d'un produit, nettoyés. @return array<int, array{nom: string, type: string, nombre: int, choix: array}> */
    public static function groups(array $product): array
    {
        $out = [];
        foreach (array_slice(array_values(array_filter((array) ($product['options'] ?? []), 'is_array')), 0, self::MAX_GROUPS) as $g) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($g['choix'] ?? ''))), fn ($l) => $l !== ''));
            $choices = array_map([self::class, 'parseLine'], array_slice($lines, 0, self::MAX_CHOICES));
            if (!$choices) {
                continue;
            }
            $box = ($g['type'] ?? '') === 'plusieurs';
            $out[] = [
                'nom' => mb_substr(trim((string) ($g['nom'] ?? '')), 0, 40) ?: 'Choix',
                'type' => $box ? 'plusieurs' : 'un',
                'nombre' => $box ? max(1, min(self::MAX_BOX, (int) ($g['nombre'] ?? 1))) : 1,
                'choix' => $choices,
            ];
        }
        return $out;
    }

    /** Détails du produit (« Dimensions : 20 × 30 cm »), un par ligne. @return string[] */
    public static function details(array $product): array
    {
        return array_slice(array_values(array_filter(array_map(fn ($l) => mb_substr(trim($l), 0, 120), preg_split('/\R/u', (string) ($product['details'] ?? ''))), fn ($l) => $l !== '')), 0, 20);
    }

    /**
     * Prix unitaire et description des choix faits par le client.
     * $picked : pour chaque groupe (par son rang), le rang du choix (« un ») ou le nombre pris de chaque choix (« plusieurs »).
     * @return array{ok: bool, prix?: ?float, detail?: string, choix?: array, error?: string}
     */
    public static function resolve(array $product, array $picked): array
    {
        $price = is_numeric($product['prix'] ?? null) ? (float) $product['prix'] : null;
        $name = (string) ($product['nom'] ?? 'ce produit');
        $parts = [];
        $clean = [];
        foreach (self::groups($product) as $gi => $g) {
            $p = $picked[$gi] ?? $picked[(string) $gi] ?? null;
            if ($g['type'] === 'un') {
                $i = is_numeric($p) ? (int) $p : 0;
                $c = $g['choix'][$i] ?? null;
                if ($c === null) {
                    return ['ok' => false, 'error' => "Choisissez « {$g['nom']} » pour « $name »."];
                }
                if ($c['prix'] !== null) {
                    $price = $c['ajout'] ? ($price ?? 0) + $c['prix'] : $c['prix'];
                }
                $parts[] = $g['nom'] . ' : ' . $c['nom'];
                $clean[$gi] = $i;
                continue;
            }
            $counts = array_map(fn ($n) => max(0, min(self::MAX_BOX, (int) $n)), array_slice(is_array($p) ? array_values($p) : [], 0, count($g['choix'])));
            if (array_sum($counts) !== $g['nombre']) {
                return ['ok' => false, 'error' => "Pour « $name », choisissez {$g['nombre']} « {$g['nom']} » (" . array_sum($counts) . ' choisis).'];
            }
            $taken = [];
            foreach ($counts as $ci => $n) {
                if ($n > 0) {
                    $c = $g['choix'][$ci];
                    if ($c['prix'] !== null) {
                        $price = ($price ?? 0) + $c['prix'] * $n; // dans une boîte, un prix s'ajoute pour chaque article
                    }
                    $taken[] = "$n × {$c['nom']}";
                }
            }
            $parts[] = $g['nom'] . ' : ' . implode(', ', $taken);
            $clean[$gi] = $counts;
        }
        return ['ok' => true, 'prix' => $price === null ? null : round($price, 2), 'detail' => implode(' · ', $parts), 'choix' => $clean];
    }
}
