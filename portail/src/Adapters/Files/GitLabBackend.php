<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\RetryableConflict;
use SimpleCommerce\Support\Http;

/** GitLab (gitlab.com ou serveur privé) : un commit avec plusieurs actions. */
final class GitLabBackend implements FileBackend
{
    public function __construct(private string $project, private string $branch, private string $token, private string $baseUrl = 'https://gitlab.com')
    {
    }

    private function api(string $method, string $path, mixed $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v4/projects/' . rawurlencode($this->project) . $path;
        return Http::json('GitLab', $method, $url, ['PRIVATE-TOKEN' => $this->token], $body);
    }

    public function test(): array
    {
        $checks = [];
        try {
            $p = $this->api('GET', '')['data'];
            $checks[] = ['label' => "Projet {$p['path_with_namespace']} trouvé", 'ok' => true];
            $level = max((int) ($p['permissions']['project_access']['access_level'] ?? 0), (int) ($p['permissions']['group_access']['access_level'] ?? 0));
            $checks[] = ($level === 0 || $level >= 30) ? ['label' => "Droit d'écriture", 'ok' => true]
                : ['label' => "Droit d'écriture", 'ok' => false, 'hint' => 'Le jeton doit avoir au moins le rôle « Developer » et la portée « api ».'];
        } catch (AdapterError $e) {
            return [['label' => 'Accès au projet', 'ok' => false, 'hint' => $e->userMessage]];
        }
        try {
            $this->api('GET', '/repository/branches/' . rawurlencode($this->branch));
            $checks[] = ['label' => "Branche « {$this->branch} » trouvée", 'ok' => true];
        } catch (AdapterError) {
            $checks[] = ['label' => "Branche « {$this->branch} »", 'ok' => false, 'hint' => "Cette branche n'existe pas."];
        }
        return $checks;
    }

    public function read(string $path): ?array
    {
        try {
            $d = $this->api('GET', '/repository/files/' . rawurlencode($path) . '?ref=' . rawurlencode($this->branch))['data'];
            return ['content' => base64_decode($d['content']), 'version' => $d['blob_id']];
        } catch (AdapterError $e) {
            if ($e->codeName === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $out = [];
        for ($page = 1; $page <= 20; $page++) {
            $r = $this->api('GET', '/repository/tree?recursive=true&per_page=100&page=' . $page . '&ref=' . rawurlencode($this->branch) . ($dir !== '' ? '&path=' . rawurlencode($dir) : ''));
            foreach ($r['data'] ?? [] as $item) {
                if ($item['type'] === 'blob') {
                    $out[] = $item['path'];
                }
            }
            if (empty($r['headers']['x-next-page'])) {
                break;
            }
        }
        $prefix = $dir === '' ? '' : rtrim($dir, '/') . '/';
        return array_values(array_filter($out, fn ($p) => count(explode('/', substr($p, strlen($prefix)))) <= $maxDepth && !array_filter(explode('/', $p), fn ($s) => str_starts_with($s, '.'))));
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        $exists = [];
        foreach ($expected as $path => $version) {
            $cur = $this->read($path);
            if (($cur['version'] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
            $exists[$path] = $cur !== null;
        }
        $actions = [];
        foreach ($writes as $w) {
            if ($w['content'] === null) {
                $actions[] = ['action' => 'delete', 'file_path' => $w['path']];
                continue;
            }
            $known = $exists[$w['path']] ?? ($this->read($w['path']) !== null);
            $actions[] = ['action' => $known ? 'update' : 'create', 'file_path' => $w['path'], 'content' => base64_encode($w['content']), 'encoding' => 'base64'];
        }
        try {
            return (string) ($this->api('POST', '/repository/commits', ['branch' => $this->branch, 'commit_message' => $message, 'actions' => $actions])['data']['id'] ?? '');
        } catch (AdapterError $e) {
            if ($e->codeName === 'invalid') {
                throw new RetryableConflict($e->detail);
            }
            throw $e;
        }
    }

    public function close(): void
    {
    }
}
