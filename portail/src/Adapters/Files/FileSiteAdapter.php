<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\ConflictError;
use SimpleCommerce\Adapters\PendingAsset;
use SimpleCommerce\Adapters\RetryableConflict;
use SimpleCommerce\Adapters\Status;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Content\Formats;
use SimpleCommerce\Content\HtmlCards;
use SimpleCommerce\Content\Infer;
use SimpleCommerce\Content\Paths;
use SimpleCommerce\Content\Values;
use SimpleCommerce\Support\Text;

/**
 * Adaptateur pour tous les sites dont le contenu est dans des fichiers
 * (dépôt GitHub/GitLab/Bitbucket, serveur SFTP/FTP de n'importe quel hébergeur, dossier local de démo).
 * Il lit le fichier, modifie uniquement l'élément concerné et réécrit en conservant le reste.
 */
final class FileSiteAdapter implements Adapter
{
    public const STATUS_FILE = 'simplecommerce-statut.json';
    private const CONTENT_DIRS = ['content', 'src/content', 'data', 'src/data', '_data', 'src/_data', 'contenu', 'site/content', 'app/content'];
    private const ROOT_FILE = '/^(content|contenu|data|donnees|site)[\w-]*\.(json|ya?ml)$/i';
    private const ATTEMPTS = 3;

    private bool $resolved = false;
    /** Site fait main sans dossier de contenu : fichiers JavaScript/JSON où des produits ont été trouvés. */
    private ?array $scanFiles = null;
    /** Page d'accueil d'un site (index.html, ou accueil.html / home.html). */
    private const HOME_PAGE = '#(^|/)(index|accueil|home)\.(html?|php)$#i';
    private const SKIP_SCAN = '#(^|/)(node_modules|vendor|vendors|lib|libs|plugins|dist|build|\.git)(/|$)|\.min\.js$|(jquery|bootstrap|stripe|analytics|gtag|swiper|slick|lightbox|polyfill|chunk|bundle|package(-lock)?|tsconfig|manifest|composer)[^/]*$#i';
    /** @var array<string, ?string> versions lues pendant une opération */
    private array $versions = [];
    /** @var array<string, ?array> fichiers lus pendant une opération */
    private array $cache = [];

    /**
     * @param array{contentDir: string, media: array{dir: string, publicPrefix: string}, publicUrl: string, webRoot: bool} $options
     */
    public function __construct(public readonly FileBackend $backend, private array $options)
    {
    }

    // ------------------------------------------------------------ dossiers

    private function init(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        $needContent = $this->options['contentDir'] === 'auto';
        $needMedia = $this->options['media']['dir'] === 'auto';
        if (!$needContent && !$needMedia) {
            return;
        }
        $files = $this->backend->list('', 3);
        if ($needContent) {
            $found = '';
            foreach (self::CONTENT_DIRS as $dir) {
                foreach ($files as $f) {
                    if (str_starts_with($f, "$dir/") && Formats::fromPath($f)) {
                        $found = $dir;
                        break 2;
                    }
                }
            }
            $this->options['contentDir'] = $found;
            if ($found === '' && !array_filter($files, fn ($f) => !str_contains($f, '/') && preg_match(self::ROOT_FILE, $f))) {
                $this->scanFiles = $this->findProductFiles($files);
            }
        }
        if ($needMedia) {
            $has = fn ($p) => (bool) array_filter($files, fn ($f) => str_starts_with($f, "$p/"));
            $base = $this->options['webRoot'] ? '' : ($has('public') ? 'public' : ($has('static') ? 'static' : ''));
            $this->options['media'] = ['dir' => $base ? "$base/images/simplecommerce" : 'images/simplecommerce', 'publicPrefix' => '/images/simplecommerce'];
        }
    }

    /**
     * Cherche, dans les fichiers JavaScript et JSON du site, ceux qui contiennent une liste de produits
     * (des éléments avec un nom et un prix ou une photo). Aucune préparation du site n'est nécessaire.
     * @param string[] $files
     * @return string[]
     */
    private function findProductFiles(array $files): array
    {
        $found = [];
        $candidates = array_values(array_filter($files, fn ($f) => in_array(Formats::fromPath($f), ['js', 'json', 'html'], true) && !preg_match(self::SKIP_SCAN, $f)));
        foreach (array_slice($candidates, 0, 60) as $f) {
            $raw = $this->backend->read($f);
            if (!$raw || strlen($raw['content']) > 400_000) {
                continue;
            }
            $r = Infer::schema([['path' => $f, 'text' => $raw['content']]]);
            if (array_filter($r['schema']['sections'], fn ($s) => self::isProductSection($s) || self::isOpenSwitch($s) || self::isPageTexts($s))) {
                $found[] = $f;
            }
        }
        return $found;
    }

    /** Une rubrique « produits » : une liste dont les éléments ont un nom et un prix ou une photo. */
    public static function isProductSection(array $s): bool
    {
        // Une liste dont les éléments ont un nom, et au moins un prix, une photo ou un texte (produits, projets, questions…).
        return $s['kind'] === 'collection' && !empty($s['titleField']) && count(array_filter($s['fields'], fn ($f) => empty($f['hidden']))) >= 2;
    }

    /** Textes d'une page HTML (titres, paragraphes, photos). */
    public static function isPageTexts(array $s): bool
    {
        return $s['kind'] === 'singleton' && ($s['source']['format'] ?? '') === 'html' && ($s['source']['path'] ?? '') === 'textes';
    }

