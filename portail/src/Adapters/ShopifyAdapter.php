<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Content\Values;
use SimpleCommerce\Support\Http;

/**
 * Shopify — API Admin GraphQL. Jeton d'une application personnalisée de la boutique,
 * portées : read/write_products, read/write_inventory, read_locations.
 */
final class ShopifyAdapter extends BaseAdapter
{
    public const DOMAIN_RE = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';
    private const FIELDS = 'id title descriptionHtml status media(first: 30) { nodes { id ... on MediaImage { image { url } } } } variants(first: 100) { nodes { id title price compareAtPrice inventoryQuantity inventoryItem { id tracked } } }';
    private ?string $location = null;

    public function __construct(private string $domain, private string $token, private string $apiVersion = '2025-07')
    {
        if (!preg_match(self::DOMAIN_RE, $domain)) {
            throw new AdapterError('invalid', "L'adresse de la boutique doit être de la forme ma-boutique.myshopify.com.");
        }
    }

    public static function sections(): array
    {
        return [
            ['key' => 'produits', 'label' => 'Produits', 'kind' => 'collection', 'itemLabel' => 'produit', 'titleField' => 'titre', 'imageField' => 'photos', 'subtitleField' => 'prixAffiche',
                'orderable' => false, 'allowCreate' => true, 'allowDelete' => true, 'fields' => [
                    ['key' => 'titre', 'label' => 'Nom du produit', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                    ['key' => 'photos', 'label' => 'Photos', 'type' => 'gallery', 'aspect' => 'libre', 'maxWidth' => 2048],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'richtext'],
                    ['key' => 'declinaisons', 'label' => 'Prix et stock', 'type' => 'repeater', 'fixedRows' => true, 'itemLabel' => 'déclinaison', 'fields' => [
                        ['key' => 'nom', 'label' => 'Déclinaison', 'type' => 'text', 'readOnly' => true],
                        ['key' => 'prix', 'label' => 'Prix', 'type' => 'price', 'required' => true, 'priceFormat' => ['store' => 'number']],
                        ['key' => 'prixBarre', 'label' => 'Prix barré', 'type' => 'price', 'priceFormat' => ['store' => 'number'], 'help' => 'Ancien prix affiché barré (facultatif).'],
                        ['key' => 'stock', 'label' => 'Stock', 'type' => 'number', 'min' => 0, 'help' => "Laissé vide si le stock n'est pas suivi."],
                        ['key' => 'variantId', 'label' => 'Référence', 'type' => 'text', 'hidden' => true],
                        ['key' => 'inventoryItemId', 'label' => 'Référence stock', 'type' => 'text', 'hidden' => true],
                    ]],
                    ['key' => 'enVente', 'label' => 'En vente sur la boutique', 'type' => 'boolean', 'help' => 'Décochez pour masquer le produit sans le supprimer.'],
                    ['key' => 'prixAffiche', 'label' => 'Prix', 'type' => 'text', 'hidden' => true],
                ]],
            ['key' => 'collections', 'label' => 'Collections', 'kind' => 'collection', 'itemLabel' => 'collection', 'titleField' => 'titre',
                'orderable' => false, 'allowCreate' => true, 'allowDelete' => false, 'fields' => [
                    ['key' => 'titre', 'label' => 'Nom de la collection', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'richtext'],
                ]],
        ];
    }

    private function gql(string $query, array $variables = [], int $attempt = 0): array
    {
        $r = Http::request('POST', "https://{$this->domain}/admin/api/{$this->apiVersion}/graphql.json",
            ['Content-Type' => 'application/json', 'X-Shopify-Access-Token' => $this->token],
            json_encode(['query' => $query, 'variables' => (object) $variables]));
        if ($r['status'] >= 300) {
            throw Http::error('Shopify', $r['status'], $r['body']);
        }
        $body = json_decode($r['body'], true) ?? [];
        if (!empty($body['errors'])) {
            $codes = array_column(array_column($body['errors'], 'extensions'), 'code');
            if (in_array('THROTTLED', $codes, true) && $attempt < 4) {
                usleep(1500000 * ($attempt + 1));
                return $this->gql($query, $variables, $attempt + 1);
            }
            $msg = implode('; ', array_column($body['errors'], 'message'));
            if (in_array('ACCESS_DENIED', $codes, true)) {
                throw new AdapterError('auth', "Le jeton Shopify n'a pas les autorisations nécessaires.", $msg);
            }
            throw new AdapterError('remote', 'Shopify a refusé la demande.', $msg);
        }
        return $body['data'] ?? [];
    }

    private function userErrors(array $errors): void
    {
        if ($errors) {
            throw new AdapterError('invalid', 'Shopify a refusé la modification : ' . implode(' ; ', array_column($errors, 'message')));
        }
    }

