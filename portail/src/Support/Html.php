<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

/** Nettoyage du texte enrichi : gras, italique, liens, paragraphes, listes. Rien qui puisse casser une mise en page. */
final class Html
{
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'a', 'ul', 'ol', 'li'];
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'div' => 'p'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('root');
        if (!$root) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= self::clean($child);
        }
        return trim($out);
    }

    private static function clean(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->wholeText, ENT_NOQUOTES, 'UTF-8');
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math'], true)) {
            return '';
        }
        $inner = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $inner .= self::clean($child);
        }
        $tag = self::RENAME[$tag] ?? $tag;
        if (!in_array($tag, self::ALLOWED, true)) {
            return $inner;
        }
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (!preg_match('#^(https?://|mailto:|tel:|/)#i', $href)) {
                return $inner;
            }
            $blank = $node->getAttribute('target') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $blank . '>' . $inner . '</a>';
        }
        return "<$tag>$inner</$tag>";
    }
}
