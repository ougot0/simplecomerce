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
        }
        if ($needMedia) {
            $has = fn ($p) => (bool) array_filter($files, fn ($f) => str_starts_with($f, "$p/"));
            $base = $this->options['webRoot'] ? '' : ($has('public') ? 'public' : ($has('static') ? 'static' : ''));
            $this->options['media'] = ['dir' => $base ? "$base/images/simplecommerce" : 'images/simplecommerce', 'publicPrefix' => '/images/simplecommerce'];
        }
    }

    private function path(string $rel): string
    {
        return Paths::join($this->options['contentDir'], $rel);
    }

    /** @return string[] */
    private function contentFiles(): array
    {
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
                    ? ['label' => 'Fichiers de contenu trouvés (' . count($files) . ')', 'ok' => true]
                    : ['label' => 'Fichiers de contenu', 'ok' => false, 'hint' => "Aucun fichier de contenu trouvé ($where). Le contenu du site doit d'abord être rangé dans un fichier comme content.json : voir le guide « Préparer un site »."];
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
        $parsed = $this->file($this->path($src['file']), $this->fileFormat($src));
        if (!$parsed) {
            throw new AdapterError('not_found', 'Le fichier de cette rubrique est introuvable sur le site.', $src['file']);
        }
        $v = Paths::get($parsed['data'], $src['path'] ?? null);
        return Values::pick($section, is_array($v) ? $v : []);
    }

    public function template(array $section): array
    {
        return [];
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
                return [$writes, ['id' => $this->itemId($section, $item, count($next) - 1), 'before' => null, 'after' => Values::pick($section, $item)]];
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
        if ($src['type'] !== 'file') {
            throw new AdapterError('invalid', 'Rubrique mal configurée.', $section['key']);
        }
        return $this->run($ctx, function () use ($section, $input, $expected, $ctx, $src) {
            [$data, $writes] = $this->planAssets($input, $ctx);
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