    public function test(): array
    {
        try {
            $d = $this->gql('{ shop { name } currentAppInstallation { accessScopes { handle } } }');
            $scopes = array_column($d['currentAppInstallation']['accessScopes'], 'handle');
            $checks = [['label' => "Boutique « {$d['shop']['name']} » trouvée", 'ok' => true]];
            $checks[] = in_array('write_products', $scopes, true) ? ['label' => 'Modification des produits autorisée', 'ok' => true]
                : ['label' => 'Modification des produits', 'ok' => false, 'hint' => "Ajoutez l'autorisation « write_products » à l'application personnalisée."];
            $checks[] = in_array('write_inventory', $scopes, true) && in_array('read_locations', $scopes, true) ? ['label' => 'Gestion du stock autorisée', 'ok' => true]
                : ['label' => 'Gestion du stock', 'ok' => false, 'hint' => 'Pour modifier le stock, ajoutez « write_inventory » et « read_locations ».'];
        } catch (AdapterError $e) {
            $checks = [['label' => 'Connexion à la boutique', 'ok' => false, 'hint' => $e->userMessage]];
        }
        return ['ok' => !array_filter($checks, fn ($c) => !$c['ok']), 'checks' => $checks];
    }

    public function discover(): array
    {
        return ['schema' => ['version' => 1, 'sections' => self::sections()], 'notes' => []];
    }

    public function template(array $section): array
    {
        return $section['key'] === 'produits' ? ['enVente' => false, 'declinaisons' => [['nom' => 'Prix unique', 'prix' => null, 'prixBarre' => null, 'stock' => null]]] : [];
    }

    private static function num(string $gid): string
    {
        return substr($gid, strrpos($gid, '/') + 1);
    }

    private function toData(array $p): array
    {
        $variants = array_map(fn ($v) => [
            'nom' => $v['title'] === 'Default Title' ? 'Prix unique' : $v['title'],
            'prix' => (float) $v['price'],
            'prixBarre' => $v['compareAtPrice'] ? (float) $v['compareAtPrice'] : null,
            'stock' => ($v['inventoryItem']['tracked'] ?? false) ? $v['inventoryQuantity'] : null,
            'variantId' => $v['id'],
            'inventoryItemId' => $v['inventoryItem']['id'] ?? '',
        ], $p['variants']['nodes']);
        $prices = array_column($variants, 'prix');
        $min = $prices ? min($prices) : null;
        return [
            'titre' => $p['title'],
            'photos' => array_values(array_filter(array_map(fn ($m) => $m['image']['url'] ?? null, $p['media']['nodes']))),
            'description' => $p['descriptionHtml'],
            'declinaisons' => $variants,
            'enVente' => $p['status'] === 'ACTIVE',
            'prixAffiche' => $min === null ? '' : ((count(array_unique($prices)) > 1 ? 'dès ' : '') . number_format($min, 2, ',', ' ') . ' €'),
        ];
    }

    public function listEntries(array $section): array
    {
        if ($section['key'] === 'collections') {
            $d = $this->gql('{ collections(first: 100, sortKey: TITLE) { nodes { id title descriptionHtml } } }');
            return array_map(fn ($c) => ['id' => self::num($c['id']), 'data' => ['titre' => $c['title'], 'description' => $c['descriptionHtml']]], $d['collections']['nodes']);
        }
        $out = [];
        $after = null;
        for ($page = 0; $page < 10; $page++) {
            $d = $this->gql('query($after: String) { products(first: 50, after: $after, sortKey: TITLE) { nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }', ['after' => $after]);
            foreach ($d['products']['nodes'] as $p) {
                if ($p['status'] !== 'ARCHIVED') {
                    $out[] = ['id' => self::num($p['id']), 'data' => $this->toData($p)];
                }
            }
            if (!$d['products']['pageInfo']['hasNextPage']) {
                break;
            }
            $after = $d['products']['pageInfo']['endCursor'];
        }
        return $out;
    }

    private function product(string $id): ?array
    {
        return $this->gql('query($id: ID!) { product(id: $id) { ' . self::FIELDS . ' } }', ['id' => "gid://shopify/Product/$id"])['product'] ?? null;
    }

