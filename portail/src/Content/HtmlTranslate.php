<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

/**
 * Version anglaise d'une page HTML : relève les textes visibles (et les textes cachés utiles : alt, title,
 * description de la page), puis fabrique la copie traduite. La page d'origine n'est jamais réécrite ici.
 */
final class HtmlTranslate
{
    private const SKIP = ['script', 'style', 'noscript', 'code', 'pre', 'svg', 'template', 'textarea', 'iframe', 'object', 'math'];
    private const ATTRS = ['alt', 'title', 'placeholder', 'aria-label'];
    private const META = ['description', 'og:title', 'og:description', 'twitter:title', 'twitter:description', 'og:site_name'];
    /** Signature des pages fabriquées par le portail (seules celles-ci peuvent être remplacées ou retirées). */
    public const GENERATOR = 'Simple Commerce (traduction anglaise)';

    public static function enPath(string $path): string
    {
        return (string) preg_replace('/\.(html?)$/i', '-en.$1', $path);
    }

    public static function isOurs(string $html): bool
    {
        return str_contains($html, self::GENERATOR);
    }

    /** @return string[] textes à traduire (sans doublons) */
    public static function segments(string $html): array
    {
        $out = [];
        self::walk(self::load($html), function (string $text) use (&$out) {
            $out[$text] = true;
            return null;
        });
        return array_keys($out);
    }

