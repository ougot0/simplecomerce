<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use SimpleCommerce\Support\Text;

/**
 * Sites faits main dont les produits sont écrits directement dans les pages HTML :
 * on repère les fiches produits (blocs répétés qui ont un titre et un prix), on lit nom, prix, description, photo,
 * et on réécrit seulement ces morceaux de texte dans la page. Le reste de la page n'est jamais touché.
 * Ajouter un produit = copier la dernière fiche et y mettre les nouvelles valeurs.
 */
final class HtmlCards
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    private const RAW = ['script', 'style', 'textarea', 'title'];
    private const PRICE = '/^\s*(?:(?:dès|à partir de|from)\s+)?(?:\d[\d\s\x{00A0}\x{202F}.]*(?:[.,]\d{1,2})?\s*(?:€|eur|euros?)|€\s*\d[\d\s\x{00A0}.]*(?:[.,]\d{1,2})?)\s*(?:\/\s*\S+)?\s*$/iu';

    /** @return array<int, array{tag: string, attrs: string, start: int, openEnd: int, closeStart: int, end: int, parent: int, children: int[]}> */
    public static function elements(string $html): array
    {
        $els = [];
        $stack = [-1];
        $n = strlen($html);
        $i = 0;
        while ($i < $n) {
            $lt = strpos($html, '<', $i);
            if ($lt === false) {
                break;
            }
            if (substr($html, $lt, 4) === '<!--') {
                $e = strpos($html, '-->', $lt + 4);
                $i = $e === false ? $n : $e + 3;
                continue;
            }
            if (!preg_match('/\G<(\/?)([a-zA-Z][\w:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/A', $html, $m, 0, $lt)) {
                $i = $lt + 1;
                continue;
            }
            $close = $m[1] === '/';
            $tag = strtolower($m[2]);
            $tagEnd = $lt + strlen($m[0]);
            if ($close) {
                // Ferme l'élément ouvert du même nom le plus proche (HTML tolérant).
                for ($k = count($stack) - 1; $k > 0; $k--) {
                    if ($els[$stack[$k]]['tag'] === $tag) {
                        for ($j = count($stack) - 1; $j >= $k; $j--) {
                            $id = array_pop($stack);
                            $els[$id]['closeStart'] = $j === $k ? $lt : $lt;
                            $els[$id]['end'] = $j === $k ? $tagEnd : $lt;
                        }
                        break;
                    }
                }
                $i = $tagEnd;
                continue;
            }
            $id = count($els);
            $parent = end($stack);
            $els[$id] = ['tag' => $tag, 'attrs' => $m[3], 'start' => $lt, 'openEnd' => $tagEnd, 'closeStart' => $tagEnd, 'end' => $tagEnd, 'parent' => $parent, 'children' => []];
            if ($parent >= 0) {
                $els[$parent]['children'][] = $id;
            }
            $selfClosing = str_ends_with(rtrim($m[3]), '/');
            if (in_array($tag, self::RAW, true)) {
                $endTag = stripos($html, "</$tag", $tagEnd);
                $endTag = $endTag === false ? $n : $endTag;
                $gt = strpos($html, '>', $endTag);
                $els[$id]['closeStart'] = $endTag;
                $els[$id]['end'] = $gt === false ? $n : $gt + 1;
                $i = $els[$id]['end'];
                continue;
            }
            if (!in_array($tag, self::VOID, true) && !$selfClosing) {
                $stack[] = $id;
            }
            $i = $tagEnd;
        }
        return $els;
    }

    private static function attr(string $attrs, string $name): ?string
    {
        if (!preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m)) {
            return null;
        }
        $v = $m[1][0] === '"' ? $m[2] : ($m[1][0] === "'" ? $m[3] : $m[4]);
        return html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function text(string $html, array $el): string
    {
        $inner = substr($html, $el['openEnd'], $el['closeStart'] - $el['openEnd']);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Élément sans sous-élément (hors mise en forme simple) : son texte peut être remplacé sans risque. */
    private static function isLeaf(array $els, array $el): bool
    {
        foreach ($el['children'] as $c) {
            if (!in_array($els[$c]['tag'], ['br', 'strong', 'em', 'b', 'i', 'span', 'small'], true) || $els[$c]['children']) {
                return false;
            }
        }
        return true;
    }

    /** @return int[] tous les descendants, dans l'ordre du document */
    private static function descendants(array $els, int $id): array
    {
        $out = [];
        foreach ($els[$id]['children'] as $c) {
            $out[] = $c;
            array_push($out, ...self::descendants($els, $c));
        }
        return $out;
    }

    /** Champs d'une fiche : identifiants des éléments titre, prix, description, image. */
    private static function cardFields(string $html, array $els, int $card): ?array
    {
        $title = $price = $desc = $img = null;
        foreach (self::descendants($els, $card) as $d) {
            $e = $els[$d];
            if (in_array($e['tag'], ['button', 'option', 'select', 'script', 'style', 'label', 'a'], true) || self::insideTag($els, $d, $card, ['button', 'select', 'a', 'label'])) {
                continue;
            }
            if ($title === null && preg_match('/^h[1-6]$/', $e['tag']) && self::isLeaf($els, $e) && self::text($html, $e) !== '') {
                $title = $d;
            } elseif ($price === null && self::isLeaf($els, $e) && preg_match(self::PRICE, self::text($html, $e))) {
                $price = $d;
            } elseif ($desc === null && $e['tag'] === 'p' && self::isLeaf($els, $e) && mb_strlen(self::text($html, $e)) >= 15) {
                $desc = $d;
            } elseif ($img === null && $e['tag'] === 'img' && self::attr($e['attrs'], 'src')) {
                $img = $d;
            }
        }
        return $title !== null && $price !== null ? ['title' => $title, 'price' => $price, 'desc' => $desc, 'img' => $img] : null;
    }

    private static function insideTag(array $els, int $id, int $stop, array $tags): bool
    {
        for ($p = $els[$id]['parent']; $p >= 0 && $p !== $stop; $p = $els[$p]['parent']) {
            if (in_array($els[$p]['tag'], $tags, true)) {
                return true;
            }
        }
        return false;
    }

    private static function signature(array $el): string
    {
        return $el['tag'] . '.' . (self::attr($el['attrs'], 'class') ?? '');
    }

    /**
     * Groupes de fiches produits de la page.
     * @return array<string, array{label: string, cards: array<int, array{start: int, end: int, fields: array}>}>
     */
    public static function find(string $html): array
    {
        $els = self::elements($html);
        $runs = [];
        foreach ($els as $id => $el) {
            if (count($el['children']) < 2) {
                continue;
            }
            $run = [];
            $flush = function () use (&$run, &$runs) {
                if (count($run) >= 2) {
                    $runs[] = $run;
                }
                $run = [];
            };
            $sig = null;
            foreach ($el['children'] as $c) {
                $fields = self::cardFields($html, $els, $c);
                if ($fields && ($sig === null || self::signature($els[$c]) === $sig)) {
                    $sig = self::signature($els[$c]);
                    $run[] = ['id' => $c, 'fields' => $fields];
                } else {
                    $flush();
                    $sig = null;
                    if ($fields) {
                        $sig = self::signature($els[$c]);
                        $run[] = ['id' => $c, 'fields' => $fields];
                    }
                }
            }
            $flush();
        }
        // On garde les fiches les plus « petites » : un groupe dont une fiche contient un autre groupe est écarté.
        $all = [];
        foreach ($runs as $r) {
            foreach ($r as $c) {
                $all[] = $c['id'];
            }
        }
        $out = [];
        $used = [];
        foreach ($runs as $r) {
            $nested = false;
            foreach ($r as $c) {
                foreach (self::descendants($els, $c['id']) as $d) {
                    if (in_array($d, $all, true)) {
                        $nested = true;
                        break 2;
                    }
                }
            }
            if ($nested) {
                continue;
            }
            $first = $els[$r[0]['id']];
            $label = self::labelBefore($html, $first['start']);
            $key = Text::slugify($label) ?: 'produits';
            $base = $key;
            for ($n = 2; isset($used[$key]); $n++) {
                $key = "$base-$n";
            }
            $used[$key] = true;
            $out[$key] = ['label' => $label, 'cards' => array_map(fn ($c) => ['start' => $els[$c['id']]['start'], 'end' => $els[$c['id']]['end'], 'fields' => array_map(fn ($f) => $f === null ? null : $els[$f], $c['fields'])], $r)];
        }
        return $out;
    }

    /** Titre de la partie de page où se trouvent les fiches (dernier h1/h2 avant elles), pour nommer la rubrique. */
    private static function labelBefore(string $html, int $pos): string
    {
        if (preg_match_all('/<h[12][^>]*>(.*?)<\/h[12]>/is', substr($html, 0, $pos), $m) && $m[1]) {
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(end($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($t !== '' && mb_strlen($t) <= 60) {
                return $t;
            }
        }
        return 'Produits';
    }

    /** Valeurs d'une fiche. */
    public static function values(string $html, array $card): array
    {
        $f = $card['fields'];
        $out = ['nom' => self::text($html, $f['title']), 'prix' => str_replace(["\u{00A0}", "\u{202F}"], ' ', self::text($html, $f['price']))];
        if ($f['desc']) {
            $out['description'] = self::text($html, $f['desc']);
        }
        if ($f['img']) {
            $out['photo'] = (string) self::attr($f['img']['attrs'], 'src');
        }
        return $out;
    }

    /** Fiche réécrite avec de nouvelles valeurs (seuls les textes, la photo et son texte alternatif changent). */
    public static function patch(string $html, array $card, array $values, bool $clone = false): string
    {
        $base = $card['start'];
        $chunk = substr($html, $base, $card['end'] - $base);
        $edits = [];
        $put = function (?array $el, ?string $value) use (&$edits, $base, $html) {
            if ($el === null || $value === null) {
                return;
            }
            $inner = substr($html, $el['openEnd'], $el['closeStart'] - $el['openEnd']);
            $text = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
            $text = str_replace('&apos;', "'", $text);
            if (str_contains($inner, '&nbsp;')) {
                // Le site sépare le prix et « € » par un espace insécable : on garde la même écriture.
                $text = preg_replace('/(\d) (€|eur)/iu', '$1&nbsp;$2', str_replace("\u{00A0}", '&nbsp;', $text));
            }
            $edits[] = [$el['openEnd'] - $base, $el['closeStart'] - $base, $text];
        };
        $f = $card['fields'];
        $put($f['title'], array_key_exists('nom', $values) ? (string) $values['nom'] : null);
        $put($f['price'], array_key_exists('prix', $values) ? (string) $values['prix'] : null);
        $put($f['desc'], array_key_exists('description', $values) ? (string) $values['description'] : null);
        if ($f['img'] && array_key_exists('photo', $values)) {
            $attrs = $f['img']['attrs'];
            $newAttrs = preg_replace_callback('/(\ssrc\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i', fn ($m) => $m[1] . '"' . htmlspecialchars((string) $values['photo'], ENT_QUOTES) . '"', $attrs, 1);
            $tagStart = $f['img']['start'] - $base;
            $edits[] = [$tagStart, $f['img']['openEnd'] - $base, '<img' . $newAttrs . '>'];
        } elseif ($f['img'] && isset($values['nom']) && $clone) {
            $edits[] = [$f['img']['start'] - $base, $f['img']['openEnd'] - $base, '<img' . $f['img']['attrs'] . '>'];
        }
        // Le texte alternatif de la photo suit le nom du produit.
        if ($f['img'] && isset($values['nom'])) {
            foreach ($edits as &$e) {
                if ($e[0] === $f['img']['start'] - $base) {
                    $e[2] = preg_replace_callback('/(\salt\s*=\s*)("[^"]*"|\'[^\']*\')/i', fn ($m) => $m[1] . '"' . htmlspecialchars((string) $values['nom'], ENT_QUOTES) . '"', $e[2], 1);
                }
            }
            unset($e);
        }
        usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($edits as [$from, $to, $text]) {
            $chunk = substr($chunk, 0, $from) . $text . substr($chunk, $to);
        }
        if ($clone) {
            // Une copie ne garde pas les liens de paiement de la fiche d'origine : ils vendraient l'autre produit.
            $chunk = preg_replace('#(=\s*["\'])https?://(?:buy\.stripe\.com|checkout\.stripe\.com|www\.paypal\.com|paypal\.me|[\w.-]*sumup\.com)[^"\']*#i', '$1#', $chunk);
        }
        return $chunk;
    }
}
