<?php
declare(strict_types=1);

namespace SimpleCommerce\Content;

use SimpleCommerce\Support\Text;

/**
 * Détection automatique : à partir des fichiers de contenu d'un site, on propose les rubriques.
 * Le client ou l'administrateur peut ensuite renommer, masquer ou réordonner.
 */
final class Infer
{
    private const LABELS = [
        'title' => 'Titre', 'titre' => 'Titre', 'name' => 'Nom', 'nom' => 'Nom', 'label' => 'Libellé',
        'description' => 'Description', 'desc' => 'Description', 'summary' => 'Résumé', 'resume' => 'Résumé', 'excerpt' => 'Résumé',
        'content' => 'Texte', 'body' => 'Texte', 'text' => 'Texte', 'texte' => 'Texte', 'contenu' => 'Texte',
        'price' => 'Prix', 'prix' => 'Prix', 'tarif' => 'Tarif', 'compareatprice' => 'Prix barré', 'oldprice' => 'Ancien prix', 'prixbarre' => 'Prix barré',
        'image' => 'Photo', 'photo' => 'Photo', 'img' => 'Photo', 'picture' => 'Photo', 'cover' => 'Photo principale', 'thumbnail' => 'Vignette',
        'images' => 'Photos', 'photos' => 'Photos', 'gallery' => 'Galerie', 'galerie' => 'Galerie',
        'available' => 'Disponible', 'disponible' => 'Disponible', 'instock' => 'En stock', 'stock' => 'Stock', 'active' => 'Visible', 'visible' => 'Visible',
        'published' => 'Publié', 'featured' => 'Mis en avant', 'category' => 'Catégorie', 'categorie' => 'Catégorie', 'tags' => 'Mots-clés',
        'phone' => 'Téléphone', 'tel' => 'Téléphone', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'mail' => 'E-mail',
        'address' => 'Adresse', 'adresse' => 'Adresse', 'city' => 'Ville', 'ville' => 'Ville', 'zip' => 'Code postal', 'cp' => 'Code postal',
        'hours' => 'Horaires', 'horaires' => 'Horaires', 'openinghours' => 'Horaires', 'day' => 'Jour', 'jour' => 'Jour',
        'date' => 'Date', 'url' => 'Lien', 'link' => 'Lien', 'lien' => 'Lien', 'subtitle' => 'Sous-titre', 'soustitre' => 'Sous-titre',
        'slug' => 'Adresse de la page', 'weight' => 'Poids', 'order' => 'Ordre', 'ordre' => 'Ordre', 'alt' => 'Description de la photo',
        'quantity' => 'Quantité', 'ingredients' => 'Ingrédients', 'allergenes' => 'Allergènes', 'allergens' => 'Allergènes',
        'gateaux' => 'Gâteaux', 'gateau' => 'Gâteau', 'actualites' => 'Actualités', 'actualite' => 'Actualité', 'evenements' => 'Événements',
        'equipe' => 'Équipe', 'realisations' => 'Réalisations', 'creations' => 'Créations', 'presentation' => 'Présentation',
        'infos' => 'Infos pratiques', 'entree' => 'Entrée', 'entrees' => 'Entrées', 'desserts' => 'Desserts', 'boissons' => 'Boissons',
        'carte' => 'Carte', 'menu' => 'Menu', 'projets' => 'Projets', 'services' => 'Services', 'prestations' => 'Prestations',
        'temoignages' => 'Témoignages', 'faq' => 'Questions fréquentes', 'accroche' => 'Accroche', 'annee' => 'Année', 'lieu' => 'Lieu',
        'surface' => 'Surface', 'categories' => 'Catégories', 'collections' => 'Collections', 'site' => 'Informations générales',
        'accueil' => 'Accueil', 'apropos' => 'À propos', 'heures' => 'Heures', 'reseaux' => 'Réseaux sociaux', 'produits' => 'Produits', 'produit' => 'Produit',
    ];
    private const ID_KEYS = ['id', '_id', 'uuid', 'slug', 'sku', 'ref', 'reference'];
    private const TITLE_KEYS = ['name', 'nom', 'title', 'titre', 'label', 'libelle'];
    private const IMAGE_EXT = '/\.(jpe?g|png|webp|gif|avif|svg)(\?.*)?$/i';
    private const IMAGE_KEY = '/(image|photo|img|picture|cover|thumbnail|visuel|logo|banner|banniere|avatar)/i';
    private const PRICE_KEY = '/(price|prix|tarif|cost|amount|montant)/i';

