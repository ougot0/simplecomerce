<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Content\Values;
use SimpleCommerce\Support\Http;

/**
 * WordPress (API REST) + WooCommerce, chez n'importe quel hébergeur.
 * Connexion par « mot de passe d'application » (WordPress 5.6 et plus).
 */
final class WordPressAdapter extends BaseAdapter
{
    private string $base;

    public function __construct(string $siteUrl, private string $username, private string $appPassword, private ?string $wooKey = null, private ?string $wooSecret = null)
    {
        $this->base = rtrim($siteUrl, '/') . '/wp-json';
    }

    public static function sections(bool $woo): array
    {
        $posts = ['key' => 'actualites', 'label' => 'Actualités', 'kind' => 'collection', 'itemLabel' => 'article', 'titleField' => 'titre', 'imageField' => 'photo', 'subtitleField' => 'date',
            'orderable' => false, 'remote' => ['type' => 'posts'], 'fields' => [
                ['key' => 'titre', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'maxLength' => 200],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'image', 'aspect' => '16:9', 'maxWidth' => 1920],
                ['key' => 'extrait', 'label' => 'Résumé', 'type' => 'textarea', 'maxLength' => 400],
                ['key' => 'contenu', 'label' => 'Texte', 'type' => 'richtext'],
                ['key' => 'publie', 'label' => 'Visible sur le site', 'type' => 'boolean'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'readOnly' => true],
                ['key' => 'photoId', 'label' => 'Référence photo', 'type' => 'number', 'hidden' => true],
            ]];
        $pages = ['key' => 'pages', 'label' => 'Pages', 'kind' => 'collection', 'itemLabel' => 'page', 'titleField' => 'titre', 'orderable' => false,
            'allowCreate' => false, 'allowDelete' => false, 'hidden' => true, 'remote' => ['type' => 'pages'], 'fields' => [
                ['key' => 'titre', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'maxLength' => 200],
                ['key' => 'contenu', 'label' => 'Texte', 'type' => 'richtext'],
            ]];
        $products = ['key' => 'produits', 'label' => 'Produits', 'kind' => 'collection', 'itemLabel' => 'produit', 'titleField' => 'nom', 'imageField' => 'photos', 'subtitleField' => 'prix',
            'orderable' => true, 'remote' => ['type' => 'woocommerce'], 'fields' => [
                ['key' => 'nom', 'label' => 'Nom du produit', 'type' => 'text', 'required' => true, 'maxLength' => 200],
                ['key' => 'photos', 'label' => 'Photos', 'type' => 'gallery', 'aspect' => '1:1', 'maxWidth' => 1600],
                ['key' => 'prix', 'label' => 'Prix', 'type' => 'price', 'required' => true, 'priceFormat' => ['store' => 'string', 'decimal' => '.']],
                ['key' => 'prixPromo', 'label' => 'Prix soldé', 'type' => 'price', 'priceFormat' => ['store' => 'string', 'decimal' => '.'], 'help' => 'Facultatif : remplace le prix tant qu\'il est rempli.'],
                ['key' => 'stock', 'label' => 'Stock', 'type' => 'number', 'min' => 0, 'help' => 'Laissez vide si vous ne suivez pas le stock.'],
                ['key' => 'resume', 'label' => 'Description courte', 'type' => 'richtext', 'maxLength' => 600],
                ['key' => 'description', 'label' => 'Description', 'type' => 'richtext'],
                ['key' => 'enVente', 'label' => 'En vente sur le site', 'type' => 'boolean'],
            ]];
        return $woo ? [$products, $posts, $pages] : [$posts, $pages];
    }

    private function auth(bool $woo): string
    {
        if ($woo && $this->wooKey && $this->wooSecret) {
            return 'Basic ' . base64_encode("{$this->wooKey}:{$this->wooSecret}");
        }
        return 'Basic ' . base64_encode($this->username . ':' . preg_replace('/\s+/', '', $this->appPassword));
    }

    private function api(string $method, string $path, mixed $body = null, bool $woo = false, array $headers = []): array
    {
        try {
            return Http::json('WordPress', $method, $this->base . $path, ['Authorization' => $this->auth($woo)] + $headers, $body);
        } catch (AdapterError $e) {
            if ($e->codeName === 'remote' && str_contains($e->userMessage, 'illisible')) {
                throw new AdapterError('remote', 'Le site WordPress a renvoyé une réponse illisible (une extension de sécurité bloque peut-être l\'API).', $e->detail);
            }
            throw $e;
        }
    }

    private function hasWoo(): bool
    {
        try {
            $this->api('GET', '/wc/v3/products?per_page=1', null, true);
            return true;
        } catch (AdapterError) {
            return false;
        }
    }

    public function capabilities(array $section): array
    {
        return ['reorder' => ($section['remote']['type'] ?? '') === 'woocommerce', 'create' => ($section['allowCreate'] ?? true) !== false, 'delete' => ($section['allowDelete'] ?? true) !== false];
    }

    public function test(): array
    {
        try {
            $me = $this->api('GET', '/wp/v2/users/me?context=edit')['data'];
            $checks = [['label' => "Connecté en tant que « {$me['name']} »", 'ok' => true]];
            $checks[] = (($me['capabilities']['edit_posts'] ?? true) === false)
                ? ['label' => 'Droits de modification', 'ok' => false, 'hint' => 'Ce compte WordPress ne peut pas modifier les contenus. Utilisez un compte « Éditeur » ou « Administrateur ».']
                : ['label' => 'Droits de modification', 'ok' => true];
            if ($this->hasWoo()) {
                $checks[] = ['label' => 'Boutique WooCommerce détectée', 'ok' => true];
            }
        } catch (AdapterError $e) {
            $checks = [['label' => 'Connexion à WordPress', 'ok' => false, 'hint' => $e->codeName === 'auth'
                ? "Identifiant ou mot de passe d'application refusé. Le mot de passe d'application n'est pas votre mot de passe habituel : créez-le dans Profil → Mots de passe d'application."
                : $e->userMessage]];
        }
        return ['ok' => !array_filter($checks, fn ($c) => !$c['ok']), 'checks' => $checks];
    }

    public function discover(): array
    {
        return ['schema' => ['version' => 1, 'sections' => self::sections($this->hasWoo())],
            'notes' => ['Les pages sont masquées par défaut : si elles sont construites avec un constructeur (Elementor, Divi…), leur texte ne doit pas être réécrit.']];
    }

    public function template(array $section): array
    {
        return match ($section['remote']['type'] ?? '') {
            'woocommerce' => ['enVente' => true],
            'posts' => ['publie' => true],
            default => [],
        };
    }

    private static function decode(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function postToData(array $p, string $type): array
    {
        if ($type === 'pages') {
            return ['titre' => self::decode($p['title']['raw'] ?? $p['title']['rendered']), 'contenu' => $p['content']['raw'] ?? $p['content']['rendered']];
        }
        $media = $p['_embedded']['wp:featuredmedia'][0] ?? null;
        return [
            'titre' => self::decode($p['title']['raw'] ?? $p['title']['rendered']),
            'photo' => $media['source_url'] ?? '',
            'extrait' => self::decode($p['excerpt']['raw'] ?? $p['excerpt']['rendered'] ?? ''),
            'contenu' => $p['content']['raw'] ?? $p['content']['rendered'],
            'publie' => $p['status'] === 'publish',
            'date' => substr($p['date'], 0, 10),
            'photoId' => (int) ($p['featured_media'] ?? 0),
        ];
    }

    private function productToData(array $p): array
    {
        return [
            'nom' => self::decode($p['name']), 'photos' => array_column($p['images'], 'src'), 'prix' => $p['regular_price'], 'prixPromo' => $p['sale_price'],
            'stock' => $p['manage_stock'] ? $p['stock_quantity'] : null, 'resume' => $p['short_description'], 'description' => $p['description'], 'enVente' => $p['status'] === 'publish',
        ];
    }

    public function listEntries(array $section): array
    {
        $type = $section['remote']['type'] ?? 'posts';
        $out = [];
        for ($page = 1; $page <= 10; $page++) {
            if ($type === 'woocommerce') {
                $r = $this->api('GET', "/wc/v3/products?per_page=100&page=$page&orderby=menu_order&order=asc&status=any", null, true);
                foreach ($r['data'] as $p) {
                    $out[] = ['id' => (string) $p['id'], 'data' => $this->productToData($p)];
                }
            } else {
                $r = $this->api('GET', "/wp/v2/$type?per_page=100&page=$page&context=edit&_embed=wp:featuredmedia&status=publish,draft,future,private");
                foreach ($r['data'] as $p) {
                    $out[] = ['id' => (string) $p['id'], 'data' => $this->postToData($p, $type)];
                }
            }
            if ($page >= (int) ($r['headers']['x-wp-totalpages'] ?? 1)) {
                break;
            }
        }
        return $out;
    }

    public function getEntry(array $section, string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $type = $section['remote']['type'] ?? 'posts';
        try {
            if ($type === 'woocommerce') {
                return ['id' => $id, 'data' => $this->productToData($this->api('GET', "/wc/v3/products/$id", null, true)['data'])];
            }
            return ['id' => $id, 'data' => $this->postToData($this->api('GET', "/wp/v2/$type/$id?context=edit&_embed=wp:featuredmedia")['data'], $type)];
        } catch (AdapterError $e) {
            if ($e->codeName === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    private function upload(string $token, array $ctx): array
    {
        $a = PendingAsset::find($ctx['assets'], $token);
        $d = $this->api('POST', '/wp/v2/media', $a->bytes, false, ['Content-Type' => $a->mime, 'Content-Disposition' => 'attachment; filename="' . $a->fileName . '"'])['data'];
        if ($a->alt !== '') {
            try {
                $this->api('POST', '/wp/v2/media/' . $d['id'], ['alt_text' => $a->alt]);
            } catch (AdapterError) {
            }
        }
        return ['id' => $d['id'], 'url' => $d['source_url']];
    }

    private function postPayload(array $data, array $ctx, string $type): array
    {
        if ($type === 'pages') {
            return ['title' => $data['titre'], 'content' => $data['contenu'] ?? ''];
        }
        $featured = (int) ($data['photoId'] ?? 0);
        $photo = (string) ($data['photo'] ?? '');
        if (str_starts_with($photo, Values::MEDIA_PREFIX)) {
            $featured = $this->upload(substr($photo, strlen(Values::MEDIA_PREFIX)), $ctx)['id'];
        } elseif ($photo === '') {
            $featured = 0;
        }
        return ['title' => $data['titre'], 'content' => $data['contenu'] ?? '', 'excerpt' => $data['extrait'] ?? '', 'status' => !empty($data['publie']) ? 'publish' : 'draft', 'featured_media' => $featured];
    }

    private function productPayload(array $data, ?array $raw, array $ctx): array
    {
        $images = [];
        foreach ($data['photos'] ?? [] as $v) {
            if (str_starts_with($v, Values::MEDIA_PREFIX)) {
                $images[] = ['id' => $this->upload(substr($v, strlen(Values::MEDIA_PREFIX)), $ctx)['id']];
            } else {
                $match = array_values(array_filter($raw['images'] ?? [], fn ($i) => $i['src'] === $v));
                $images[] = $match ? ['id' => $match[0]['id']] : ['src' => $v];
            }
        }
        $stock = $data['stock'] ?? null;
        return [
            'name' => $data['nom'], 'regular_price' => (string) ($data['prix'] ?? ''), 'sale_price' => (string) ($data['prixPromo'] ?? ''),
            'short_description' => $data['resume'] ?? '', 'description' => $data['description'] ?? '', 'status' => !empty($data['enVente']) ? 'publish' : 'draft', 'images' => $images,
        ] + ($stock === null || $stock === '' ? ['manage_stock' => false] : ['manage_stock' => true, 'stock_quantity' => (int) $stock]);
    }

    public function createEntry(array $section, array $data, array $ctx): array
    {
        $type = $section['remote']['type'] ?? 'posts';
        if ($type === 'woocommerce') {
            $p = $this->api('POST', '/wc/v3/products', $this->productPayload($data, null, $ctx), true)['data'];
            return ['id' => (string) $p['id'], 'before' => null, 'after' => Values::pick($section, $this->productToData($p))];
        }
        $p = $this->api('POST', "/wp/v2/$type", $this->postPayload($data, $ctx, $type))['data'];
        $fresh = $this->getEntry($section, (string) $p['id']);
        return ['id' => (string) $p['id'], 'before' => null, 'after' => $fresh['data'] ?? Values::pick($section, $data)];
    }

    public function updateEntry(array $section, string $id, array $data, ?array $expected, array $ctx): array
    {
        $type = $section['remote']['type'] ?? 'posts';
        $current = $this->getEntry($section, $id);
        if (!$current) {
            throw new ConflictError('élément supprimé');
        }
        $this->assertSame($section, $current['data'], $expected);
        $merged = array_replace($current['data'], $data);
        if ($type === 'woocommerce') {
            $raw = $this->api('GET', "/wc/v3/products/$id", null, true)['data'];
            $p = $this->api('PUT', "/wc/v3/products/$id", $this->productPayload($merged, $raw, $ctx), true)['data'];
            return ['id' => $id, 'before' => $current['data'], 'after' => Values::pick($section, $this->productToData($p))];
        }
        $this->api('POST', "/wp/v2/$type/$id", $this->postPayload($merged, $ctx, $type));
        return ['id' => $id, 'before' => $current['data'], 'after' => $this->getEntry($section, $id)['data'] ?? Values::pick($section, $merged)];
    }

    public function deleteEntry(array $section, string $id, ?array $expected, array $ctx): array
    {
        $type = $section['remote']['type'] ?? 'posts';
        $current = $this->getEntry($section, $id);
        if (!$current) {
            throw new ConflictError('élément déjà supprimé');
        }
        $this->assertSame($section, $current['data'], $expected);
        // Mise à la corbeille : récupérable depuis WordPress.
        $type === 'woocommerce' ? $this->api('DELETE', "/wc/v3/products/$id", null, true) : $this->api('DELETE', "/wp/v2/$type/$id");
        return ['id' => $id, 'before' => $current['data'], 'after' => null];
    }

    public function reorder(array $section, array $orderedIds, array $ctx): array
    {
        if (($section['remote']['type'] ?? '') !== 'woocommerce') {
            throw new AdapterError('unsupported', "L'ordre de cette rubrique ne peut pas être modifié.");
        }
        $before = array_column($this->listEntries($section), 'id');
        $a = $orderedIds;
        $b = $before;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new ConflictError('la liste a changé');
        }
        foreach (array_chunk(array_map(fn ($id, $i) => ['id' => (int) $id, 'menu_order' => $i], $orderedIds, array_keys($orderedIds)), 100) as $chunk) {
            $this->api('POST', '/wc/v3/products/batch', ['update' => $chunk], true);
        }
        return ['before' => null, 'after' => null, 'beforeOrder' => $before, 'afterOrder' => $orderedIds];
    }
}
