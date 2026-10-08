<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\RetryableConflict;
use SimpleCommerce\Support\Http;

/** GitHub : lecture par l'API Contents, écriture d'un seul commit par l'API Git Data. */
final class GitHubBackend implements FileBackend
{
    private ?array $tree = null;

    public function __construct(private string $owner, private string $repo, private string $branch, private string $token, private string $apiBase = 'https://api.github.com')
    {
    }

    private function api(string $method, string $path, mixed $body = null): array
    {
        $url = rtrim($this->apiBase, '/') . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . $path;
        return Http::json('GitHub', $method, $url, [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ], $body)['data'] ?? [];
    }

    private static function enc(string $p): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $p)));
    }

    public function test(): array
    {
        $checks = [];
        try {
            $repo = $this->api('GET', '');
            $checks[] = ['label' => "Dépôt {$repo['full_name']} trouvé", 'ok' => true];
            $checks[] = (($repo['permissions']['push'] ?? true) === false)
                ? ['label' => "Droit d'écriture", 'ok' => false, 'hint' => "Le jeton peut lire le dépôt mais pas le modifier. Donnez-lui l'autorisation « Contents : Read and write »."]
                : ['label' => "Droit d'écriture", 'ok' => true];
        } catch (AdapterError $e) {
            $checks[] = ['label' => 'Accès au dépôt', 'ok' => false, 'hint' => $e->codeName === 'not_found' ? 'Dépôt introuvable : vérifiez son adresse, et que le jeton a accès à ce dépôt.' : $e->userMessage];
            return $checks;
        }
        try {
            $this->api('GET', '/branches/' . self::enc($this->branch));
            $checks[] = ['label' => "Branche « {$this->branch} » trouvée", 'ok' => true];
        } catch (AdapterError) {
            $checks[] = ['label' => "Branche « {$this->branch} »", 'ok' => false, 'hint' => 'Cette branche n\'existe pas. En général c\'est « main ».'];
        }
        return $checks;
    }

    public function read(string $path, ?string $ref = null): ?array
    {
        try {
            $d = $this->api('GET', '/contents/' . self::enc($path) . '?ref=' . rawurlencode($ref ?? $this->branch));
        } catch (AdapterError $e) {
            if ($e->codeName === 'not_found') {
                return null;
            }
            throw $e;
        }
        if (($d['type'] ?? '') !== 'file') {
            return null;
        }
        if (($d['encoding'] ?? '') === 'base64' && isset($d['content'])) {
            return ['content' => base64_decode($d['content']), 'version' => $d['sha']];
        }
        $blob = $this->api('GET', '/git/blobs/' . $d['sha']);
        return ['content' => base64_decode($blob['content']), 'version' => $d['sha']];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $this->tree ??= $this->api('GET', '/git/trees/' . rawurlencode($this->branch) . '?recursive=1')['tree'] ?? [];
        $prefix = $dir === '' ? '' : rtrim($dir, '/') . '/';
        $out = [];
        foreach ($this->tree as $t) {
            $p = $t['path'];
            if ($t['type'] !== 'blob' || !str_starts_with($p, $prefix) || count(explode('/', substr($p, strlen($prefix)))) > $maxDepth) {
                continue;
            }
            if (array_filter(explode('/', $p), fn ($s) => $s === 'node_modules' || str_starts_with($s, '.'))) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        $head = $this->api('GET', '/git/ref/heads/' . self::enc($this->branch))['object']['sha'];
        foreach ($expected as $path => $version) {
            if (($this->read($path, $head)['version'] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
        }
        $baseTree = $this->api('GET', "/git/commits/$head")['tree']['sha'];
        $tree = [];
        foreach ($writes as $w) {
            if ($w['content'] === null) {
                $tree[] = ['path' => $w['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                continue;
            }
            $blob = $this->api('POST', '/git/blobs', ['content' => base64_encode($w['content']), 'encoding' => 'base64']);
            $tree[] = ['path' => $w['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
        }
        $newTree = $this->api('POST', '/git/trees', ['base_tree' => $baseTree, 'tree' => $tree]);
        $commit = $this->api('POST', '/git/commits', ['message' => $message, 'tree' => $newTree['sha'], 'parents' => [$head]]);
        try {
            $this->api('PATCH', '/git/refs/heads/' . self::enc($this->branch), ['sha' => $commit['sha'], 'force' => false]);
        } catch (AdapterError $e) {
            if (in_array($e->codeName, ['conflict', 'invalid'], true)) {
                throw new RetryableConflict('branche déplacée');
            }
            throw $e;
        }
        $this->tree = null;
        return $commit['sha'];
    }

    public function close(): void
    {
    }
}
