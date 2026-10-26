<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\RetryableConflict;
use SimpleCommerce\Db;

/**
 * Catalogue hébergé par Simple Commerce : les « fichiers » du site (produits, réglages, photos)
 * sont gardés dans la base du portail. Le site du client les affiche grâce à une ligne à coller
 * (voir CatalogueController). Aucun accès au site du client n'est nécessaire.
 */
final class DbBackend implements FileBackend
{
    public const CONTENT = 'content.json';
    public const SCHEMA = 'simplecommerce.json';
    public const PHOTOS = 'photos';

    private static bool $ready = false;

    public function __construct(private string $siteId)
    {
        self::ensureTable();
    }

    /** Table créée à la demande : les portails déjà installés n'ont rien à faire. */
    public static function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        $long = Db::driver() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $engine = Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        Db::pdo()->exec("CREATE TABLE IF NOT EXISTS sc_files (site_id VARCHAR(64) NOT NULL, path VARCHAR(191) NOT NULL,
            content $long NOT NULL, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (site_id, path))$engine");
        self::$ready = true;
    }

    /** Ajoute aux catalogues déjà créés les champs apparus depuis (pastille, mise en avant…). Null si rien à changer. */
    public static function upgradeSchema(array $schema): ?array
    {
        $model = \SimpleCommerce\Content\ContentSchema::parse(json_decode(self::defaults()[self::SCHEMA], true));
        $ref = current(array_filter($model['sections'], fn ($s) => $s['key'] === 'produits'));
        $changed = false;
        foreach ($schema['sections'] as &$sec) {
            if ($sec['key'] !== 'produits') {
                continue;
            }
            $have = array_column($sec['fields'], 'key');
            foreach ($ref['fields'] as $i => $f) {
                if (in_array($f['key'], $have, true)) {
                    continue;
                }
                // On le place après le champ qui le précède dans le modèle.
                $prev = $i > 0 ? array_search($ref['fields'][$i - 1]['key'], array_column($sec['fields'], 'key'), true) : false;
                array_splice($sec['fields'], $prev === false ? count($sec['fields']) : $prev + 1, 0, [$f]);
                $have = array_column($sec['fields'], 'key');
                $changed = true;
            }
        }
        unset($sec);
        return $changed ? $schema : null;
    }

    /** Contenu de départ d'un catalogue neuf (rien n'est écrit tant que le client n'a rien enregistré). */
    public static function defaults(): array
    {
        $price = ['store' => 'number'];
        $schema = ['version' => 1, 'sections' => [
            ['key' => 'produits', 'label' => 'Mes produits', 'kind' => 'collection', 'itemLabel' => 'produit', 'titleField' => 'nom',
                'imageField' => 'photos', 'subtitleField' => 'prix', 'idField' => 'id',
                'source' => ['type' => 'file', 'file' => self::CONTENT, 'path' => 'produits'],
                'fields' => [
                    ['key' => 'nom', 'label' => 'Nom du produit', 'type' => 'text', 'required' => true, 'maxLength' => 80],
                    ['key' => 'photos', 'label' => 'Photos', 'type' => 'gallery', 'aspect' => '1:1', 'maxWidth' => 1600, 'help' => 'La première photo sert de vignette.'],
                    ['key' => 'prix', 'label' => 'Prix', 'type' => 'price', 'priceFormat' => $price],
                    ['key' => 'ancienPrix', 'label' => 'Ancien prix (barré)', 'type' => 'price', 'priceFormat' => $price, 'help' => 'Seulement pour une promotion. Laissez vide sinon.'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxLength' => 800],
                    ['key' => 'details', 'label' => 'Détails (un par ligne)', 'type' => 'textarea', 'maxLength' => 1500,
                        'help' => 'Dimensions, matière, poids, conservation… Par exemple : « Dimensions : 20 × 30 cm ».'],
                    ['key' => 'options', 'label' => 'Choix proposés au client', 'type' => 'repeater', 'itemLabel' => 'choix',
                        'help' => 'Tailles, formats, parfums, couleurs… ou une boîte à composer.', 'fields' => [
                        ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'maxLength' => 40, 'help' => 'Par exemple « Taille », « Parfum », « Composition de la boîte ».'],
                        ['key' => 'type', 'label' => 'Le client…', 'type' => 'select', 'required' => true, 'options' => [
                            ['value' => 'un', 'label' => 'choisit une option'],
                            ['value' => 'plusieurs', 'label' => 'compose une boîte'],
                        ]],
                        ['key' => 'nombre', 'label' => 'Nombre d\'articles dans la boîte', 'type' => 'number', 'min' => 1, 'max' => 100,
                            'help' => 'Seulement pour une boîte : par exemple 10 pour une boîte de 10 cookies.'],
                        ['key' => 'choix', 'label' => 'Options, une par ligne', 'type' => 'textarea', 'maxLength' => 2000,
                            'help' => 'Ajoutez un prix s\'il change : « 40 cm · 55 € » remplace le prix, « Bougie · +2 € » s\'ajoute. Dans une boîte, un prix s\'ajoute pour chaque article.'],
                    ]],
                    ['key' => 'stock', 'label' => 'Stock', 'type' => 'number', 'min' => 0, 'help' => 'Laissez vide si vous ne comptez pas. À 0, le produit est affiché « Épuisé ».'],
                    ['key' => 'pastille', 'label' => 'Pastille', 'type' => 'text', 'maxLength' => 24, 'help' => 'Petit mot affiché sur la photo : « Nouveau », « Promo », « Coup de cœur »… Laissez vide sinon.'],
                    ['key' => 'enAvant', 'label' => 'Mettre en avant', 'type' => 'boolean', 'help' => 'Le produit est affiché en premier sur votre site.'],
                    ['key' => 'lien', 'label' => 'Lien pour commander', 'type' => 'url', 'help' => 'Facultatif : page de paiement, formulaire de contact… Un bouton « Commander » y mènera.'],
                    ['key' => 'disponible', 'label' => 'Visible sur mon site', 'type' => 'boolean', 'help' => 'Décochez pour cacher le produit sans l\'effacer.'],
                ]],
        ]];
        return [
            self::SCHEMA => json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            self::CONTENT => "{\n  \"produits\": []\n}\n",
        ];
    }

    public function test(): array
    {
        return [['label' => 'Catalogue prêt', 'ok' => true]];
    }

    public function read(string $path): ?array
    {
        $content = Db::value('SELECT content FROM sc_files WHERE site_id = ? AND path = ?', [$this->siteId, $path]);
        if ($content === null) {
            $content = self::defaults()[$path] ?? null;
        } elseif (str_starts_with($path, self::PHOTOS . '/')) {
            $content = base64_decode((string) $content);
        }
        return $content === null ? null : ['content' => (string) $content, 'version' => hash('sha256', (string) $content)];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $stored = array_column(Db::all('SELECT path FROM sc_files WHERE site_id = ?', [$this->siteId]), 'path');
        $all = array_unique([...$stored, ...array_keys(self::defaults())]);
        $prefix = $dir === '' ? '' : rtrim($dir, '/') . '/';
        return array_values(array_filter($all, fn ($p) => str_starts_with($p, $prefix) && count(explode('/', substr($p, strlen($prefix)))) <= $maxDepth));
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        return Db::transaction(function () use ($writes, $expected) {
            foreach ($expected as $path => $version) {
                if (($this->read($path)['version'] ?? null) !== $version) {
                    throw new RetryableConflict($path);
                }
            }
            foreach ($writes as $w) {
                Db::run('DELETE FROM sc_files WHERE site_id = ? AND path = ?', [$this->siteId, $w['path']]);
                if ($w['content'] !== null) {
                    $content = str_starts_with($w['path'], self::PHOTOS . '/') ? base64_encode($w['content']) : $w['content'];
                    Db::insert('sc_files', ['site_id' => $this->siteId, 'path' => $w['path'], 'content' => $content, 'updated_at' => Db::now()]);
                }
            }
            return bin2hex(random_bytes(4));
        });
    }

    public function close(): void
    {
    }

    public static function purge(string $siteId): void
    {
        self::ensureTable();
        Db::run('DELETE FROM sc_files WHERE site_id = ?', [$siteId]);
    }
}
