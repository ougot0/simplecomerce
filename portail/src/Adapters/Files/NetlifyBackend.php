<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\RetryableConflict;
use SimpleCommerce\Support\Http;

/**
 * Netlify, pour un site déposé directement (glisser-déposer, sans GitHub) : les fichiers sont lus par l'API,
 * et chaque modification publie une nouvelle version du site (« déploiement » par empreintes SHA1) :
 * on envoie la liste complète des fichiers, Netlify ne demande que ceux qui ont changé.
 */
final class NetlifyBackend implements FileBackend
{
    /** @var array<string, string>|null chemin (sans « / » initial) → empreinte SHA1 */
    private ?array $files = null;
    private ?array $site = null;

    public function __construct(private string $siteId, private string $token, private string $apiBase = 'https://api.netlify.com/api/v1')
    {
    }

    private function url(string $path): string
    {
        return rtrim($this->apiBase, '/') . $path;
    }

    private function api(string $method, string $path, mixed $body = null): mixed
    {
        return Http::json('Netlify', $method, $this->url($path), ['Authorization' => 'Bearer ' . $this->token], $body)['data'];
    }

    private static function enc(string $p): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($p, '/'))));
    }

    private function sitePath(): string
    {
        return '/sites/' . rawurlencode($this->siteId);
    }

    private function site(): array
    {
        return $this->site ??= (array) $this->api('GET', $this->sitePath());
    }

    /** Fonctions Netlify du site : elles ne peuvent pas être renvoyées sans le code source, on refuse donc d'écrire. */
    private function hasFunctions(): bool
    {
        $deploy = $this->site()['published_deploy'] ?? [];
        return !empty($deploy['available_functions']) || !empty($deploy['functions']);
    }

    public function test(): array
    {
        $checks = [];
        try {
            $site = $this->site();
            $name = (string) ($site['custom_domain'] ?? '') ?: (string) ($site['name'] ?? $this->siteId);
            $checks[] = ['label' => "Site Netlify « $name » trouvé", 'ok' => true];
        } catch (AdapterError $e) {
            $checks[] = ['label' => 'Accès au site Netlify', 'ok' => false, 'hint' => match ($e->codeName) {
                'not_found' => "Site introuvable : indiquez son adresse en .netlify.app (visible dans Netlify, en haut de la page du site), et vérifiez que le jeton vient du compte qui possède ce site.",
                'auth' => "Netlify refuse le jeton : recopiez-le, ou créez-en un nouveau (il a peut-être expiré).",
                default => $e->userMessage,
            }];
            return $checks;
        }
        if (!empty($site['build_settings']['repo_url'])) {
            $checks[] = ['label' => 'Site relié à un dépôt de code', 'ok' => false,
                'hint' => "Ce site Netlify se reconstruit depuis GitHub (ou GitLab) : les modifications seraient effacées à la prochaine mise à jour. Choisissez plutôt « Mon site est sur GitHub »."];
        }
        if ($this->hasFunctions()) {
            $checks[] = ['label' => 'Fonctions Netlify', 'ok' => false,
                'hint' => "Ce site utilise des fonctions Netlify, qu'on ne peut pas renvoyer sans son code source. Reliez-le plutôt par son dépôt GitHub."];
        }
        try {
            $count = count($this->fileMap(true));
            $checks[] = $count > 0
                ? ['label' => 'Fichiers du site lus (' . $count . ')', 'ok' => true]
                : ['label' => 'Fichiers du site', 'ok' => false, 'hint' => "Ce site n'a encore aucune version publiée sur Netlify."];
        } catch (AdapterError $e) {
            $checks[] = ['label' => 'Fichiers du site', 'ok' => false, 'hint' => $e->userMessage];
        }
        return $checks;
    }

    /** @return array<string, string> */
    private function fileMap(bool $fresh = false): array
    {
        if ($this->files !== null && !$fresh) {
            return $this->files;
        }
        $files = [];
        for ($page = 1; $page <= 100; $page++) {
            $batch = (array) $this->api('GET', $this->sitePath() . '/files?page=' . $page . '&per_page=100');
            foreach ($batch as $f) {
                if (isset($f['path'], $f['sha'])) {
                    $files[ltrim((string) $f['path'], '/')] = (string) $f['sha'];
                }
            }
            if (count($batch) < 100) {
                break;
            }
        }
        return $this->files = $files;
    }

    public function read(string $path): ?array
    {
        $path = ltrim($path, '/');
        $sha = $this->fileMap()[$path] ?? null;
        if ($sha === null) {
            return null;
        }
        // Contenu brut du fichier tel qu'il a été déposé (et non la version retouchée servie aux visiteurs).
        $res = Http::request('GET', $this->url($this->sitePath() . '/files/' . self::enc($path)), [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/vnd.bitballoon.v1.raw',
            'Accept' => 'application/vnd.bitballoon.v1.raw',
        ]);
        if ($res['status'] === 404) {
            return null;
        }
        if ($res['status'] >= 300) {
            throw Http::error('Netlify', $res['status'], $res['body']);
        }
        return ['content' => $res['body'], 'version' => $sha];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $prefix = $dir === '' ? '' : rtrim(ltrim($dir, '/'), '/') . '/';
        $out = [];
        foreach (array_keys($this->fileMap()) as $p) {
            if (str_starts_with($p, $prefix) && count(explode('/', substr($p, strlen($prefix)))) <= $maxDepth) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        if ($this->hasFunctions()) {
            throw new AdapterError('unsupported', "Ce site utilise des fonctions Netlify : il doit être modifié par son dépôt GitHub.");
        }
        $files = $this->fileMap(true);
        foreach ($expected as $path => $version) {
            if (($files[ltrim($path, '/')] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
        }
        $upload = [];
        foreach ($writes as $w) {
            $p = ltrim($w['path'], '/');
            if ($w['content'] === null) {
                unset($files[$p]);
                continue;
            }
            $files[$p] = sha1($w['content']);
            $upload[$files[$p]][] = ['path' => $p, 'content' => $w['content']];
        }
        // Nouvelle version complète du site : tous les fichiers, avec leur empreinte. Netlify ne réclame que les nouveaux.
        $manifest = [];
        foreach ($files as $p => $sha) {
            $manifest['/' . $p] = $sha;
        }
        $title = mb_substr(strtok($message, "\n") ?: 'Simple Commerce', 0, 200);
        $deploy = (array) $this->api('POST', $this->sitePath() . '/deploys?title=' . rawurlencode($title), ['files' => $manifest]);
        $id = (string) ($deploy['id'] ?? '');
        if ($id === '') {
            throw new AdapterError('remote', 'Netlify n\'a pas accepté la nouvelle version du site.');
        }
        foreach ((array) ($deploy['required'] ?? []) as $sha) {
            foreach ($upload[$sha] ?? [] as $f) {
                $res = Http::request('PUT', $this->url('/deploys/' . rawurlencode($id) . '/files/' . self::enc($f['path'])), [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/octet-stream',
                ], $f['content'], 60);
                if ($res['status'] >= 300) {
                    throw Http::error('Netlify', $res['status'], $res['body']);
                }
                break; // un même contenu n'est envoyé qu'une fois
            }
        }
        // On attend que la nouvelle version soit en ligne (quelques secondes en général).
        for ($i = 0; $i < 20; $i++) {
            $state = (string) (($this->api('GET', '/deploys/' . rawurlencode($id)))['state'] ?? '');
            if ($state === 'ready') {
                break;
            }
            if ($state === 'error') {
                throw new AdapterError('remote', 'Netlify n\'a pas pu publier la nouvelle version du site. Réessayez dans un instant.');
            }
            usleep(1_000_000);
        }
        $this->files = null;
        $this->site = null;
        return $id;
    }

    public function close(): void
    {
    }
}
