<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\RetryableConflict;
use SimpleCommerce\Support\Http;

/** Bitbucket Cloud : écriture de plusieurs fichiers en un commit par l'API /src. */
final class BitbucketBackend implements FileBackend
{
    public function __construct(private string $workspace, private string $repo, private string $branch, private string $token, private ?string $username = null)
    {
    }

    private function base(): string
    {
        return 'https://api.bitbucket.org/2.0/repositories/' . rawurlencode($this->workspace) . '/' . rawurlencode($this->repo);
    }

    private function auth(): array
    {
        return ['Authorization' => $this->username ? 'Basic ' . base64_encode($this->username . ':' . $this->token) : 'Bearer ' . $this->token];
    }

    public function test(): array
    {
        try {
            $r = Http::json('Bitbucket', 'GET', $this->base(), $this->auth())['data'];
            Http::json('Bitbucket', 'GET', $this->base() . '/refs/branches/' . rawurlencode($this->branch), $this->auth());
            return [['label' => "Dépôt {$r['full_name']} trouvé", 'ok' => true], ['label' => "Branche « {$this->branch} » trouvée", 'ok' => true]];
        } catch (AdapterError $e) {
            return [['label' => 'Accès au dépôt', 'ok' => false, 'hint' => $e->userMessage]];
        }
    }

    public function read(string $path): ?array
    {
        $r = Http::request('GET', $this->base() . '/src/' . rawurlencode($this->branch) . '/' . implode('/', array_map('rawurlencode', explode('/', $path))), $this->auth());
        if ($r['status'] === 404) {
            return null;
        }
        if ($r['status'] >= 300) {
            throw Http::error('Bitbucket', $r['status'], $r['body']);
        }
        return ['content' => $r['body'], 'version' => hash('sha256', $r['body'])];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $out = [];
        $url = $this->base() . '/src/' . rawurlencode($this->branch) . '/' . ($dir !== '' ? rtrim($dir, '/') . '/' : '') . '?max_depth=' . $maxDepth . '&pagelen=100';
        for ($i = 0; $url && $i < 20; $i++) {
            $page = Http::json('Bitbucket', 'GET', $url, $this->auth())['data'];
            foreach ($page['values'] ?? [] as $v) {
                if ($v['type'] === 'commit_file') {
                    $out[] = $v['path'];
                }
            }
            $url = $page['next'] ?? null;
        }
        return array_values(array_filter($out, fn ($p) => !array_filter(explode('/', $p), fn ($s) => str_starts_with($s, '.'))));
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        foreach ($expected as $path => $version) {
            if (($this->read($path)['version'] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
        }
        $form = ['message' => $message, 'branch' => $this->branch];
        $deleted = [];
        foreach ($writes as $i => $w) {
            if ($w['content'] === null) {
                $deleted[] = $w['path'];
            } else {
                $form[$w['path']] = new \CURLStringFile($w['content'], basename($w['path']));
            }
        }
        if ($deleted) {
            $form['files'] = implode(',', $deleted);
        }
        $r = Http::request('POST', $this->base() . '/src', $this->auth(), $form, 30);
        if ($r['status'] >= 300) {
            throw Http::error('Bitbucket', $r['status'], $r['body']);
        }
        return basename($r['headers']['location'] ?? '');
    }

    public function close(): void
    {
    }
}