    /** Réglage « ouvert / fermé » écrit dans un fichier du site (ex. OUVERTURE = { ouvert: true, message: "…" }). */
    public static function isOpenSwitch(array $s): bool
    {
        return $s['kind'] === 'singleton' && (bool) array_filter($s['fields'], fn ($f) => $f['type'] === 'boolean' && preg_match('/(open|ouvert|ferme|closed|actif|enabled|status)/i', $f['key']));
    }

    private function path(string $rel): string
    {
        return Paths::join($this->options['contentDir'], $rel);
    }

    /** @return string[] */
    private function contentFiles(): array
    {
        if ($this->scanFiles !== null) {
            return $this->scanFiles;
        }
        if ($this->options['contentDir'] !== '') {
            return array_values(array_filter($this->backend->list($this->options['contentDir'], 3), fn ($f) => Formats::fromPath($f)));
        }
        return array_values(array_filter($this->backend->list('', 1), fn ($f) => preg_match(self::ROOT_FILE, $f)));
    }

    public function close(): void
    {
        $this->backend->close();
    }

    public function capabilities(array $section): array
    {
        $src = $section['source'] ?? [];
        return [
            'reorder' => $section['kind'] === 'collection' && ($section['orderable'] ?? true) !== false && (($src['type'] ?? '') === 'file' || !empty($src['orderField'])),
            'create' => $section['kind'] === 'collection' && ($section['allowCreate'] ?? true) !== false,
            'delete' => $section['kind'] === 'collection' && ($section['allowDelete'] ?? true) !== false,
        ];
    }

    public function test(): array
    {
        $checks = $this->backend->test();
        if (!array_filter($checks, fn ($c) => !$c['ok'])) {
            try {
                $this->init();
                $files = $this->contentFiles();
                $where = $this->options['contentDir'] !== '' ? "dossier « {$this->options['contentDir']} »" : 'content.json, data.json… à la racine';
                $checks[] = $files
                    ? ['label' => $this->scanFiles !== null ? 'Contenu modifiable trouvé dans les fichiers du site' : 'Fichiers de contenu trouvés (' . count($files) . ')', 'ok' => true]
                    : ['label' => 'Contenu du site', 'ok' => false, 'hint' => "Nous n'avons trouvé aucune page ni aucun fichier de contenu dans ce dossier. Vérifiez le « Dossier du site sur le serveur » : c'est celui qui contient la page d'accueil (index.html)."];
            } catch (AdapterError $e) {
                $checks[] = ['label' => 'Lecture du dossier de contenu', 'ok' => false, 'hint' => $e->userMessage];
            }
        }
        return ['ok' => !array_filter($checks, fn ($c) => !$c['ok']), 'checks' => $checks];
    }

    public function discover(): array
    {
        $this->init();
        $notes = [];
        foreach (['simplecommerce.json', '_simplecommerce.json'] as $candidate) {
            $raw = $this->backend->read($this->path($candidate));
            if ($raw) {
                try {
                    $schema = ContentSchema::parse(json_decode($raw['content'], true, 512, JSON_THROW_ON_ERROR));
                    $notes[] = "Rubriques lues depuis $candidate.";
                    return ['schema' => $schema + ['contentDir' => $this->options['contentDir'], 'media' => $this->options['media']], 'notes' => $notes];
                } catch (\Throwable $e) {
                    $notes[] = "$candidate est présent mais invalide (" . mb_substr($e->getMessage(), 0, 120) . ') ; détection automatique utilisée.';
                }
            }
        }
        $notes[] = $this->options['contentDir'] !== '' ? "Contenu lu dans le dossier « {$this->options['contentDir']} »." : 'Contenu lu à la racine du site.';
        $prefix = $this->options['contentDir'] !== '' ? Paths::join($this->options['contentDir']) . '/' : '';
        $files = [];
        foreach (array_slice($this->contentFiles(), 0, 80) as $full) {
            $raw = $this->backend->read($full);
            if (!$raw || strlen($raw['content']) > 2_000_000) {
                continue;
            }
            $files[] = ['path' => str_starts_with($full, $prefix) ? substr($full, strlen($prefix)) : $full, 'text' => $raw['content']];
        }
        $r = Infer::schema($files);
        if ($this->scanFiles !== null) {
            // Site fait main : on ne garde que les listes de produits et les réglages simples (ouverture…) des mêmes fichiers.
            $r['schema']['sections'] = array_values(array_filter($r['schema']['sections'], fn ($s) => self::isProductSection($s) || self::isPageTexts($s)
                || ($s['kind'] === 'singleton' && !empty($s['source']['path']) && array_filter($s['fields'], fn ($f) => $f['type'] === 'boolean'))));
            // Pages détachées (projet supprimé…) : plus aucune page n'y mène, on ne les propose plus.
            $linked = [];
            foreach ($files as $f) {
                if (Formats::fromPath($f['path']) === 'html') {
                    foreach (HtmlCards::pageLinks($f['text']) as $href) {
                        try {
                            if (($t = $this->linkTarget($f['path'], $href)) !== $f['path']) {
                                $linked[$t] = true;
                            }
                        } catch (\InvalidArgumentException) {
                        }
                    }
                }
            }
            $r['schema']['sections'] = array_values(array_filter($r['schema']['sections'], fn ($s) => !self::isPageTexts($s)
                || preg_match(self::HOME_PAGE, $s['source']['file']) || isset($linked[$s['source']['file']])));
            if ($logo = $this->logoSection($files)) {
                $r['schema']['sections'][] = $logo;
            }
            // Ordre : ouvert/fermé, puis les listes (produits…), puis les textes des pages (accueil d'abord).
            $rank = fn ($s) => self::isOpenSwitch($s) || ($s['source']['type'] ?? '') === 'logo' ? 0 : (self::isPageTexts($s) ? (preg_match(self::HOME_PAGE, $s['source']['file']) ? 2 : 3) : 1);
            usort($r['schema']['sections'], fn ($a, $b) => $rank($a) <=> $rank($b));
            foreach ($r['schema']['sections'] as &$s) {
                if (self::isOpenSwitch($s)) {
                    $s['label'] = 'Ouverture du site';
                }
            }
            unset($s);
            $notes = ['Produits trouvés dans : ' . implode(', ', $this->scanFiles) . '.'];
        }
        if ($r['skipped']) {
            $notes[] = 'Fichiers ignorés : ' . implode(', ', array_slice($r['skipped'], 0, 10)) . (count($r['skipped']) > 10 ? '…' : '');
        }
        if (!$r['schema']['sections']) {
            $notes[] = "Aucune rubrique modifiable n'a été reconnue.";
        }
        return ['schema' => $r['schema'] + ['contentDir' => $this->options['contentDir'], 'media' => $this->options['media']], 'notes' => $notes];
    }

