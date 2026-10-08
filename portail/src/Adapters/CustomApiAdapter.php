<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Content\Values;
use SimpleCommerce\Support\Http;

/**
 * « API sur mesure » : pour les sites qui ont leur propre serveur et base de données (PHP, Laravel, Symfony, Node, Django…).
 * Le site expose quelques adresses (docs/API-SUR-MESURE.md) ; exemple prêt à l'emploi dans examples/.
 */
final class CustomApiAdapter extends BaseAdapter
{
    private string $base;

    public function __construct(string $baseUrl, private string $secret)
    {
        $this->base = rtrim($baseUrl, '/');
    }

    private function call(string $method, string $path, mixed $body = null, bool $conflictAware = false): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secret, 'Accept' => 'application/json'];
        if (is_array($body) && !array_filter($body, fn ($v) => $v instanceof \CURLStringFile)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $r = Http::request($method, $this->base . $path, $headers, $body);
        if ($conflictAware && $r['status'] === 409) {
            throw new ConflictError();
        }
        if ($r['status'] >= 300) {
            throw Http::error('Le site', $r['status'], $r['body']);
        }
        $d = $r['body'] === '' ? [] : json_decode($r['body'], true);
        if (!is_array($d)) {
            throw new AdapterError('remote', 'Le site a renvoyé une réponse illisible.', substr($r['body'], 0, 200));
        }
        return $d;
    }

    public function capabilities(array $section): array
    {
        $col = $section['kind'] === 'collection';
        return ['reorder' => $col && ($section['orderable'] ?? true) !== false, 'create' => $col && ($section['allowCreate'] ?? true) !== false, 'delete' => $col && ($section['allowDelete'] ?? true) !== false];
    }

    public function test(): array
    {
        try {
            $ping = $this->call('GET', '/ping');
            $checks = [['label' => isset($ping['name']) ? "Site « {$ping['name']} » joint" : 'Site joint', 'ok' => ($ping['ok'] ?? true) !== false]];
            try {
                $schema = ContentSchema::parse($this->call('GET', '/schema'));
                $checks[] = ['label' => count($schema['sections']) . ' rubrique(s) décrite(s) par le site', 'ok' => true];
            } catch (\InvalidArgumentException $e) {
                $checks[] = ['label' => 'Description des rubriques', 'ok' => false, 'hint' => 'Le site renvoie une description des rubriques invalide : ' . $e->getMessage()];
            }
        } catch (AdapterError $e) {
            $checks = [['label' => 'Connexion au site', 'ok' => false, 'hint' => $e->userMessage]];
        }
        return ['ok' => !array_filter($checks, fn ($c) => !$c['ok']), 'checks' => $checks];
    }

    public function discover(): array
    {
        return ['schema' => ContentSchema::parse($this->call('GET', '/schema')), 'notes' => ['Rubriques fournies par le site.']];
    }

    private function media(array $data, array $ctx): array
    {
        $map = [];
        foreach (Values::mediaTokens($data) as $token) {
            $a = PendingAsset::find($ctx['assets'], $token);
            $map[$token] = $this->call('POST', '/media', ['file' => new \CURLStringFile($a->bytes, $a->fileName, $a->mime), 'alt' => $a->alt])['url'];
        }
        return $map ? Values::replaceMedia($data, $map) : $data;
    }

    private function path(array $section): string
    {
        return '/sections/' . rawurlencode($section['key']);
    }

    public function listEntries(array $section): array
    {
        return array_map(fn ($e) => ['id' => (string) $e['id'], 'data' => $e['data'] ?? []], $this->call('GET', $this->path($section))['entries'] ?? []);
    }

    public function getEntry(array $section, string $id): ?array
    {
        try {
            $e = $this->call('GET', $this->path($section) . '/entries/' . rawurlencode($id));
            return ['id' => (string) ($e['id'] ?? $id), 'data' => $e['data'] ?? []];
        } catch (AdapterError $e) {
            if ($e->codeName === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    public function createEntry(array $section, array $data, array $ctx): array
    {
        $e = $this->call('POST', $this->path($section) . '/entries', ['data' => $this->media($data, $ctx), 'author' => $ctx['author']]);
        return ['id' => (string) $e['id'], 'before' => null, 'after' => $e['data'] ?? $data];
    }

    public function updateEntry(array $section, string $id, array $data, ?array $expected, array $ctx): array
    {
        $before = $this->getEntry($section, $id)['data'] ?? throw new ConflictError('élément supprimé');
        $e = $this->call('PUT', $this->path($section) . '/entries/' . rawurlencode($id), ['data' => $this->media($data, $ctx), 'expected' => $expected, 'author' => $ctx['author']], true);
        return ['id' => $id, 'before' => $before, 'after' => $e['data'] ?? array_replace($before, $data)];
    }

    public function deleteEntry(array $section, string $id, ?array $expected, array $ctx): array
    {
        $before = $this->getEntry($section, $id)['data'] ?? throw new ConflictError('élément déjà supprimé');
        $this->call('DELETE', $this->path($section) . '/entries/' . rawurlencode($id), ['expected' => $expected, 'author' => $ctx['author']], true);
        return ['id' => $id, 'before' => $before, 'after' => null];
    }

    public function reorder(array $section, array $orderedIds, array $ctx): array
    {
        $before = array_column($this->listEntries($section), 'id');
        $this->call('PUT', $this->path($section) . '/order', ['ids' => $orderedIds, 'author' => $ctx['author']], true);
        return ['before' => null, 'after' => null, 'beforeOrder' => $before, 'afterOrder' => $orderedIds];
    }

    public function getSingleton(array $section): array
    {
        return $this->call('GET', $this->path($section))['data'] ?? [];
    }

    public function updateSingleton(array $section, array $data, ?array $expected, array $ctx): array
    {
        $before = $this->getSingleton($section);
        $r = $this->call('PUT', $this->path($section), ['data' => $this->media($data, $ctx), 'expected' => $expected, 'author' => $ctx['author']], true);
        return ['before' => $before, 'after' => $r['data'] ?? array_replace($before, $data)];
    }

    public function getStatus(): ?array
    {
        try {
            return Status::fromFile($this->call('GET', '/status'));
        } catch (AdapterError $e) {
            if ($e->codeName === 'not_found') {
                return Status::OPEN;
            }
            throw $e;
        }
    }

    public function setStatus(array $status, array $ctx): array
    {
        $before = Status::toFile($this->getStatus() ?? Status::OPEN);
        $after = Status::toFile($status);
        $this->call('PUT', '/status', $after + ['author' => $ctx['author']]);
        return ['before' => $before, 'after' => $after];
    }
}
