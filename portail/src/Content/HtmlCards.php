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
        $title = $price = $desc = $img = $list = $tag = null;
        $groups = [];
        foreach (self::descendants($els, $card) as $d) {
            $e = $els[$d];
            if (($key = self::choiceKey($html, $els, $e, count($groups) + 1)) !== null && !isset($groups[$key])) {
                // Groupe de choix (« 6 à 8 pers. · 45 € / 10 à 12 pers. · 65 € », « Parvé / Halavi »…).
                $groups[$key] = $d;
                continue;
            }
            if (in_array($e['tag'], ['button', 'option', 'select', 'script', 'style', 'label', 'a'], true) || self::insideTag($els, $d, $card, ['button', 'select', 'a', 'label'])) {
                continue;
            }
            if ($title === null && preg_match('/^h[1-6]$/', $e['tag']) && self::isLeaf($els, $e) && self::text($html, $e) !== '') {
                $title = $d;
            } elseif ($price === null && self::isLeaf($els, $e) && $e['tag'] !== 'li' && (preg_match(self::PRICE, self::text($html, $e))
                || (preg_match('/price|prix|tarif|amount|montant/i', (string) self::attr($e['attrs'], 'class')) && self::text($html, $e) !== ''))) {
                // Prix : texte comme « 45 € » ou « À partir de 70 € », ou bloc nommé « price… » (« Sur devis »).
                $price = $d;
            } elseif ($desc === null && $e['tag'] === 'p' && self::isLeaf($els, $e) && mb_strlen(self::text($html, $e)) >= 15) {
                $desc = $d;
            } elseif ($tag === null && in_array($e['tag'], ['span', 'small', 'p', 'div'], true) && !$e['children'] && !in_array($e['parent'], [$title, $price, $desc], true) && preg_match('/\b[\w-]*(tag|categ|badge|type|kicker|label|eyebrow)[\w-]*\b/i', (string) self::attr($e['attrs'], 'class'))
                && ($t = self::text($html, $e)) !== '' && mb_strlen($t) <= 60) {
                // Petite étiquette (« Restaurant · ERP ») : sa catégorie.
                $tag = $d;
            } elseif ($list === null && in_array($e['tag'], ['ul', 'ol'], true) && self::isList($els, $e)) {
                // Liste à puces (« ce qui est compris ») : un point par ligne.
                $list = $d;
            } elseif ($img === null && (($e['tag'] === 'img' && self::attr($e['attrs'], 'src')) || self::bgUrl($e['attrs']) !== null)) {
                // Photo : balise <img>, ou bloc dont le fond est une image (style="background-image:url(…)").
                $img = $d;
            }
        }
        return $title !== null && ($price !== null || $img !== null || $desc !== null || $list !== null) ? ['title' => $title, 'price' => $price, 'desc' => $desc, 'img' => $img, 'list' => $list, 'tag' => $tag, 'groups' => $groups] : null;
    }

    /** Liste simple : uniquement des <li> sans sous-élément. */
    private static function isList(array $els, array $el): bool
    {
        if (!$el['children']) {
            return false;
        }
        foreach ($el['children'] as $c) {
            if ($els[$c]['tag'] !== 'li' || !self::isLeaf($els, $els[$c])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Groupe de boutons de choix (au moins deux boutons ou étiquettes de même sorte, sans sous-élément) :
     * rangé avec role="group" ou une classe qui parle d'options. Renvoie le nom du champ, ou null.
     */
    private static function choiceKey(string $html, array $els, array $e, int $n): ?string
    {
        $class = (string) self::attr($e['attrs'], 'class');
        $select = $e['tag'] === 'select'; // menu déroulant « Choisissez une taille »
        $kids = self::choiceKids($els, $e);
        if (count($kids) < 2 || !($select || self::attr($e['attrs'], 'role') === 'group' || preg_match('/opt|choi|variant|size|taille|format|parfum|saveur/i', $class))) {
            return null;
        }
        $tags = array_unique(array_map(fn ($c) => $els[$c]['tag'], $kids));
        if (count($tags) !== 1 || !in_array($tags[0], $select ? ['option'] : ['button', 'label', 'span', 'a'], true)) {
            return null;
        }
        foreach ($kids as $c) {
            if (!self::isLeaf($els, $els[$c]) || self::text($html, $els[$c]) === '') {
                return null;
            }
        }
        $name = (string) (self::attr($e['attrs'], 'aria-label') ?? ($select ? self::attr($e['attrs'], 'name') ?? self::attr($e['attrs'], 'id') ?? '' : ''));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) (@iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: ''))), '_');
        return 'choix_' . ($slug !== '' ? mb_substr($slug, 0, 40) : $n);
    }

    /** @return int[] éléments du groupe qui sont des choix (sans la ligne « Choisissez… » d'un menu déroulant) */
    private static function choiceKids(array $els, array $group): array
    {
        return array_values(array_filter($group['children'], fn ($c) => !($els[$c]['tag'] === 'option' && self::attr($els[$c]['attrs'], 'value') === '')));
    }

    /** @return string[] textes des boutons d'un groupe de choix */
    private static function choiceItems(string $html, array $els, array $group): array
    {
        return array_map(fn ($c) => str_replace(["\u{00A0}", "\u{202F}"], ' ', self::text($html, $els[$c])), self::choiceKids($els, $group));
    }

    /** Le premier choix reste celui sélectionné d'avance (si le site en marque un). */
    private static function keepFirstActive(array $first, string $part): string
    {
        if (!preg_match('/\sclass\s*=\s*"[^"]*\b(is-)?active\b/i', $first['attrs']) || preg_match('/^<[^>]*\sclass\s*=\s*"[^"]*\b(is-)?active\b/i', $part)) {
            return $part;
        }
        $cls = preg_match('/\bis-active\b/', $first['attrs']) ? 'is-active' : 'active';
        return preg_match('/^<[^>]*\sclass\s*=\s*"/i', $part) ? (string) preg_replace('/(\sclass\s*=\s*")([^"]*)"/i', '$1$2 ' . $cls . '"', $part, 1)
            : (string) preg_replace('/^<(\w+)/', '<$1 class="' . $cls . '"', $part, 1);
    }

    /**
     * Groupe de choix ajouté à une fiche qui n'en avait pas, sur le modèle du même groupe d'une autre fiche
     * (mêmes balises et classes : le site le fait fonctionner comme les autres). Aucun lien de paiement n'est recopié.
     */
    private static function newChoiceGroup(string $html, array $els, int $tplId, array $lines): string
    {
        $g = $els[$tplId];
        $kids = array_map(fn ($c) => $els[$c], self::choiceKids($els, $g));
        $old = self::choiceItems($html, $els, $g);
        $parts = [];
        foreach ($lines as $i => $l) {
            $k = min($i, count($kids) - 1);
            $parts[] = self::choiceButton($html, $kids[$k], $old[$k], $l, true);
        }
        $parts[0] = self::keepFirstActive($kids[0], $parts[0]);
        $sep = count($kids) > 1 ? substr($html, $kids[0]['end'], $kids[1]['start'] - $kids[0]['end']) : "\n";
        $placeholder = $g['children'] && $g['children'][0] !== self::choiceKids($els, $g)[0] ? substr($html, $els[$g['children'][0]]['start'], $els[$g['children'][0]]['end'] - $els[$g['children'][0]]['start']) . $sep : '';
        return substr($html, $g['start'], $g['openEnd'] - $g['start']) . $placeholder . implode($sep, $parts) . substr($html, $g['closeStart'], $g['end'] - $g['closeStart']);
    }

    /** Bouton de choix réécrit avec un nouveau texte (prix et valeurs cachées suivent). */
    private static function choiceButton(string $html, array $btn, string $old, string $new, bool $copy): string
    {
        $attrs = $btn['attrs'];
        $label = fn (string $t) => trim((string) preg_split('/\s*[·•|–—]\s*|\s+-\s+/u', $t)[0]);
        $amount = preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $new, $pm) ? str_replace(',', '.', $pm[1]) : null;
        $attrs = (string) preg_replace_callback('/(\s(?:data-([\w-]+)|value)\s*=\s*)("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($old, $new, $label, $amount, $copy) {
            $v = html_entity_decode($m[4] !== '' || $m[3][0] === '"' ? $m[4] : ($m[5] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $set = fn (string $x) => $m[1] . '"' . htmlspecialchars($x, ENT_QUOTES) . '"';
            if (preg_match('#^(https?:)?//#i', $v)) {
                // Lien de paiement : un nouveau choix ne garde pas celui du choix copié (il vendrait l'autre).
                return $copy ? $set('#') : $m[0];
            }
            if (preg_match('/price|prix|amount|montant|tarif/i', $m[2])) {
                return $amount !== null && $amount !== $v ? $set(preg_replace('/\.0+$/', '', $amount)) : $m[0];
            }
            if ($old === $new) {
                return $m[0];
            }
            if ($v === $old) {
                return $set($new);
            }
            return $label($old) !== $label($new) ? $set($label($new)) : $m[0];
        }, $attrs);
        if ($copy) {
            $attrs = (string) preg_replace_callback('/(\sclass\s*=\s*")([^"]*)"/i', fn ($m) => $m[1] . trim((string) preg_replace('/(^|\s)(is-)?(active|selected|checked|current)(?=\s|$)/', ' ', $m[2])) . '"', $attrs);
            $attrs = (string) preg_replace('/(\saria-(?:pressed|checked|selected)\s*=\s*")true"/i', '$1false"', $attrs);
        }
        $text = str_replace('&apos;', "'", htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8', false));
        return '<' . $btn['tag'] . $attrs . '>' . $text . '</' . $btn['tag'] . '>';
    }

    /** Adresse de l'image de fond écrite dans l'attribut style (la dernière url() : la première est souvent un dégradé). */
    private static function bgUrl(string $attrs): ?string
    {
        $style = self::attr($attrs, 'style');
        if ($style === null || !preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $style, $m)) {
            return null;
        }
        return trim(end($m[2]));
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
        // Seule la première classe compte : « price-card featured » est une fiche comme les autres.
        $class = preg_split('/\s+/', trim((string) self::attr($el['attrs'], 'class')))[0] ?? '';
        return $el['tag'] . '.' . $class;
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
        // Deux grands blocs côte à côte (titres h1/h2) sont des parties de page, pas une liste : leurs textes restent dans « Textes de la page ».
        $runs = array_values(array_filter($runs, fn ($r) => count($r) > 2 || !preg_match('/^h[12]$/', $els[$r[0]['fields']['title']]['tag'])));
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
            $out[$key] = ['label' => $label, 'cards' => array_map(fn ($c) => ['start' => $els[$c['id']]['start'], 'end' => $els[$c['id']]['end'], 'fields' => array_map(fn ($f) => $f === null ? null : (is_array($f) ? array_map(fn ($g) => $els[$g] + ['id' => $g], $f) : $els[$f]), $c['fields'])], $r)];
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

    /**
     * Textes de la page (titres, paragraphes, photos) qui ne font pas partie d'une liste de fiches :
     * le client peut les modifier un par un. Le menu, les boutons, les liens et les formulaires sont laissés de côté.
     * @param array<int, array{0: int, 1: int}> $exclude zones déjà couvertes par des fiches
     * @return array<string, array{el: array, kind: string, label: string}>
     */
    public static function texts(string $html, array $exclude = []): array
    {
        $els = self::elements($html);
        $out = [];
        $count = ['titre' => 0, 'texte' => 0, 'photo' => 0];
        foreach ($els as $id => $e) {
            if (count($out) >= 60) {
                break;
            }
            $isHeading = (bool) preg_match('/^h[1-4]$/', $e['tag']);
            $bg = $e['tag'] !== 'img' ? self::bgUrl($e['attrs']) : null;
            if (!$isHeading && !in_array($e['tag'], ['p', 'img', 'figcaption'], true) && $bg === null) {
                continue;
            }
            foreach ($exclude as [$a, $b]) {
                if ($e['start'] >= $a && $e['end'] <= $b) {
                    continue 2;
                }
            }
            if (self::insideTag($els, $id, -1, ['nav', 'a', 'button', 'form', 'select', 'label', 'noscript', 'template', 'svg'])) {
                continue;
            }
            if ($e['tag'] === 'img' || $bg !== null) {
                $src = $bg ?? self::attr($e['attrs'], 'src');
                if (!$src || str_starts_with($src, 'data:') || preg_match('/\.svg(\?|$)|logo|icon|favicon/i', $src)) {
                    continue;
                }
                $alt = (string) ($bg !== null ? self::attr($e['attrs'], 'aria-label') : self::attr($e['attrs'], 'alt'));
                $key = 'photo' . (++$count['photo']);
                $out[$key] = ['el' => $e, 'kind' => $bg !== null ? 'bg' : 'img', 'label' => 'Photo' . ($alt !== '' ? ' : ' . mb_strimwidth($alt, 0, 50, '…') : ' ' . $count['photo'])];
                continue;
            }
            if (!self::isLeaf($els, $e)) {
                continue;
            }
            $t = self::text($html, $e);
            if (mb_strlen($t) < 3) {
                continue;
            }
            $kind = $isHeading ? 'titre' : 'texte';
            $key = $kind . (++$count[$kind]);
            $out[$key] = ['el' => $e, 'kind' => $kind, 'label' => ($isHeading ? 'Titre' : 'Texte') . ' : « ' . mb_strimwidth($t, 0, 50, '…') . ' »'];
        }
        return $out;
    }

    public static function textValue(string $html, array $t): string
    {
        return match ($t['kind']) {
            'img' => (string) self::attr($t['el']['attrs'], 'src'),
            'bg' => (string) self::bgUrl($t['el']['attrs']),
            default => self::text($html, $t['el']),
        };
    }

    /** Remplacement d'un texte (ou de la photo) de la page : [début, fin, nouveau texte]. */
    public static function textEdit(string $html, array $t, string $value): array
    {
        $el = $t['el'];
        if ($t['kind'] === 'img') {
            $attrs = preg_replace_callback('/(\ssrc\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i', fn ($m) => $m[1] . '"' . htmlspecialchars($value, ENT_QUOTES) . '"', $el['attrs'], 1);
            return [$el['start'], $el['openEnd'], '<img' . $attrs . '>'];
        }
        if ($t['kind'] === 'bg') {
            $from = $el['start'] + 1 + strlen($el['tag']);
            $attrs = $el['attrs'];
            $old = (string) self::bgUrl($attrs);
            $pos = strrpos($attrs, $old);
            return [$from, $from + strlen($attrs), substr($attrs, 0, $pos) . htmlspecialchars($value, ENT_QUOTES) . substr($attrs, $pos + strlen($old))];
        }
        $inner = substr($html, $el['openEnd'], $el['closeStart'] - $el['openEnd']);
        $text = str_replace('&apos;', "'", htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false));
        if (str_contains($inner, '&nbsp;')) {
            $text = str_replace("\u{00A0}", '&nbsp;', $text);
        }
        return [$el['openEnd'], $el['closeStart'], nl2br($text, false)];
    }

    /** Lien vers une autre page du même site (« projet-zohar.html »), sans adresse complète. */
    public static function isLocalPage(string $href): bool
    {
        return !preg_match('#^([a-z][a-z0-9+.-]*:|//|\#|/)#i', $href) && (bool) preg_match('#\.(html?|php)([?\#].*)?$#i', $href);
    }

    /** Pages du site vers lesquelles la page renvoie. @return string[] */
    public static function pageLinks(string $html): array
    {
        preg_match_all('/<a\b[^>]*?\shref\s*=\s*(["\'])([^"\']*)\1/i', $html, $m);
        return array_values(array_unique(array_map(fn ($h) => preg_replace('/[?#].*$/', '', html_entity_decode($h)), array_filter($m[2], [self::class, 'isLocalPage']))));
    }

    /** Page de détail d'une fiche (la fiche est un lien, ou contient un lien vers une page du site). */
    public static function cardLink(string $html, array $card): ?string
    {
        $chunk = substr($html, $card['start'], $card['end'] - $card['start']);
        foreach (self::elements($chunk) as $e) {
            if ($e['tag'] === 'a' && ($h = self::attr($e['attrs'], 'href')) !== null && self::isLocalPage($h)) {
                return preg_replace('/[?#].*$/', '', $h);
            }
        }
        return null;
    }

    /** Remplace le premier lien vers $old situé après $from (celui de la fiche ajoutée). */
    public static function relink(string $html, int $from, string $old, string $new): string
    {
        if (!preg_match('/(\shref\s*=\s*["\']?)' . preg_quote($old, '/') . '(?=[?#"\'\s>])/i', $html, $m, PREG_OFFSET_CAPTURE, $from)) {
            return $html;
        }
        $at = $m[0][1] + strlen($m[1][0]);
        return substr($html, 0, $at) . $new . substr($html, $at + strlen($old));
    }

    /**
     * Nouvelle page de détail, copiée sur celle d'une fiche existante : le nom (titre principal et titre de l'onglet)
     * et la première photo sont remplacés. Les autres textes et photos se modifient ensuite dans « Textes de la page ».
     */
    public static function copyPage(string $page, string $oldName, string $newName, ?string $photo, string $desc = '', string $oldFile = '', string $newFile = ''): string
    {
        if (str_contains($page, '<?')) {
            throw new \InvalidArgumentException('Page avec du code : copie refusée.');
        }
        $edits = [];
        $texts = self::texts($page);
        $heading = null;
        foreach ($texts as $t) {
            if ($t['kind'] === 'titre' && ($heading === null || ($t['el']['tag'] === 'h1' && $heading['el']['tag'] !== 'h1'))) {
                $heading = $t;
            }
        }
        if ($heading) {
            $edits[] = self::textEdit($page, $heading, $newName);
            // Le texte d'accroche juste sous le titre reprend la description de la nouvelle fiche.
            $after = false;
            foreach ($texts as $t) {
                if ($after && $desc !== '') {
                    if ($t['kind'] === 'texte' && $t['el']['tag'] === 'p') {
                        $edits[] = self::textEdit($page, $t, $desc);
                    }
                    break;
                }
                $after = $t === $heading;
            }
        }
        if ($photo !== null && $photo !== '') {
            foreach ($texts as $t) {
                if ($t['kind'] === 'img' || $t['kind'] === 'bg') {
                    $edits[] = self::textEdit($page, $t, $photo);
                    break;
                }
            }
        }
        if (preg_match('/(<title[^>]*>)(.*?)(<\/title>)/is', $page, $m, PREG_OFFSET_CAPTURE)) {
            $title = html_entity_decode($m[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // « Ancien projet — détail | Studio » devient « Nouveau projet | Studio ».
            $new = $newName . (preg_match('/\s[|·–—-]\s[^|·–—-]+$/u', $title, $tail) ? $tail[0] : '');
            $edits[] = [$m[2][1], $m[2][1] + strlen($m[2][0]), htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8', false)];
        }
        // Fil d'Ariane (« Accueil / Projets / Ancien nom ») et adresse canonique.
        foreach (self::elements($page) as $e) {
            if ($e['tag'] === 'nav' && $oldName !== '' && preg_match('/breadcrumb|ariane/i', $e['attrs'])) {
                $inner = substr($page, $e['openEnd'], $e['closeStart'] - $e['openEnd']);
                $edits[] = [$e['openEnd'], $e['closeStart'], str_replace(htmlspecialchars($oldName, ENT_QUOTES | ENT_HTML5, 'UTF-8', false), htmlspecialchars($newName, ENT_QUOTES | ENT_HTML5, 'UTF-8', false), $inner)];
            }
            if ($e['tag'] === 'meta' && $desc !== '' && preg_match('/\bname\s*=\s*["\']description["\']/i', $e['attrs'])) {
                $attrs = preg_replace('/(\scontent\s*=\s*)("[^"]*"|\'[^\']*\')/i', '$1"' . str_replace('$', '\\$', htmlspecialchars($desc, ENT_QUOTES)) . '"', $e['attrs'], 1);
                $edits[] = [$e['start'] + 5, $e['openEnd'] - 1, $attrs];
            }
            if ($e['tag'] === 'link' && $oldFile !== '' && preg_match('/\brel\s*=\s*["\']canonical/i', $e['attrs'])) {
                $edits[] = [$e['start'] + 5, $e['openEnd'] - 1, str_replace($oldFile, $newFile, $e['attrs'])];
            }
        }
        usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($edits as [$from, $to, $text]) {
            $page = substr($page, 0, $from) . $text . substr($page, $to);
        }
        return $page;
    }

    // ------------------------------------------------------------ logo du site

    private const LOGO = '/logo|brand|monogram|marque|enseigne/i';

    /** Le logo est-il dans cet élément (ou un de ses parents proches) ? Renvoie le conteneur du logo ou null. */
    private static function logoBox(array $els, int $id): ?int
    {
        $e = $els[$id];
        $own = (string) self::attr($e['attrs'], 'class') . ' ' . (string) self::attr($e['attrs'], 'id') . ' ' . (string) self::attr($e['attrs'], 'src');
        for ($p = $e['parent'], $n = 0; $p >= 0 && $n < 3; $p = $els[$p]['parent'], $n++) {
            if (preg_match(self::LOGO, (string) self::attr($els[$p]['attrs'], 'class') . ' ' . (string) self::attr($els[$p]['attrs'], 'id'))) {
                return $p;
            }
        }
        return preg_match(self::LOGO, $own) ? ($e['parent'] >= 0 ? $e['parent'] : $id) : null;
    }

    /**
     * Logo d'une page : image (src tel qu'écrit), texte à côté du logo (« Studio »), icône de l'onglet.
     * @return array{img: ?string, text: ?string, icon: ?string}
     */
    public static function logo(string $html): array
    {
        $els = self::elements($html);
        $out = ['img' => null, 'text' => null, 'icon' => null];
        foreach ($els as $id => $e) {
            if ($out['icon'] === null && $e['tag'] === 'link' && preg_match('/\brel\s*=\s*["\']?[^"\'>]*\bicon\b/i', $e['attrs'])) {
                $out['icon'] = self::attr($e['attrs'], 'href');
            }
            if ($out['img'] === null && $e['tag'] === 'img' && self::attr($e['attrs'], 'src') && ($box = self::logoBox($els, $id)) !== null) {
                $out['img'] = self::attr($e['attrs'], 'src');
                foreach (self::descendants($els, $box) as $d) {
                    $t = $els[$d];
                    if (in_array($t['tag'], ['span', 'strong', 'b', 'em', 'small'], true) && !$t['children'] && ($text = self::text($html, $t)) !== '' && mb_strlen($text) <= 40) {
                        $out['text'] = $text;
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Remplace le logo dans une page. $same(src) dit si une adresse désigne l'ancien logo (ou l'ancienne icône),
     * $write(chemin) donne l'adresse à écrire dans cette page.
     * @param array{img?: string, text?: string, icon?: string} $new
     */
    public static function replaceLogo(string $html, array $old, array $new, callable $same, callable $write): string
    {
        $els = self::elements($html);
        $edits = [];
        $esc = fn ($v) => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
        $setAttr = function (string $attrs, string $name, string $value) use ($esc): string {
            $re = '/(\s' . $name . '\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i';
            return preg_match($re, $attrs) ? preg_replace_callback($re, fn ($m) => $m[1] . '"' . $esc($value) . '"', $attrs, 1) : $attrs;
        };
        foreach ($els as $id => $e) {
            $from = $e['start'] + 1 + strlen($e['tag']);
            $to = $e['openEnd'] - 1;
            if (isset($new['img']) && $e['tag'] === 'img' && ($src = self::attr($e['attrs'], 'src')) !== null && $same($src, 'img')) {
                $attrs = $setAttr($e['attrs'], 'src', $write($new['img']));
                // Les autres tailles de l'ancien logo afficheraient encore l'ancien.
                $attrs = preg_replace('/\ssrcset\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs);
                $edits[] = [$from, $to, $attrs];
                if (isset($new['text'], $old['text']) && ($box = self::logoBox($els, $id)) !== null) {
                    foreach (self::descendants($els, $box) as $d) {
                        $t = $els[$d];
                        if (!$t['children'] && $t['tag'] !== 'img' && self::text($html, $t) === $old['text']) {
                            $edits[] = [$t['openEnd'], $t['closeStart'], $esc($new['text'])];
                        }
                    }
                }
            }
            if (isset($new['icon']) && $e['tag'] === 'link' && preg_match('/\brel\s*=\s*["\']?[^"\'>]*\bicon\b/i', $e['attrs'])) {
                $attrs = $setAttr($e['attrs'], 'href', $write($new['icon']));
                $ext = strtolower(pathinfo(parse_url($new['icon'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                $type = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon'][$ext] ?? null;
                if ($type) {
                    $attrs = $setAttr($attrs, 'type', $type);
                }
                $edits[] = [$from, $to, $attrs];
            }
        }
        usort($edits, fn ($a, $b) => $b[0] <=> $a[0]);
        $last = PHP_INT_MAX;
        foreach ($edits as [$a, $b, $text]) {
            if ($b > $last) {
                continue; // jamais deux remplacements au même endroit
            }
            $html = substr($html, 0, $a) . $text . substr($html, $b);
            $last = $a;
        }
        return $html;
    }

    /** Titre de la page (balise title), pour nommer la rubrique de ses textes. */
    public static function pageTitle(string $html): string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    /** @return string[] textes des points d'une liste */
    private static function listItems(string $html, array $list): array
    {
        preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', substr($html, $list['openEnd'], $list['closeStart'] - $list['openEnd']), $m);
        return array_values(array_filter(array_map(fn ($t) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $m[1]), fn ($t) => $t !== ''));
    }

    /** Valeurs d'une fiche. */
    public static function values(string $html, array $card): array
    {
        $f = $card['fields'];
        $out = ['nom' => self::text($html, $f['title'])];
        if ($f['price']) {
            $out['prix'] = str_replace(["\u{00A0}", "\u{202F}"], ' ', self::text($html, $f['price']));
        }
        if ($f['desc']) {
            $out['description'] = self::text($html, $f['desc']);
        }
        if (!empty($f['tag'])) {
            $out['categorie'] = self::text($html, $f['tag']);
        }
        if (!empty($f['list'])) {
            $out['points'] = implode("\n", self::listItems($html, $f['list']));
        }
        if (!empty($f['groups'])) {
            $els = self::elements($html);
            foreach ($f['groups'] as $key => $g) {
                $out[$key] = implode("\n", self::choiceItems($html, $els, $els[$g['id']]));
            }
        }
        if ($f['img']) {
            $out['photo'] = (string) ($f['img']['tag'] === 'img' ? self::attr($f['img']['attrs'], 'src') : self::bgUrl($f['img']['attrs']));
        }
        return $out;
    }

    /** Fiche réécrite avec de nouvelles valeurs (seuls les textes, la photo et son texte alternatif changent). */
    /** @param array $siblings autres fiches du même groupe (modèle pour un groupe de choix ajouté) */
    public static function patch(string $html, array $card, array $values, bool $clone = false, array $siblings = []): string
    {
        $base = $card['start'];
        $chunk = substr($html, $base, $card['end'] - $base);
        $edits = [];
        $put = function (?array $el, ?string $value) use (&$edits, $base, $html) {
            if ($el === null || $value === null || $value === self::text($html, $el)) {
                return; // inchangé : on ne touche pas au code d'origine (mise en forme, badges…)
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
        $put($f['tag'] ?? null, array_key_exists('categorie', $values) ? (string) $values['categorie'] : null);
        if (!empty($f['list']) && array_key_exists('points', $values)) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $values['points'])), fn ($l) => $l !== ''));
            if ($lines !== self::listItems($html, $f['list'])) {
                $inner = substr($html, $f['list']['openEnd'], $f['list']['closeStart'] - $f['list']['openEnd']);
                $liOpen = preg_match('/<li\b[^>]*>/i', $inner, $m) ? $m[0] : '<li>';
                // Même disposition que l'original : tout sur une ligne, ou un point par ligne avec le même retrait.
                $sep = preg_match('/<\/li>(\s*\n[ \t]*)<li/i', $inner, $m2) ? $m2[1] : '';
                $lead = preg_match('/^\s*/', $inner, $m3) ? $m3[0] : '';
                $tail = preg_match('/\s*$/', $inner, $m4) ? $m4[0] : '';
                $items = array_map(fn ($l) => $liOpen . str_replace('&apos;', "'", htmlspecialchars($l, ENT_QUOTES | ENT_HTML5, 'UTF-8', false)) . '</li>', $lines);
                $edits[] = [$f['list']['openEnd'] - $base, $f['list']['closeStart'] - $base, $lead . implode($sep, $items) . $tail];
            }
        }
        $els = null;
        foreach ($f['groups'] ?? [] as $key => $g) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $els ??= self::elements($html);
            $kids = array_map(fn ($c) => $els[$c], self::choiceKids($els, $els[$g['id']]));
            $old = self::choiceItems($html, $els, $els[$g['id']]);
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $values[$key])), fn ($l) => $l !== ''));
            if ($lines === $old || !$lines) {
                continue; // inchangé, ou vidé par erreur : un groupe de choix ne disparaît pas d'un coup
            }
            // Chaque ligne garde son bouton d'origine (lien de paiement compris) ; une ligne modifiée à la même place
            // reprend le bouton de cette place ; une ligne nouvelle copie le dernier bouton, sans son lien de paiement.
            $free = array_keys($old);
            $plan = [];
            foreach ($lines as $i => $l) {
                $k = array_search($l, array_intersect_key($old, array_flip($free)), true);
                if ($k !== false) {
                    $plan[$i] = [$k, false];
                    $free = array_values(array_diff($free, [$k]));
                }
            }
            foreach ($lines as $i => $l) {
                if (!isset($plan[$i])) {
                    $k = in_array($i, $free, true) && !in_array($old[$i], $lines, true) ? $i : null;
                    $plan[$i] = $k === null ? [count($kids) - 1, true] : [$k, false];
                    if ($k !== null) {
                        $free = array_values(array_diff($free, [$k]));
                    }
                }
            }
            ksort($plan);
            $parts = [];
            foreach ($plan as $i => [$k, $copy]) {
                $btn = $kids[$k];
                $parts[] = !$copy && $lines[$i] === $old[$k] ? substr($html, $btn['start'], $btn['end'] - $btn['start'])
                    : self::choiceButton($html, $btn, $old[$k], $lines[$i], $copy);
            }
            $parts[0] = self::keepFirstActive($kids[0], $parts[0]);
            $sep = count($kids) > 1 ? substr($html, $kids[0]['end'], $kids[1]['start'] - $kids[0]['end']) : "\n";
            $edits[] = [$kids[0]['start'] - $base, end($kids)['end'] - $base, implode($sep, $parts)];
        }
        // Choix demandés sur une fiche qui n'en a pas : groupe recopié d'une autre fiche, au même endroit qu'elle.
        foreach ($values as $key => $v) {
            $lines = str_starts_with((string) $key, 'choix_') && !isset($f['groups'][$key])
                ? array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $v)), fn ($l) => $l !== '')) : [];
            $tpl = $tplCard = null;
            foreach ($lines ? $siblings : [] as $sib) {
                if (isset($sib['fields']['groups'][$key])) {
                    $tpl = $sib['fields']['groups'][$key]['id'];
                    $tplCard = $sib;
                    break;
                }
            }
            if ($tpl === null) {
                continue;
            }
            $els ??= self::elements($html);
            $group = self::newChoiceGroup($html, $els, $tpl, $lines);
            $box = $els[$tpl]['parent'];
            $sig = self::signature($els[$box]);
            $at = null;
            // Le conteneur des choix (« cake-options ») existe déjà dans la fiche : le groupe y est ajouté à la fin.
            foreach (self::descendants($els, (int) array_key_first(array_filter($els, fn ($e) => $e['start'] === $card['start']))) as $d) {
                if (self::signature($els[$d]) === $sig && $sig !== 'div.') {
                    $at = $els[$d]['closeStart'];
                    break;
                }
            }
            if ($at === null) {
                // Sinon juste après la description (ou le prix, ou le nom), avec son conteneur s'il en a un.
                $after = $f['desc'] ?? $f['price'] ?? $f['title'];
                $at = $after['end'];
                if ($els[$box]['start'] !== $tplCard['start']) {
                    $group = substr($html, $els[$box]['start'], $els[$box]['openEnd'] - $els[$box]['start']) . $group . substr($html, $els[$box]['closeStart'], $els[$box]['end'] - $els[$box]['closeStart']);
                }
            }
            $edits[] = [$at - $base, $at - $base, $group];
        }
        if ($f['img'] && $f['img']['tag'] !== 'img') {
            $attrs = $f['img']['attrs'];
            if ($clone && isset($values['nom'])) {
                // Description de la photo pour les lecteurs d'écran : celle de la fiche copiée ne convient plus.
                $attrs = preg_replace('/(\saria-label\s*=\s*)("[^"]*"|\'[^\']*\')/i', '$1"' . str_replace('$', '\\$', htmlspecialchars((string) $values['nom'], ENT_QUOTES)) . '"', $attrs, 1);
            }
            if (array_key_exists('photo', $values) && ($old = self::bgUrl($attrs)) !== null && $old !== (string) $values['photo']) {
                $pos = strrpos($attrs, $old);
                $attrs = substr($attrs, 0, $pos) . htmlspecialchars((string) $values['photo'], ENT_QUOTES) . substr($attrs, $pos + strlen($old));
            }
            if ($attrs !== $f['img']['attrs']) {
                $edits[] = [$f['img']['start'] - $base + 1 + strlen($f['img']['tag']), $f['img']['openEnd'] - $base - 1, $attrs];
            }
        } elseif ($f['img'] && array_key_exists('photo', $values)) {
            $attrs = $f['img']['attrs'];
            $newAttrs = preg_replace_callback('/(\ssrc\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i', fn ($m) => $m[1] . '"' . htmlspecialchars((string) $values['photo'], ENT_QUOTES) . '"', $attrs, 1);
            $tagStart = $f['img']['start'] - $base;
            $edits[] = [$tagStart, $f['img']['openEnd'] - $base, '<img' . $newAttrs . '>'];
        } elseif ($f['img'] && isset($values['nom']) && $clone) {
            $edits[] = [$f['img']['start'] - $base, $f['img']['openEnd'] - $base, '<img' . $f['img']['attrs'] . '>'];
        }
        // Le texte alternatif de la photo suit le nom du produit.
        if ($f['img'] && $f['img']['tag'] === 'img' && isset($values['nom'])) {
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