    // ------------------------------------------------------------ lecture

    /** Lit et analyse un fichier, en retenant sa version pour l'écriture. */
    private function file(string $path, string $format): ?array
    {
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }
        $raw = $this->backend->read($path);
        $this->versions[$path] = $raw['version'] ?? null;
        if (!$raw) {
            return $this->cache[$path] = null;
        }
        try {
            return $this->cache[$path] = Formats::parse($raw['content'], $format);
        } catch (\Throwable $e) {
            throw new AdapterError('invalid', 'Un fichier de contenu du site est mal formé ; votre administrateur doit le corriger.', "$path: " . $e->getMessage());
        }
    }

    private function resetReads(): void
    {
        $this->versions = [];
        $this->cache = [];
    }

    private function source(array $section): array
    {
        if (empty($section['source'])) {
            throw new AdapterError('invalid', "Cette rubrique n'est pas reliée à un fichier du site.", $section['key']);
        }
        return $section['source'];
    }

    private function fileFormat(array $src): string
    {
        return $src['format'] ?? (Formats::fromPath($src['file']) ?? 'json');
    }

    private function itemId(array $section, array $item, int $i): string
    {
        $k = $section['idField'] ?? null;
        return $k && isset($item[$k]) && $item[$k] !== '' ? (string) $item[$k] : "n$i";
    }

    /** @return array{entries: array, raw: array, files: array<string, string>} */
    private function readCollection(array $section): array
    {
        $this->init();
        $src = $this->source($section);
        if ($src['type'] === 'file') {
            $path = $this->path($src['file']);
            $parsed = $this->file($path, $this->fileFormat($src));
            if (!$parsed) {
                throw new AdapterError('not_found', 'Le fichier de cette rubrique est introuvable sur le site.', $path);
            }
            $list = Paths::get($parsed['data'], $src['path'] ?? null);
            if ($list !== null && !(is_array($list) && array_is_list($list))) {
                throw new AdapterError('invalid', "Le contenu de cette rubrique n'a pas la forme attendue.", $path);
            }
            $raw = array_map(fn ($i) => (array) $i, $list ?? []);
            $entries = [];
            foreach ($raw as $i => $item) {
                $entries[] = ['id' => $this->itemId($section, $item, $i), 'data' => $item];
            }
            return ['entries' => $entries, 'raw' => $raw, 'files' => []];
        }
        $folder = $this->path($src['folder']);
        $ext = $src['extension'] ?? ($src['format'] === 'markdown' ? '.md' : '.' . $src['format']);
        $entries = [];
        $files = [];
        foreach ($this->backend->list($folder, 1) as $p) {
            if (!str_ends_with($p, $ext) || str_starts_with(basename($p), '_')) {
                continue;
            }
            $parsed = $this->file($p, $src['format']);
            if (!$parsed) {
                continue;
            }
            $id = substr(basename($p), 0, -strlen($ext));
            $files[$id] = $p;
            $data = (array) $parsed['data'];
            if ($src['format'] === 'markdown') {
                // Les retours à la ligne finaux ne comptent pas : sinon une relecture ne serait jamais « identique ».
                $data[$src['bodyField'] ?? 'body'] = rtrim($parsed['body'] ?? '');
            }
            $entries[] = ['id' => $id, 'data' => $data];
        }
        if (!empty($src['orderField'])) {
            $k = $src['orderField'];
            usort($entries, fn ($a, $b) => ($a['data'][$k] ?? 9999) <=> ($b['data'][$k] ?? 9999));
        } else {
            usort($entries, fn ($a, $b) => strcmp($a['id'], $b['id']));
        }
        return ['entries' => $entries, 'raw' => array_column($entries, 'data'), 'files' => $files];
    }

    public function listEntries(array $section): array
    {
        $this->resetReads();
        return array_map(fn ($e) => ['id' => $e['id'], 'data' => Values::pick($section, $e['data'])], $this->readCollection($section)['entries']);
    }

    public function getEntry(array $section, string $id): ?array
    {
        foreach ($this->listEntries($section) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        return null;
    }

    public function getSingleton(array $section): array
    {
        $this->init();
        $this->resetReads();
        $src = $this->source($section);
        if ($src['type'] === 'logo') {
            return Values::pick($section, $this->currentLogo()['values']);
        }
        $parsed = $this->file($this->path($src['file']), $this->fileFormat($src));
        if (!$parsed) {
            throw new AdapterError('not_found', 'Le fichier de cette rubrique est introuvable sur le site.', $src['file']);
        }
        $v = Paths::get($parsed['data'], $src['path'] ?? null);
        return Values::pick($section, is_array($v) ? $v : []);
    }

    public function template(array $section): array
    {
        // Un nouvel élément est visible sur le site par défaut (case « disponible », « visible »… déjà cochée).
        $visible = ContentSchema::visibilityField($section);
        $out = $visible ? [$visible => true] : [];
        // Liste de choix (catégorie…) : premier choix proposé, pour que le nouvel élément s'affiche quelque part.
        foreach ($section['fields'] as $f) {
            if ($f['type'] === 'select' && !empty($f['options']) && empty($f['hidden'])) {
                $out[$f['key']] = $f['options'][0]['value'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ écriture

    /** Photos en attente : chemin dans le site + valeur à écrire dans le contenu. */
    private function planAssets(array $data, array $ctx): array
    {
        $tokens = Values::mediaTokens($data);
        if (!$tokens) {
            return [$data, []];
        }
        $map = [];
        $writes = [];
        foreach ($tokens as $token) {
            $asset = PendingAsset::find($ctx['assets'], $token);
            $writes[] = ['path' => Paths::join($this->options['media']['dir'], $asset->fileName), 'content' => $asset->bytes];
            $map[$token] = rtrim($this->options['media']['publicPrefix'], '/') . '/' . $asset->fileName;
        }
        return [Values::replaceMedia($data, $map), $writes];
    }

    /**
     * Lit, construit les écritures, puis écrit en une fois ; recommence si le site a bougé entre-temps.
     * @param callable(): array{0: array, 1: array} $build renvoie [écritures, résultat]
     */
    private function run(array $ctx, callable $build): array
    {
        $this->init();
        $last = null;
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $this->resetReads();
            [$writes, $result] = $build();
            $expected = [];
            $mediaDir = Paths::join($this->options['media']['dir']);
            foreach ($writes as $w) {
                if (array_key_exists($w['path'], $this->versions)) {
                    $expected[$w['path']] = $this->versions[$w['path']];
                } elseif ($w['content'] !== null && !str_starts_with($w['path'], $mediaDir . '/')) {
                    $expected[$w['path']] = null;
                }
            }
            try {
                $ref = $this->backend->commit($writes, $ctx['summary'] . "\n\nPar {$ctx['author']} via Simple Commerce\nSimpleCommerce-Change: {$ctx['changeId']}", $expected);
                return $result + ['ref' => $ref];
            } catch (RetryableConflict $e) {
                $last = $e;
            }
        }
        throw $last ?? new ConflictError();
    }

    private function assertExpected(array $section, ?array $current, ?array $expected): void
    {
        if ($expected === null) {
            return;
        }
        if ($current === null || !Values::equal(Values::pick($section, $current), Values::pick($section, $expected))) {
            throw new ConflictError("rubrique {$section['key']}");
        }
    }

    private function buildItem(array $section, array $data, mixed $id = null): array
    {
        $item = [];
        if (!empty($section['idField']) && $id !== null) {
            $item[$section['idField']] = $id;
        }
        foreach ($section['fields'] as $f) {
            if (array_key_exists($f['key'], $data) && $f['key'] !== ($section['idField'] ?? null)) {
                $item[$f['key']] = $data[$f['key']];
            }
        }
        return $item;
    }

    private function newId(array $section, array $entries, array $data, array $raw): mixed
    {
        $k = $section['idField'] ?? null;
        if (!$k) {
            return null;
        }
        $ids = array_column($entries, 'id');
        if ($ids && !array_filter($ids, fn ($i) => !ctype_digit($i))) {
            $next = max(array_map('intval', $ids)) + 1;
            return is_int($raw[0][$k] ?? null) ? $next : (string) $next;
        }
        $base = Text::slugify((string) ($data[$section['titleField'] ?? ''] ?? $section['itemLabel'] ?? 'element'));
        $id = $base;
        $n = 2;
        while (in_array($id, $ids, true)) {
            $id = $base . '-' . $n++;
        }
        return $id;
    }

    // ------------------------------------------------------------ logo du site

    /** Adresse d'une image écrite dans une page → chemin depuis la racine du site (null si adresse extérieure). */
    private static function rootPath(string $page, string $src): ?string
    {
        $src = preg_replace('/[?#].*$/', '', trim($src));
        if ($src === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $src)) {
            return null;
        }
        $parts = str_starts_with($src, '/') ? [] : array_filter(explode('/', dirname($page)), fn ($p) => $p !== '.' && $p !== '');
        foreach (explode('/', ltrim($src, '/')) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return implode('/', $parts);
    }

    /** Adresse à écrire dans une page pour un chemin depuis la racine du site. */
    private static function fromPage(string $page, string $value): string
    {
        if ($value === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|/)#i', $value)) {
            return $value;
        }
        $depth = count(array_filter(explode('/', dirname($page)), fn ($p) => $p !== '.' && $p !== ''));
        return str_repeat('../', $depth) . $value;
    }

    /** @return string[] pages HTML du site */
    private function htmlPages(): array
    {
        $pages = array_values(array_filter($this->backend->list($this->options['contentDir'], 3), fn ($f) => Formats::fromPath($f) === 'html' && !preg_match(self::SKIP_SCAN, $f)));
        usort($pages, fn ($a, $b) => [!preg_match(self::HOME_PAGE, $a), substr_count($a, '/'), $a] <=> [!preg_match(self::HOME_PAGE, $b), substr_count($b, '/'), $b]);
        return array_slice($pages, 0, 200);
    }

    /** Rubrique « Logo du site », si une page en montre un. @param array<int, array{path: string, text: string}> $files */
    private function logoSection(array $files): ?array
    {
        $pages = array_filter($files, fn ($f) => Formats::fromPath($f['path']) === 'html');
        usort($pages, fn ($a, $b) => !preg_match(self::HOME_PAGE, $a['path']) <=> !preg_match(self::HOME_PAGE, $b['path']));
        foreach ($pages as $f) {
            $l = HtmlCards::logo($f['text']);
            if ($l['img'] === null && $l['icon'] === null) {
                continue;
            }
            $fields = [];
            if ($l['img'] !== null) {
                $fields[] = ['key' => 'logo', 'label' => 'Logo', 'type' => 'image', 'required' => true, 'aspect' => 'libre', 'maxWidth' => 1200,
                    'help' => 'Remplacé sur toutes les pages du site. Image PNG, JPG ou WebP, de préférence sur fond transparent.'];
                if ($l['text'] !== null) {
                    $fields[] = ['key' => 'nom', 'label' => 'Texte à côté du logo', 'type' => 'text', 'maxLength' => 40];
                }
            }
            if ($l['icon'] !== null) {
                $fields[] = ['key' => 'icone', 'label' => "Petite icône de l'onglet", 'type' => 'image', 'required' => true, 'aspect' => '1:1', 'maxWidth' => 512,
                    'help' => "L'image carrée affichée dans l'onglet du navigateur et les favoris."];
            }
            return ['key' => 'logo-du-site', 'label' => 'Logo du site', 'kind' => 'singleton', 'source' => ['type' => 'logo'], 'fields' => $fields];
        }
        return null;
    }

    /** Logo actuel (lu sur la page d'accueil) : valeurs du formulaire et chemins depuis la racine du site. */
    private function currentLogo(): array
    {
        foreach ($this->htmlPages() as $page) {
            $raw = $this->backend->read($page);
            $l = $raw ? HtmlCards::logo($raw['content']) : ['img' => null, 'icon' => null, 'text' => null];
            if ($l['img'] === null && $l['icon'] === null) {
                continue;
            }
            $root = fn ($v) => $v === null ? null : (self::rootPath($page, $v) ?? $v);
            // Valeur affichée : adresse depuis la racine du site (« /… » et adresses complètes gardées telles quelles).
            $shown = fn ($v) => $v === null ? null : (preg_match('#^([a-z][a-z0-9+.-]*:|/)#i', $v) ? $v : $root($v));
            return ['values' => array_filter(['logo' => $shown($l['img']), 'nom' => $l['text'], 'icone' => $shown($l['icon'])], fn ($v) => $v !== null),
                'img' => $root($l['img']), 'icon' => $root($l['icon']), 'text' => $l['text']];
        }
        throw new AdapterError('not_found', "Le logo n'a pas été retrouvé dans les pages du site.");
    }

    /** Remplace le logo (et l'icône, le texte) dans toutes les pages du site, en une seule écriture. */
    private function writeLogo(array $section, array $data, ?array $expected, array $writes): array
    {
        $cur = $this->currentLogo();
        $current = Values::pick($section, $cur['values']);
        $this->assertExpected($section, $current, $expected);
        $new = [];
        foreach (['logo' => 'img', 'icone' => 'icon', 'nom' => 'text'] as $field => $k) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '' && $data[$field] !== ($cur['values'][$field] ?? null)) {
                $new[$k] = $data[$field];
            }
        }
        if (isset($new['text']) && !isset($new['img'])) {
            $new['img'] = $cur['img']; // le texte se remplace à côté du logo : on réécrit le logo à l'identique
        }
        if ($new) {
            foreach ($this->htmlPages() as $page) {
                $raw = $this->backend->read($page);
                if (!$raw) {
                    continue;
                }
                $same = function (string $src, string $what) use ($page, $cur): bool {
                    $target = $what === 'img' ? $cur['img'] : $cur['icon'];
                    return $target !== null && (self::rootPath($page, $src) ?? $src) === $target;
                };
                $html = HtmlCards::replaceLogo($raw['content'], ['text' => $cur['text']], $new, $same, fn ($v) => self::fromPage($page, $v));
                if ($html !== $raw['content']) {
                    $this->versions[$page] = $raw['version'];
                    $writes[] = ['path' => $page, 'content' => $html];
                }
            }
        }
        $after = array_replace($current, array_intersect_key($data, $current + array_flip(['logo', 'nom', 'icone'])));
        return [$writes, ['before' => $current, 'after' => Values::pick($section, $after)]];
    }

    /**
     * Ajoute (ou retire) une balise <script src="…"> à toutes les pages HTML du site, en une seule écriture
     * (statistiques de visite). Le reste des pages n'est pas touché. @return array{changed: int, pages: int}
     */
    public function setPageScript(string $src, bool $on, string $summary): array
    {
        $this->init();
        $tag = '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" defer></script>';
        $re = '#[ \t]*<script\b[^>]*\bsrc=["\']' . preg_quote(htmlspecialchars($src, ENT_QUOTES), '#') . '["\'][^>]*>\s*</script>[ \t]*\r?\n?#i';
        $writes = [];
        $expected = [];
        $pages = $this->htmlPages();
        foreach ($pages as $page) {
            $raw = $this->backend->read($page);
            if (!$raw) {
                continue;
            }
            $html = $raw['content'];
            $has = (bool) preg_match($re, $html);
            if ($on && !$has) {
                $pos = strripos($html, '</body>');
                $html = $pos === false ? $html . "\n" . $tag . "\n" : substr($html, 0, $pos) . $tag . "\n" . substr($html, $pos);
            } elseif (!$on && $has) {
                $html = (string) preg_replace($re, '', $html);
            }
            if ($html !== $raw['content']) {
                $writes[] = ['path' => $page, 'content' => $html];
                $expected[$page] = $raw['version'];
            }
        }
        if ($writes) {
            $this->backend->commit($writes, "$summary\n\nvia Simple Commerce", $expected);
        }
        return ['changed' => count($writes), 'pages' => count($pages)];
    }

    /** Chemin d'un lien de page, depuis le fichier qui le contient. */
    private function linkTarget(string $fromFile, string $href): string
    {
        $dir = dirname($fromFile);
        return Paths::join($dir === '.' ? '' : $dir, $href);
    }

    /** Une autre page du site renvoie-t-elle vers $file ? (Sinon c'est une page détachée, par exemple d'un projet supprimé.) */
    private function isLinked(string $file): bool
    {
        foreach ($this->backend->list($this->options['contentDir'], 3) as $f) {
            if ($f === $file || Formats::fromPath($f) !== 'html' || preg_match(self::SKIP_SCAN, $f)) {
                continue;
            }
            $raw = $this->backend->read($f);
            foreach ($raw ? HtmlCards::pageLinks($raw['content']) : [] as $href) {
                try {
                    if ($this->linkTarget($f, $href) === $file) {
                        return true;
                    }
                } catch (\InvalidArgumentException) {
                }
            }
        }
        return false;
    }

    /**
     * Fiche ajoutée dans une page HTML : si les fiches mènent chacune à leur page (« projet-zohar.html »),
     * la nouvelle fiche reçoit sa propre page, copiée sur celle de la fiche d'origine avec le nouveau nom.
     * Une page détachée du même nom (fiche supprimée puis remise) est reprise telle quelle.
     */
    private function addDetailPage(array $section, array $item, array &$writes): ?string
    {
        $src = $this->source($section);
        $path = $this->path($src['file']);
        $parsed = $this->file($path, 'html');
        $cards = $parsed['style']['groups'][$src['path'] ?? '']['cards'] ?? [];
        if (!$cards) {
            return null;
        }
        $html = $parsed['style']['source'];
        $last = $cards[count($cards) - 1];
        $link = HtmlCards::cardLink($html, $last);
        if ($link === null) {
            return null;
        }
        try {
            $sourcePath = $this->linkTarget($path, $link);
        } catch (\InvalidArgumentException) {
            return null;
        }
        // Seules les pages HTML simples sont recopiées : jamais un fichier PHP (ni une page qui contient du code PHP),
        // où un nom de produit bien choisi pourrait devenir du code exécuté sur l'hébergement.
        if (!preg_match('/\.html?$/i', $sourcePath)) {
            return null;
        }
        $source = $this->backend->read($sourcePath);
        if (!$source || str_contains($source['content'], '<?')) {
            return null;
        }
        $oldName = HtmlCards::values($html, $last)['nom'] ?? '';
        $newName = trim((string) ($item[$section['titleField'] ?? 'nom'] ?? ''));
        $oldSlug = Text::slugify($oldName);
        $newSlug = Text::slugify($newName) ?: 'nouveau';
        $base = basename($link);
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        $stem = $oldSlug !== '' && str_contains($base, $oldSlug) ? str_replace($oldSlug, $newSlug, substr($base, 0, -strlen($ext) - 1)) : $newSlug;
        $linkDir = dirname($link) === '.' ? '' : dirname($link) . '/';
        for ($n = 1; $n < 50; $n++) {
            $name = $stem . ($n > 1 ? "-$n" : '') . '.' . $ext;
            $target = $this->linkTarget($path, $linkDir . $name);
            $existing = $target === $sourcePath ? $source : $this->backend->read($target);
            if (!$existing) {
                $photo = $item['photo'] ?? null;
                $writes[] = ['path' => $target, 'content' => HtmlCards::copyPage($source['content'], $oldName, $newName, is_string($photo) ? $photo : null,
                    trim((string) ($item['description'] ?? '')), $base, $name)];
                break;
            }
            if ($target !== $sourcePath && !$this->isLinked($target)) {
                break; // page détachée : on la relie de nouveau
            }
        }
        foreach ($writes as &$w) {
            if ($w['path'] === $path) {
                $w['content'] = HtmlCards::relink($w['content'], $last['end'], $link, $linkDir . $name);
            }
        }
        unset($w);
        return $target;
    }

    private function writeCollectionFile(array $section, array $items): array
    {
        $src = $this->source($section);
        $path = $this->path($src['file']);
        $parsed = $this->file($path, $this->fileFormat($src));
        $root = Paths::set($parsed['data'], $src['path'] ?? null, $items);
        return ['path' => $path, 'content' => Formats::serialize($parsed, $root)];
    }

    private function serializeFolderItem(array $src, array $item, ?array $existing): string
    {
        if ($src['format'] === 'markdown') {
            $key = $src['bodyField'] ?? 'body';
            $body = (string) ($item[$key] ?? '');
            unset($item[$key]);
            return $existing ? Formats::serialize($existing, $item, $body) : Formats::create('markdown', $item, $body);
        }
        return $existing ? Formats::serialize($existing, $item) : Formats::create($src['format'], $item);
    }

    public function createEntry(array $section, array $input, array $ctx): array
    {
        $src = $this->source($section);
        return $this->run($ctx, function () use ($section, $input, $ctx, $src) {
            [$data, $writes] = $this->planAssets($input, $ctx);
            ['entries' => $entries, 'raw' => $raw] = $this->readCollection($section);
            if ($src['type'] === 'file') {
                $id = $this->newId($section, $entries, $data, $raw);
                $item = $this->buildItem($section, $data, $id);
                $next = [...$raw, $item];
                $writes[] = $this->writeCollectionFile($section, $next);
                $page = $this->fileFormat($src) === 'html' ? $this->addDetailPage($section, $item, $writes) : null;
                return [$writes, ['id' => $this->itemId($section, $item, count($next) - 1), 'before' => null, 'after' => Values::pick($section, $item)]
                    + ($page ? ['pageCreated' => $page] : [])];
            }
            $ext = $src['extension'] ?? ($src['format'] === 'markdown' ? '.md' : '.' . $src['format']);
            $base = Text::slugify((string) ($data[$section['titleField'] ?? ''] ?? $section['itemLabel'] ?? 'element'));
            $id = $base;
            $n = 2;
            $ids = array_column($entries, 'id');
            while (in_array($id, $ids, true)) {
                $id = $base . '-' . $n++;
            }
            $item = $this->buildItem($section, $data);
            if (!empty($src['orderField'])) {
                $orders = array_map(fn ($e) => (int) ($e['data'][$src['orderField']] ?? 0), $entries);
                $item[$src['orderField']] = $orders ? max($orders) + 1 : 1;
            }
            $writes[] = ['path' => Paths::join($this->path($src['folder']), $id . $ext), 'content' => $this->serializeFolderItem($src, $item, null)];
            return [$writes, ['id' => $id, 'before' => null, 'after' => Values::pick($section, $item)]];
        });
    }

    public function updateEntry(array $section, string $id, array $input, ?array $expected, array $ctx): array
    {
        $src = $this->source($section);
        return $this->run($ctx, function () use ($section, $id, $input, $expected, $ctx, $src) {
            [$data, $writes] = $this->planAssets($input, $ctx);
            ['entries' => $entries, 'raw' => $raw, 'files' => $files] = $this->readCollection($section);
            $index = array_search($id, array_column($entries, 'id'), true);
            if ($index === false) {
                throw new ConflictError("élément $id introuvable");
            }
            $current = $entries[$index]['data'];
            $this->assertExpected($section, $current, $expected);
            $merged = array_replace($current, $data);
            if ($src['type'] === 'file') {
                $raw[$index] = $merged;
                $writes[] = $this->writeCollectionFile($section, $raw);
            } else {
                $path = $files[$id];
                $writes[] = ['path' => $path, 'content' => $this->serializeFolderItem($src, $merged, $this->file($path, $src['format']))];
            }
            return [$writes, ['id' => $id, 'before' => Values::pick($section, $current), 'after' => Values::pick($section, $merged)]];
        });
    }

    /**
     * Plusieurs éléments modifiés (ou supprimés) d'un coup, en une seule écriture.
     * @param array<string, ?array> $items identifiant => nouvelles valeurs (null = supprimer)
     * @return array<string, array{before: ?array, after: ?array}>
     */
    public function changeMany(array $section, array $items, array $ctx): array
    {
        $src = $this->source($section);
        return $this->run($ctx, function () use ($section, $items, $src) {
            ['entries' => $entries, 'raw' => $raw, 'files' => $files] = $this->readCollection($section);
            $ids = array_column($entries, 'id');
            $out = [];
            $writes = [];
            $drop = [];
            foreach ($items as $id => $data) {
                $index = array_search((string) $id, $ids, true);
                if ($index === false) {
                    throw new ConflictError("élément $id introuvable");
                }
                $current = $entries[$index]['data'];
                $merged = $data === null ? null : array_replace($current, $data);
                $out[(string) $id] = ['before' => Values::pick($section, $current), 'after' => $merged === null ? null : Values::pick($section, $merged), 'index' => $index];
                if ($src['type'] === 'file') {
                    if ($merged === null) {
                        $drop[] = $index;
                    } else {
                        $raw[$index] = $merged;
                    }
                } else {
                    $writes[] = ['path' => $files[(string) $id], 'content' => $merged === null ? null : $this->serializeFolderItem($src, $merged, $this->file($files[(string) $id], $src['format']))];
                }
            }
            if ($src['type'] === 'file') {
                foreach ($drop as $i) {
                    unset($raw[$i]);
                }
                $writes[] = $this->writeCollectionFile($section, array_values($raw));
            }
            return [$writes, ['items' => $out]];
        })['items'];
    }

    public function deleteEntry(array $section, string $id, ?array $expected, array $ctx): array
    {
        $src = $this->source($section);
        return $this->run($ctx, function () use ($section, $id, $expected, $src) {
            ['entries' => $entries, 'raw' => $raw, 'files' => $files] = $this->readCollection($section);
            $index = array_search($id, array_column($entries, 'id'), true);
            if ($index === false) {
                throw new ConflictError("élément $id introuvable");
            }
            $current = $entries[$index]['data'];
            $this->assertExpected($section, $current, $expected);
            if ($src['type'] === 'file') {
                array_splice($raw, $index, 1);
                $writes = [$this->writeCollectionFile($section, $raw)];
            } else {
                $writes = [['path' => $files[$id], 'content' => null]];
            }
            return [$writes, ['id' => $id, 'before' => Values::pick($section, $current), 'after' => null, 'index' => $index]];
        });
    }

    public function reorder(array $section, array $orderedIds, array $ctx): array
    {
        $src = $this->source($section);
        return $this->run($ctx, function () use ($section, $orderedIds, $src) {
            ['entries' => $entries, 'raw' => $raw, 'files' => $files] = $this->readCollection($section);
            $before = array_column($entries, 'id');
            $a = $orderedIds;
            $b = $before;
            sort($a);
            sort($b);
            if ($a !== $b) {
                throw new ConflictError('la liste a changé');
            }
            if ($src['type'] === 'file') {
                $next = array_map(fn ($id) => $raw[array_search($id, $before, true)], $orderedIds);
                return [[$this->writeCollectionFile($section, $next)], ['before' => null, 'after' => null, 'beforeOrder' => $before, 'afterOrder' => $orderedIds]];
            }
            if (empty($src['orderField'])) {
                throw new AdapterError('unsupported', "L'ordre de cette rubrique ne peut pas être modifié.");
            }
            $writes = [];
            foreach ($orderedIds as $i => $id) {
                $entry = $entries[array_search($id, $before, true)];
                if ((int) ($entry['data'][$src['orderField']] ?? 0) === $i + 1) {
                    continue;
                }
                $item = $entry['data'];
                $item[$src['orderField']] = $i + 1;
                $writes[] = ['path' => $files[$id], 'content' => $this->serializeFolderItem($src, $item, $this->file($files[$id], $src['format']))];
            }
            return [$writes, ['before' => null, 'after' => null, 'beforeOrder' => $before, 'afterOrder' => $orderedIds]];
        });
    }

    public function updateSingleton(array $section, array $input, ?array $expected, array $ctx): array
    {
        $src = $this->source($section);
        if ($src['type'] !== 'file' && $src['type'] !== 'logo') {
            throw new AdapterError('invalid', 'Rubrique mal configurée.', $section['key']);
        }
        return $this->run($ctx, function () use ($section, $input, $expected, $ctx, $src) {
            [$data, $writes] = $this->planAssets($input, $ctx);
            if ($src['type'] === 'logo') {
                return $this->writeLogo($section, $data, $expected, $writes);
            }
            $path = $this->path($src['file']);
            $parsed = $this->file($path, $this->fileFormat($src));
            if (!$parsed) {
                throw new AdapterError('not_found', 'Le fichier de cette rubrique est introuvable sur le site.', $src['file']);
            }
            $cur = Paths::get($parsed['data'], $src['path'] ?? null);
            $current = is_array($cur) ? $cur : [];
            $this->assertExpected($section, $current, $expected);
            $merged = array_replace($current, $data);
            $writes[] = ['path' => $path, 'content' => Formats::serialize($parsed, Paths::set($parsed['data'], $src['path'] ?? null, $merged))];
            return [$writes, ['before' => Values::pick($section, $current), 'after' => Values::pick($section, $merged)]];
        });
    }

    // ------------------------------------------------------------ fermeture temporaire

    public function getStatus(): ?array
    {
        $this->init();
        $raw = $this->backend->read($this->path(self::STATUS_FILE));
        if (!$raw) {
            return Status::OPEN;
        }
        $data = json_decode($raw['content'], true);
        return Status::fromFile($data);
    }

    public function setStatus(array $status, array $ctx): array
    {
        return $this->run($ctx, function () use ($status) {
            $path = $this->path(self::STATUS_FILE);
            $parsed = $this->file($path, 'json');
            $before = Status::toFile($parsed ? Status::fromFile($parsed['data']) : Status::OPEN);
            $after = Status::toFile($status);
            $text = $parsed ? Formats::serialize($parsed, $after) : Formats::create('json', $after);
            return [[['path' => $path, 'content' => $text]], ['before' => $before, 'after' => $after]];
        });
    }

    public function options(): array
    {
        $this->init();
        return $this->options;
    }
}
