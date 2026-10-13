<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

/**
 * Onglet ajouté au menu d'un site fait de pages HTML (ex. « Rendez-vous »), et page créée pour lui.
 * Changements limités au strict nécessaire : l'onglet ajouté porte data-sc-onglet="…" et se retire sans trace.
 */
final class HtmlTab
{
    public const GENERATOR = 'Simple Commerce (page ajoutée)';

    public static function isOurs(string $html): bool
    {
        return str_contains($html, self::GENERATOR);
    }

    /** Retire l'onglet $id de toutes les barres de menu de la page. */
    public static function remove(string $html, string $id): string
    {
        $q = preg_quote($id, '#');
        $html = (string) preg_replace('#[ \t]*<li\b[^>]*\bdata-sc-onglet="' . $q . '"[^>]*>.*?</li>[ \t]*\r?\n?#s', '', $html);
        return (string) preg_replace('#[ \t]*<a\b[^>]*\bdata-sc-onglet="' . $q . '"[^>]*>.*?</a>[ \t]*\r?\n?#s', '', $html);
    }

    /**
     * Ajoute l'onglet dans chaque menu principal de la page (en-tête et menus <nav>, jamais le bas de page),
     * juste avant « Contact » s'il existe, sinon à la fin. @return array{html: string, added: int}
     */
    public static function add(string $html, string $id, string $href, string $label): array
    {
        $html = self::remove($html, $id);
        $footer = [];
        if (preg_match_all('#<footer\b.*?</footer>#is', $html, $fm, PREG_OFFSET_CAPTURE)) {
            foreach ($fm[0] as $f) {
                $footer[] = [$f[1], $f[1] + strlen($f[0])];
            }
        }
        $zones = [];
        if (preg_match_all('#<(header|nav)\b.*?</\1>#is', $html, $zm, PREG_OFFSET_CAPTURE)) {
            foreach ($zm[0] as $z) {
                $zones[] = [$z[1], $z[1] + strlen($z[0])];
            }
        }
        $inZone = function (int $a, int $b) use ($zones, $footer): bool {
            foreach ($footer as [$x, $y]) {
                if ($a >= $x && $b <= $y) {
                    return false;
                }
            }
            foreach ($zones as [$x, $y]) {
                if ($a >= $x && $b <= $y) {
                    return true;
                }
            }
            return false;
        };
        $edits = [];
        // Menus en liste : <ul><li><a href="page.html">…</a></li>…</ul> (liste la plus intérieure).
        if (preg_match_all('#<ul\b[^>]*>(?:(?!<ul\b).)*?</ul>#is', $html, $um, PREG_OFFSET_CAPTURE)) {
            foreach ($um[0] as [$ul, $pos]) {
                if (!$inZone($pos, $pos + strlen($ul)) || !preg_match_all('#([ \t]*)(<li\b[^>]*>\s*<a\b[^>]*href="[^"]*"[^>]*>.*?</a>\s*</li>)#is', $ul, $li, PREG_OFFSET_CAPTURE)
                    || count($li[0]) < 3) {
                    continue;
                }
                $model = self::clean(self::plainest(array_column($li[2], 0)));
                $new = preg_replace('#^<li\b#i', '<li data-sc-onglet="' . $id . '"', $model);
                $new = self::retarget($new, $href, $label);
                [$at, $indent] = self::spot($li, $ul);
                $edits[] = [$pos + $at, $indent . $new . "\n"];
            }
        }
        // Menus sans liste : <nav><a>…</a><a>…</a></nav>.
        if (!$edits && preg_match_all('#<nav\b[^>]*>(.*?)</nav>#is', $html, $nm, PREG_OFFSET_CAPTURE)) {
            foreach ($nm[0] as $i => [$nav, $pos]) {
                if (!$inZone($pos, $pos + strlen($nav)) || stripos($nav, '<li') !== false
                    || !preg_match_all('#([ \t]*)(<a\b[^>]*href="[^"]*"[^>]*>.*?</a>)#is', $nav, $a, PREG_OFFSET_CAPTURE) || count($a[0]) < 2) {
                    continue;
                }
                $new = preg_replace('#^<a\b#i', '<a data-sc-onglet="' . $id . '"', self::clean(self::plainest(array_column($a[2], 0))));
                $new = self::retarget($new, $href, $label);
                [$at, $indent] = self::spot($a, $nav);
                $edits[] = [$pos + $at, $indent . $new . "\n"];
            }
        }
        usort($edits, fn ($x, $y) => $y[0] <=> $x[0]);
        foreach ($edits as [$at, $text]) {
            $html = substr($html, 0, $at) . $text . substr($html, $at);
        }
        return ['html' => $html, 'added' => count($edits)];
    }

    /** Où insérer : devant le lien « Contact », sinon après le dernier élément. @return array{0: int, 1: string} */
    private static function spot(array $m, string $block): array
    {
        $n = count($m[0]);
        foreach ($m[2] as $k => [$item, $off]) {
            if ($k > 0 && preg_match('#href="[^"]*contact[^"]*"|>\s*contact\s*<#i', $item)) {
                return [$m[0][$k][1], $m[1][$k][0]];
            }
        }
        $last = $m[0][$n - 1];
        $end = $last[1] + strlen($last[0]);
        $rest = substr($block, $end);
        $nl = preg_match('/^[ \t]*\r?\n/', $rest, $w) ? strlen($w[0]) : 0;
        return [$end + $nl, $m[1][$n - 1][0] !== '' ? $m[1][$n - 1][0] : ''];
    }