    public function getEntry(array $section, string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }
        if ($section['key'] === 'collections') {
            $c = $this->gql('query($id: ID!) { collection(id: $id) { title descriptionHtml } }', ['id' => "gid://shopify/Collection/$id"])['collection'] ?? null;
            return $c ? ['id' => $id, 'data' => ['titre' => $c['title'], 'description' => $c['descriptionHtml']]] : null;
        }
        $p = $this->product($id);
        return $p ? ['id' => $id, 'data' => $this->toData($p)] : null;
    }

    private function stage(PendingAsset $a): string
    {
        $d = $this->gql('mutation($input: [StagedUploadInput!]!) { stagedUploadsCreate(input: $input) { stagedTargets { url resourceUrl parameters { name value } } userErrors { field message } } }',
            ['input' => [['filename' => $a->fileName, 'mimeType' => $a->mime, 'resource' => 'IMAGE', 'httpMethod' => 'POST']]]);
        $this->userErrors($d['stagedUploadsCreate']['userErrors']);
        $t = $d['stagedUploadsCreate']['stagedTargets'][0];
        $form = [];
        foreach ($t['parameters'] as $p) {
            $form[$p['name']] = $p['value'];
        }
        $form['file'] = new \CURLStringFile($a->bytes, $a->fileName, $a->mime);
        $r = Http::request('POST', $t['url'], [], $form, 60);
        if ($r['status'] >= 300) {
            throw new AdapterError('remote', "L'envoi de la photo vers Shopify a échoué.", 'HTTP ' . $r['status']);
        }
        return $t['resourceUrl'];
    }

    private function mediaChanges(?array $product, array $wanted, array $ctx): array
    {
        $existing = $product['media']['nodes'] ?? [];
        $urls = array_map(fn ($m) => $m['image']['url'] ?? null, $existing);
        $add = [];
        foreach ($wanted as $v) {
            if (str_starts_with($v, Values::MEDIA_PREFIX)) {
                $a = PendingAsset::find($ctx['assets'], substr($v, strlen(Values::MEDIA_PREFIX)));
                $add[] = ['originalSource' => $this->stage($a), 'mediaContentType' => 'IMAGE', 'alt' => $a->alt];
            } elseif (!in_array($v, $urls, true)) {
                $add[] = ['originalSource' => $v, 'mediaContentType' => 'IMAGE'];
            }
        }
        $remove = array_values(array_map(fn ($m) => $m['id'], array_filter($existing, fn ($m) => !in_array($m['image']['url'] ?? '', $wanted, true))));
        return [$add, $remove];
    }

    private function locationId(): string
    {
        if (!$this->location) {
            $id = $this->gql('{ locations(first: 1) { nodes { id } } }')['locations']['nodes'][0]['id'] ?? null;
            if (!$id) {
                throw new AdapterError('invalid', "Aucun emplacement de stock n'est configuré sur la boutique.");
            }
            $this->location = $id;
        }
        return $this->location;
    }

    private function applyVariants(array $product, array $rows): void
    {
        $variants = $product['variants']['nodes'];
        $updates = [];
        $stock = [];
        foreach ($rows as $i => $row) {
            $v = null;
            foreach ($variants as $cand) {
                if ($cand['id'] === ($row['variantId'] ?? null)) {
                    $v = $cand;
                }
            }
            $v ??= $variants[$i] ?? null;
            if (!$v) {
                continue;
            }
            $price = isset($row['prix']) && $row['prix'] !== '' ? number_format((float) $row['prix'], 2, '.', '') : null;
            $compare = isset($row['prixBarre']) && $row['prixBarre'] !== '' && $row['prixBarre'] !== null ? number_format((float) $row['prixBarre'], 2, '.', '') : null;
            $curCompare = $v['compareAtPrice'] ? number_format((float) $v['compareAtPrice'], 2, '.', '') : null;
            if (($price !== null && (float) $price !== (float) $v['price']) || $compare !== $curCompare) {
                $updates[] = array_filter(['id' => $v['id'], 'price' => $price], fn ($x) => $x !== null) + ['compareAtPrice' => $compare];
            }
            if (isset($row['stock']) && $row['stock'] !== '' && $v['inventoryItem'] && (int) $row['stock'] !== $v['inventoryQuantity']) {
                if (!$v['inventoryItem']['tracked']) {
                    $this->gql('mutation($id: ID!) { inventoryItemUpdate(id: $id, input: { tracked: true }) { userErrors { message } } }', ['id' => $v['inventoryItem']['id']]);
                }
                $stock[] = ['inventoryItemId' => $v['inventoryItem']['id'], 'locationId' => $this->locationId(), 'quantity' => max(0, (int) $row['stock'])];
            }
        }
        if ($updates) {
            $d = $this->gql('mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId: $productId, variants: $variants) { userErrors { field message } } }', ['productId' => $product['id'], 'variants' => $updates]);
            $this->userErrors($d['productVariantsBulkUpdate']['userErrors']);
        }
        if ($stock) {
            $d = $this->gql('mutation($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) { userErrors { field message } } }', ['input' => ['name' => 'available', 'reason' => 'correction', 'ignoreCompareQuantity' => true, 'quantities' => $stock]]);
            $this->userErrors($d['inventorySetQuantities']['userErrors']);
        }
    }

    public function createEntry(array $section, array $data, array $ctx): array
    {
        if ($section['key'] === 'collections') {
            $d = $this->gql('mutation($input: CollectionInput!) { collectionCreate(input: $input) { collection { id } userErrors { field message } } }', ['input' => ['title' => $data['titre'], 'descriptionHtml' => $data['description'] ?? '']]);
            $this->userErrors($d['collectionCreate']['userErrors']);
            return ['id' => self::num($d['collectionCreate']['collection']['id']), 'before' => null, 'after' => Values::pick($section, $data)];
        }
        [$add] = $this->mediaChanges(null, $data['photos'] ?? [], $ctx);
        $d = $this->gql('mutation($product: ProductCreateInput!, $media: [CreateMediaInput!]) { productCreate(product: $product, media: $media) { product { ' . self::FIELDS . ' } userErrors { field message } } }',
            ['product' => ['title' => $data['titre'], 'descriptionHtml' => $data['description'] ?? '', 'status' => !empty($data['enVente']) ? 'ACTIVE' : 'DRAFT'], 'media' => $add]);
        $this->userErrors($d['productCreate']['userErrors']);
        $p = $d['productCreate']['product'];
        $this->applyVariants($p, array_slice($data['declinaisons'] ?? [], 0, 1));
        $id = self::num($p['id']);
        $fresh = $this->product($id);
        return ['id' => $id, 'before' => null, 'after' => Values::pick($section, $fresh ? $this->toData($fresh) : $data), 'ref' => $p['id']];
    }

    public function updateEntry(array $section, string $id, array $data, ?array $expected, array $ctx): array
    {
        $current = $this->getEntry($section, $id);
        if (!$current) {
            throw new ConflictError('élément supprimé');
        }
        $cmp = fn ($d) => array_diff_key(Values::pick($section, $d), ['prixAffiche' => 1]);
        if ($expected !== null && !Values::equal($cmp($current['data']), $cmp($expected))) {
            throw new ConflictError();
        }
        $before = $current['data'];
        if ($section['key'] === 'collections') {
            $d = $this->gql('mutation($input: CollectionInput!) { collectionUpdate(input: $input) { userErrors { field message } } }', ['input' => ['id' => "gid://shopify/Collection/$id", 'title' => $data['titre'], 'descriptionHtml' => $data['description'] ?? '']]);
            $this->userErrors($d['collectionUpdate']['userErrors']);
            return ['id' => $id, 'before' => $before, 'after' => Values::pick($section, array_replace($before, $data))];
        }
        $p = $this->product($id);
        [$add, $remove] = $this->mediaChanges($p, $data['photos'] ?? [], $ctx);
        $d = $this->gql('mutation($product: ProductUpdateInput!, $media: [CreateMediaInput!]) { productUpdate(product: $product, media: $media) { product { id } userErrors { field message } } }',
            ['product' => ['id' => $p['id'], 'title' => $data['titre'], 'descriptionHtml' => $data['description'] ?? '', 'status' => !empty($data['enVente']) ? 'ACTIVE' : 'DRAFT'], 'media' => $add ?: null]);
        $this->userErrors($d['productUpdate']['userErrors']);
        if ($remove) {
            $d = $this->gql('mutation($productId: ID!, $mediaIds: [ID!]!) { productDeleteMedia(productId: $productId, mediaIds: $mediaIds) { deletedMediaIds mediaUserErrors { field message } } }', ['productId' => $p['id'], 'mediaIds' => $remove]);
            $this->userErrors($d['productDeleteMedia']['mediaUserErrors']);
        }
        $this->applyVariants($p, $data['declinaisons'] ?? []);
        $fresh = $this->product($id);
        return ['id' => $id, 'before' => $before, 'after' => Values::pick($section, $fresh ? $this->toData($fresh) : array_replace($before, $data)), 'ref' => $p['id']];
    }

    public function deleteEntry(array $section, string $id, ?array $expected, array $ctx): array
    {
        $current = $this->getEntry($section, $id);
        if (!$current) {
            throw new ConflictError('élément déjà supprimé');
        }
        if ($section['key'] === 'collections') {
            throw new AdapterError('unsupported', 'La suppression des collections se fait depuis Shopify.');
        }
        $d = $this->gql('mutation($input: ProductDeleteInput!) { productDelete(input: $input) { deletedProductId userErrors { field message } } }', ['input' => ['id' => "gid://shopify/Product/$id"]]);
        $this->userErrors($d['productDelete']['userErrors']);
        return ['id' => $id, 'before' => $current['data'], 'after' => null];
    }
}