    public static function humanize(string $key): string
    {
        $compact = strtolower(preg_replace('/[_\-\s]/', '', $key));
        if (isset(self::LABELS[$compact])) {
            return self::LABELS[$compact];
        }
        $words = strtolower(trim(preg_replace('/[_\-.]+/', ' ', preg_replace('/([a-z])([A-Z])/', '$1 $2', $key))));
        return mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1);
    }

    private static function isAssoc(mixed $v): bool
    {
        return is_array($v) && ($v === [] ? false : !array_is_list($v)) || $v instanceof \stdClass;
    }

    private static function looksLikeImage(string $key, array $values): bool
    {
        $strings = array_values(array_filter($values, fn ($v) => is_string($v) && $v !== ''));
        if (!$strings) {
            return (bool) preg_match(self::IMAGE_KEY, $key);
        }
        $allImg = !array_filter($strings, fn ($s) => !(preg_match(self::IMAGE_EXT, $s) || preg_match('#^https?://.*(cdn|images?|media|uploads?)#i', $s)));
        $keyImg = preg_match(self::IMAGE_KEY, $key) && !array_filter($strings, fn ($s) => preg_match('/\s/', $s));
        return $allImg || $keyImg;
    }

    private static function field(string $key, array $values): array
    {
        $present = array_values(array_filter($values, fn ($v) => $v !== null));
        $base = ['key' => $key, 'label' => self::humanize($key)];
        if (in_array(strtolower($key), self::ID_KEYS, true)) {
            return $base + ['type' => 'text', 'hidden' => true];
        }
        if (!$present) {
            return $base + ['type' => preg_match(self::IMAGE_KEY, $key) ? 'image' : 'text'];
        }
        $all = fn (callable $fn) => !array_filter($present, fn ($v) => !$fn($v));
        if ($all('is_bool')) {
            return $base + ['type' => 'boolean'];
        }
        if (preg_match(self::PRICE_KEY, $key) && $all(fn ($v) => is_int($v) || is_float($v) || (is_string($v) && preg_match('/\d/', $v) && strlen($v) < 20))) {
            return $base + ['type' => 'price', 'priceFormat' => Price::detect($present)];
        }
        if ($all(fn ($v) => is_int($v) || is_float($v))) {
            return $base + ['type' => 'number'];
        }
        if ($all(fn ($v) => is_array($v) && array_is_list($v))) {
            $items = array_merge(...$present);
            if (!$items || $all(fn ($v) => !array_filter($v, fn ($i) => !is_string($i)))) {
                return $items && self::looksLikeImage($key, $items) ? $base + ['type' => 'gallery'] : $base + ['type' => 'list'];
            }
            if (!array_filter($items, fn ($i) => !self::isAssoc($i))) {
                return $base + ['type' => 'repeater', 'itemLabel' => 'ligne', 'fields' => self::fields(array_map(fn ($i) => (array) $i, $items))];
            }
            return $base + ['type' => 'list', 'hidden' => true];
        }
        if ($all(fn ($v) => self::isAssoc($v))) {
            return $base + ['type' => 'group', 'fields' => self::fields(array_map(fn ($v) => (array) $v, $present))];
        }
        if ($all('is_string')) {
            if (self::looksLikeImage($key, $present)) {
                return $base + ['type' => 'image', 'aspect' => 'libre'];
            }
            if ($all(fn ($s) => preg_match('/^\d{4}-\d{2}-\d{2}/', $s))) {
                return $base + ['type' => 'date'];
            }
            if ($all(fn ($s) => filter_var($s, FILTER_VALIDATE_EMAIL))) {
                return $base + ['type' => 'email'];
            }
            if (preg_match('/(phone|tel|mobile|portable)/i', $key)) {
                return $base + ['type' => 'phone'];
            }
            if ($all(fn ($s) => preg_match('#^(https?://|/)\S*$#', $s))) {
                return $base + ['type' => 'url'];
            }
            if (array_filter($present, fn ($s) => preg_match('#</?(p|strong|em|br|a|ul|li|b|i)\b#i', $s))) {
                return $base + ['type' => 'richtext'];
            }
            $longest = max(array_map('mb_strlen', $present));
            if (array_filter($present, fn ($s) => str_contains($s, "\n")) || $longest > 90 || preg_match('/(description|desc|texte|text|content|body|resume|summary|bio|intro)/i', $key)) {
                return $base + ['type' => 'textarea'];
            }
            return $base + ['type' => 'text'];
        }
        return $base + ['type' => 'text', 'hidden' => true];
    }

    public static function fields(array $samples): array
    {
        $keys = [];
        foreach ($samples as $s) {
            foreach (array_keys((array) $s) as $k) {
                $keys[(string) $k] = true;
            }
        }
        return array_map(fn ($k) => self::field($k, array_map(fn ($s) => ((array) $s)[$k] ?? null, $samples)), array_keys($keys));
    }

    private static function pick(array $fields, array $candidates, ?string $type = null): ?string
    {
        foreach ($candidates as $c) {
            foreach ($fields as $f) {
                if (strtolower($f['key']) === $c && empty($f['hidden'])) {
                    return $f['key'];
                }
            }
        }
        if ($type) {
            foreach ($fields as $f) {
                if ($f['type'] === $type && empty($f['hidden'])) {
                    return $f['key'];
                }
            }
        }
        return null;
    }

    private static function singular(string $label): string
    {
        $l = mb_strtolower($label);
        return match (true) {
            str_ends_with($l, 'eaux') => mb_substr($l, 0, -1),
            str_ends_with($l, 'aux') => mb_substr($l, 0, -3) . 'al',
            str_ends_with($l, 's') && !str_ends_with($l, 'ss') => mb_substr($l, 0, -1),
            default => $l,
        };
    }

    private static function order(array $fields, ?string $title, ?string $image): array
    {
        $rank = fn ($f) => $f['key'] === $title ? 0 : ($f['key'] === $image ? 1 : ($f['type'] === 'price' ? 2 : (!empty($f['hidden']) ? 9 : 5)));
        usort($fields, fn ($a, $b) => $rank($a) <=> $rank($b));
        return $fields;
    }

    private static function collection(string $key, string $label, array $items, array $source): array
    {
        $items = array_map(fn ($i) => (array) $i, $items);
        $fields = self::fields($items);
        $idField = null;
        foreach (self::ID_KEYS as $k) {
            $vals = array_map(fn ($i) => $i[$k] ?? null, $items);
            if (!array_filter($vals, fn ($v) => !is_string($v) && !is_int($v)) && count(array_unique(array_map('strval', $vals))) === count($vals)) {
                $idField = $k;
                break;
            }
        }
        $title = self::pick($fields, self::TITLE_KEYS, 'text');
        $image = self::pick($fields, [], 'image') ?? self::pick($fields, [], 'gallery');
        $sub = null;
        foreach ($fields as $f) {
            if ($f['type'] === 'price') {
                $sub = $f['key'];
                break;
            }
        }
        return array_filter([
            'key' => $key, 'label' => $label, 'kind' => 'collection', 'itemLabel' => self::singular($label),
            'titleField' => $title, 'imageField' => $image, 'subtitleField' => $sub, 'idField' => $idField,
            'orderable' => true, 'allowCreate' => true, 'allowDelete' => true, 'source' => $source,
            'fields' => self::order($fields, $title, $image),
        ], fn ($v) => $v !== null);
    }

    private static function unique(string $base, array &$used): string
    {
        $key = Text::slugify($base);
        $k = $key;
        $n = 2;
        while (isset($used[$k])) {
            $k = $key . '-' . $n++;
        }
        $used[$k] = true;
        return $k;
    }

    /**
     * @param array<int, array{path: string, text: string}> $files chemins relatifs au dossier de contenu
     * @return array{schema: array, skipped: string[]}
     */
    public static function schema(array $files): array
    {
        $sections = [];
        $used = [];
        $skipped = [];
        $markdown = [];
        foreach ($files as $file) {
            $format = Formats::fromPath($file['path']);
            $base = preg_replace('/\.[^.]+$/', '', basename($file['path']));
            if (str_starts_with($base, '_') || str_starts_with($base, 'simplecommerce') || preg_match('/package(-lock)?|tsconfig|manifest|composer/i', $base)) {
                $skipped[] = $file['path'];
                continue;
            }
            if ($format === 'markdown') {
                $folder = str_contains($file['path'], '/') ? dirname($file['path']) : '';
                $markdown[$folder][] = $file;
                continue;
            }
            if (!$format) {
                $skipped[] = $file['path'];
                continue;
            }
            try {
                $data = Formats::parse($file['text'], $format)['data'];
            } catch (\Throwable) {
                $skipped[] = $file['path'];
                continue;
            }
            if (is_array($data) && array_is_list($data)) {
                if ($data && !array_filter($data, fn ($i) => !self::isAssoc($i))) {
                    $sections[] = self::collection(self::unique($base, $used), self::humanize($base), $data, ['type' => 'file', 'file' => $file['path'], 'format' => $format]);
                } else {
                    $skipped[] = $file['path'];
                }
                continue;
            }
            if (!self::isAssoc($data)) {
                $skipped[] = $file['path'];
                continue;
            }
            $data = (array) $data;
            $rest = [];
            foreach ($data as $k => $v) {
                $k = (string) $k;
                if (is_array($v) && array_is_list($v) && $v && !array_filter($v, fn ($i) => !self::isAssoc($i)) && count((array) $v[0]) >= 2) {
                    $sections[] = self::collection(self::unique($k, $used), self::humanize($k), $v, ['type' => 'file', 'file' => $file['path'], 'format' => $format, 'path' => $k]);
                } elseif (self::isAssoc($v) && count($data) > 1 && array_filter((array) $v, 'is_string')) {
                    $sections[] = ['key' => self::unique($k, $used), 'label' => self::humanize($k), 'kind' => 'singleton',
                        'source' => ['type' => 'file', 'file' => $file['path'], 'format' => $format, 'path' => $k], 'fields' => self::fields([(array) $v])];
                } else {
                    $rest[$k] = $v;
                }
            }
            if ($rest) {
                $sections[] = ['key' => self::unique($base, $used), 'label' => self::humanize($base), 'kind' => 'singleton',
                    'source' => ['type' => 'file', 'file' => $file['path'], 'format' => $format], 'fields' => self::fields([$rest])];
            }
        }
        foreach ($markdown as $folder => $mdFiles) {
            $samples = [];
            foreach ($mdFiles as $f) {
                try {
                    $p = Formats::parse($f['text'], 'markdown');
                    $samples[] = ((array) $p['data']) + ['body' => $p['body'] ?? ''];
                } catch (\Throwable) {
                    $skipped[] = $f['path'];
                }
            }
            if (!$samples) {
                continue;
            }
            $name = basename((string) $folder) ?: 'pages';
            $ext = '.' . pathinfo($mdFiles[0]['path'], PATHINFO_EXTENSION);
            $fields = array_map(fn ($f) => $f['key'] === 'body' ? ['key' => 'body', 'label' => 'Texte', 'type' => 'markdown'] : $f, self::fields($samples));
            $orderField = null;
            foreach ($fields as $f) {
                if (in_array(strtolower($f['key']), ['order', 'ordre', 'weight', 'position'], true) && $f['type'] === 'number') {
                    $orderField = $f['key'];
                }
            }
            $title = self::pick($fields, self::TITLE_KEYS, 'text');
            $image = self::pick($fields, [], 'image');
            $fields = array_map(fn ($f) => $f['key'] === $orderField ? $f + ['hidden' => true] : $f, self::order($fields, $title, $image));
            $sections[] = array_filter([
                'key' => self::unique($name, $used), 'label' => self::humanize($name), 'kind' => 'collection',
                'itemLabel' => self::singular(self::humanize($name)), 'titleField' => $title, 'imageField' => $image,
                'orderable' => $orderField !== null, 'allowCreate' => true, 'allowDelete' => true,
                'source' => array_filter(['type' => 'folder', 'folder' => (string) $folder, 'format' => 'markdown', 'extension' => $ext, 'bodyField' => 'body', 'orderField' => $orderField], fn ($v) => $v !== null),
                'fields' => $fields,
            ], fn ($v) => $v !== null);
        }
        return ['schema' => ['version' => 1, 'sections' => $sections], 'skipped' => $skipped];
    }
}