    /**
     * Copie anglaise de la page. $map : texte français → anglais. $link : nouvelle adresse d'un lien interne (ou null).
     */
    public static function render(string $html, array $map, callable $link, string $frHref): string
    {
        $doc = self::load($html);
        self::walk($doc, fn (string $text) => $map[$text] ?? null);
        $root = $doc->documentElement;
        if ($root instanceof \DOMElement) {
            $root->setAttribute('lang', 'en');
        }
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('//a[@href] | //area[@href]') as $a) {
            /** @var \DOMElement $a */
            $new = $link($a->getAttribute('href'));
            if ($new !== null) {
                $a->setAttribute('href', $new);
            }
        }
        foreach ($xp->query('//link[@rel="canonical"][@href] | //meta[@property="og:url"][@content]') as $el) {
            /** @var \DOMElement $el */
            $attr = $el->hasAttribute('href') ? 'href' : 'content';
            $v = $el->getAttribute($attr);
            $el->setAttribute($attr, preg_match('/\.html?$/i', $v) ? self::enPath($v) : (str_ends_with($v, '/') ? $v . 'index-en.html' : $v));
        }
        $head = $xp->query('//head')->item(0);
        if ($head) {
            $meta = $doc->createElement('meta');
            $meta->setAttribute('name', 'generator');
            $meta->setAttribute('content', self::GENERATOR);
            $head->appendChild($meta);
            $alt = $doc->createElement('link');
            $alt->setAttribute('rel', 'alternate');
            $alt->setAttribute('hreflang', 'fr');
            $alt->setAttribute('href', $frHref);
            $head->appendChild($alt);
        }
        $body = $xp->query('//body')->item(0);
        if ($body) {
            $frag = $doc->createDocumentFragment();
            $frag->appendXML(self::switcher($frHref, 'fr', 'Français'));
            $body->appendChild($frag);
        }
        foreach ($xp->query('//meta[@property="og:locale"][@content]') as $el) {
            /** @var \DOMElement $el */
            $el->setAttribute('content', 'en_GB');
        }
        foreach (iterator_to_array($doc->childNodes) as $n) {
            if ($n->nodeType === XML_PI_NODE) {
                $doc->removeChild($n);
            }
        }
        // libxml écrit les accents en entités (« &eacute; ») : on les remet en clair, sauf les caractères réservés.
        return (string) preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]{1,30});/', fn ($m) => in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'apos', 'nbsp'], true)
            ? $m[0] : html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), (string) $doc->saveHTML());
    }

    /** Petit bouton de langue, en bas à gauche de la page. */
    public static function switcher(string $href, string $lang, string $label): string
    {
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_XML1) . '" hreflang="' . $lang . '" lang="' . $lang . '" data-sc-langue="1" '
            . 'style="position:fixed;left:16px;bottom:16px;z-index:2147483000;padding:9px 16px;border-radius:999px;background:#141210;color:#fff;'
            . 'font:600 14px/1 system-ui,-apple-system,Segoe UI,sans-serif;text-decoration:none;box-shadow:0 2px 10px rgba(0,0,0,.25)">' . $label . '</a>';
    }

    /** Bloc ajouté à la page française (lien « English » + indication pour les moteurs de recherche). */
    public static function frenchBlock(string $enHref): array
    {
        return [
            'head' => '<!-- simplecommerce-langue --><link rel="alternate" hreflang="en" href="' . htmlspecialchars($enHref, ENT_QUOTES) . '"><!-- /simplecommerce-langue -->',
            'body' => '<!-- simplecommerce-langue -->' . self::switcher($enHref, 'en', 'English') . '<!-- /simplecommerce-langue -->',
        ];
    }

    /** Ajoute (ou retire, si $enHref est null) le lien « English » d'une page française, sans toucher au reste. */
    public static function markFrench(string $html, ?string $enHref): string
    {
        $html = (string) preg_replace('#[ \t]*<!-- simplecommerce-langue -->.*?<!-- /simplecommerce-langue -->\r?\n?#s', '', $html);
        if ($enHref === null) {
            return $html;
        }
        $b = self::frenchBlock($enHref);
        $pos = stripos($html, '</head>');
        if ($pos !== false) {
            $html = substr($html, 0, $pos) . $b['head'] . "\n" . substr($html, $pos);
        }
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . "\n" . $b['body'] . "\n" : substr($html, 0, $pos) . $b['body'] . "\n" . substr($html, $pos);
    }

    private static function load(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Sans cette indication, libxml lit la page comme de l'ISO-8859-1.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }

    private static function key(string $text): ?string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($t === '' || mb_strlen($t) > 3000 || !preg_match('/\p{L}{2,}/u', $t) || preg_match('#^(https?://|www\.)\S+$|^\S+@\S+\.\w+$#i', $t)) {
            return null;
        }
        return $t;
    }

    /** Passe sur chaque texte ; $fn renvoie la traduction (ou null pour laisser tel quel). */
    private static function walk(\DOMDocument $doc, callable $fn): void
    {
        $xp = new \DOMXPath($doc);
        $skip = implode(' or ', array_map(fn ($t) => "ancestor::$t", self::SKIP));
        foreach ($xp->query("//text()[not($skip)]") as $node) {
            $raw = (string) $node->nodeValue;
            $k = self::key($raw);
            if ($k === null) {
                continue;
            }
            $tr = $fn($k);
            if (is_string($tr) && $tr !== '') {
                preg_match('/^\s*/u', $raw, $lead);
                preg_match('/\s*$/u', $raw, $trail);
                $node->nodeValue = $lead[0] . $tr . $trail[0];
            }
        }
        $attrQuery = implode(' | ', array_map(fn ($a) => "//*[@$a][not($skip)]", self::ATTRS)) . ' | //input[@type="submit" or @type="button"][@value]';
        foreach ($xp->query($attrQuery) as $el) {
            /** @var \DOMElement $el */
            foreach ([...self::ATTRS, 'value'] as $a) {
                if ($a === 'value' && !in_array(strtolower($el->getAttribute('type')), ['submit', 'button'], true)) {
                    continue;
                }
                if (!$el->hasAttribute($a) || ($k = self::key($el->getAttribute($a))) === null) {
                    continue;
                }
                $tr = $fn($k);
                if (is_string($tr) && $tr !== '') {
                    $el->setAttribute($a, $tr);
                }
            }
        }
        foreach ($xp->query('//meta[@content][@name or @property]') as $m) {
            /** @var \DOMElement $m */
            $name = strtolower($m->getAttribute('name') ?: $m->getAttribute('property'));
            if (!in_array($name, self::META, true) || ($k = self::key($m->getAttribute('content'))) === null) {
                continue;
            }
            $tr = $fn($k);
            if (is_string($tr) && $tr !== '') {
                $m->setAttribute('content', $tr);
            }
        }
    }
}