    /** L'élément du menu le plus simple (sans classe de préférence), pour que l'onglet ajouté ressemble aux autres. */
    private static function plainest(array $items): string
    {
        foreach ($items as $it) {
            if (!preg_match('#\sclass="[^"]*\S[^"]*"#i', $it)) {
                return $it;
            }
        }
        return $items[0];
    }

    /** Un élément de menu « neutre » : sans marque de page en cours ni d'identifiant. */
    private static function clean(string $item): string
    {
        $item = (string) preg_replace('#\s(aria-current|id)="[^"]*"#i', '', $item);
        return (string) preg_replace_callback('#\sclass="([^"]*)"#i', function ($m) {
            $cls = array_filter(preg_split('/\s+/', $m[1]), fn ($c) => $c !== '' && !preg_match('/^(is-)?(active|current|selected)$|cta|btn|button/i', $c));
            return $cls ? ' class="' . implode(' ', $cls) . '"' : '';
        }, $item);
    }

    private static function retarget(string $item, string $href, string $label): string
    {
        $item = (string) preg_replace('#(<a\b[^>]*\bhref=")[^"]*(")#i', '${1}' . htmlspecialchars($href, ENT_QUOTES) . '${2}', $item, 1);
        return (string) preg_replace('#(<a\b[^>]*>).*?(</a>)#is', '${1}' . htmlspecialchars($label, ENT_QUOTES) . '${2}', $item, 1);
    }

    /**
     * Nouvelle page à partir d'une page du site (même en-tête, même menu, même bas de page) :
     * seul le contenu principal est remplacé par $body.
     */
    public static function page(string $template, string $title, string $body, string $fileName, string $templateName): string
    {
        $html = $template;
        // Menu rempli par le site lui-même au chargement (ex. onglets fabriqués à partir des produits) : le contenu
        // d'origine reste dans la page, caché, pour que le site fabrique ses onglets ; un clic sur l'un d'eux ramène
        // à la page d'origine.
        $jsMenu = (bool) preg_match('#<nav\b[^>]*>(?:(?!</nav>).)*<(div|ul|span)\b[^>]*>\s*(?:<!--(?:(?!-->).)*-->\s*)?</\1>#is', $template);
        $fill = function (string $original) use ($body, $jsMenu, $templateName): string {
            if (!$jsMenu) {
                return "\n" . $body . "\n";
            }
            $anchor = preg_match('#\sid="(collection|boutique|produits|shop|catalogue|products)"#i', $original, $a) ? '#' . $a[1] : '';
            $go = json_encode($templateName . $anchor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
            return "\n" . $body . "\n<div hidden data-sc-page-originale>" . $original . "</div>\n"
                . '<script>document.addEventListener("click",function(e){var b=e.target&&e.target.closest?e.target.closest("nav button"):null;'
                . 'if(!b||b.hasAttribute("aria-controls"))return;e.preventDefault();e.stopImmediatePropagation();location.href=' . $go . ';},true);</script>' . "\n";
        };
        if (preg_match('#(<main\b[^>]*>)(.*)(</main>)#is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $html = substr($html, 0, $m[2][1]) . $fill($m[2][0]) . substr($html, $m[3][1]);
        } elseif (preg_match('#</header>#i', $html, $h, PREG_OFFSET_CAPTURE) && preg_match('#<footer\b#i', $html, $f, PREG_OFFSET_CAPTURE, $h[0][1])) {
            $a = $h[0][1] + strlen($h[0][0]);
            $html = substr($html, 0, $a) . "\n<main>" . $fill(substr($html, $a, $f[0][1] - $a)) . "</main>\n" . substr($html, $f[0][1]);
        } elseif (preg_match('#(<body\b[^>]*>)(.*)(</body>)#is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $html = substr($html, 0, $m[2][1]) . "\n<main>\n" . $body . "\n</main>\n" . substr($html, $m[3][1]);
        }
        $html = (string) preg_replace('#<title>.*?</title>#is', '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>', $html, 1);
        $html = (string) preg_replace('#(<meta\b[^>]*name="description"[^>]*content=")[^"]*(")#i', '${1}' . htmlspecialchars($title, ENT_QUOTES) . '${2}', $html);
        $html = (string) preg_replace_callback('#<(link|meta)\b[^>]*(canonical|og:url)[^>]*>#i', fn ($m) => str_replace($templateName . '"', $fileName . '"', $m[0]), $html);
        // Liens du menu vers une partie de la page modèle (« #sacs ») : ils mènent désormais à cette page.
        $html = (string) preg_replace_callback('#<(header|nav)\b.*?</\1>#is', fn ($z) => (string) preg_replace_callback('#href="\#([\w-]+)"#',
            fn ($l) => preg_match('#\sid="' . preg_quote($l[1], '#') . '"#', $html) ? $l[0] : 'href="' . $templateName . '#' . $l[1] . '"', $z[0]), $html);
        $html = (string) preg_replace_callback('#<(header|nav)\b.*?</\1>#is', fn ($z) => (string) preg_replace_callback('#\sclass="([^"]*)"#',
            fn ($c) => ' class="' . trim((string) preg_replace('/(^|\s)(is-)?(active|current)(?=\s|$)/', ' ', $c[1])) . '"', $z[0]), $html);
        // Plus de page « en cours » héritée du modèle.
        $html = (string) preg_replace('#\saria-current="page"#i', '', $html);
        $meta = '<meta name="generator" content="' . self::GENERATOR . '">';
        $pos = stripos($html, '</head>');
        return $pos === false ? $html : substr($html, 0, $pos) . $meta . "\n" . substr($html, $pos);
    }
}
