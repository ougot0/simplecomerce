<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Adapters\Files\BitbucketBackend;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Adapters\Files\FtpBackend;
use SimpleCommerce\Adapters\Files\GitHubBackend;
use SimpleCommerce\Adapters\Files\GitLabBackend;
use SimpleCommerce\Adapters\Files\LocalBackend;
use SimpleCommerce\Adapters\Files\SftpBackend;
use SimpleCommerce\Config;
use SimpleCommerce\Content\Paths;
use SimpleCommerce\Support\Net;

/** Valeurs du formulaire → réglages + secrets ; réglages enregistrés → connecteur prêt à l'emploi. */
final class Registry
{
    /**
     * @param array<string, string> $values saisies (sans préfixe)
     * @return array{config: array, secrets: array, errors: array<string, string>}
     */
    public static function parse(string $id, array $values, ?array $existingSecrets): array
    {
        $def = Catalog::get($id);
        if (!$def) {
            throw new AdapterError('invalid', 'Type de site inconnu.');
        }
        $config = [];
        $secrets = $existingSecrets ?? [];
        $errors = [];
        foreach ($def['fields'] as $f) {
            $raw = (string) ($values[$f['name']] ?? '');
            $v = $f['type'] === 'textarea' ? trim(str_replace("\r\n", "\n", $raw)) : trim($raw);
            if (!empty($f['secret'])) {
                if ($v !== '') {
                    $secrets[$f['name']] = $v;
                } elseif (!empty($f['required']) && empty($secrets[$f['name']])) {
                    $errors[$f['name']] = 'Ce champ est obligatoire.';
                }
                continue;
            }
            $v = $v !== '' ? $v : (string) ($f['default'] ?? '');
            if (!empty($f['required']) && $v === '') {
                $errors[$f['name']] = 'Ce champ est obligatoire.';
                continue;
            }
            if ($f['type'] === 'select' && $v !== '' && !in_array($v, array_column($f['options'], 'value'), true)) {
                $errors[$f['name']] = 'Choix invalide.';
                continue;
            }
            $config[$f['name']] = $f['type'] === 'number' && $v !== '' ? (int) $v : $v;
        }
        if ($errors) {
            return compact('config', 'secrets', 'errors');
        }
        try {
            switch ($id) {
                case 'github':
                    if (!preg_match('#^(?:https?://github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i', $config['repository'], $m)) {
                        $errors['repository'] = "Indiquez l'adresse du dépôt, par exemple https://github.com/mon-compte/mon-site";
                    } else {
                        $config += ['owner' => $m[1], 'repo' => $m[2]];
                    }
                    break;
                case 'gitlab':
                    $url = str_starts_with($config['repository'], 'http') ? $config['repository'] : 'https://gitlab.com/' . $config['repository'];
                    $parts = Net::assertPublicUrl($url);
                    $project = trim(preg_replace('/\.git$/', '', $parts['path'] ?? ''), '/');
                    if (!str_contains($project, '/')) {
                        $errors['repository'] = "Indiquez l'adresse complète du projet.";
                    }
                    $config += ['baseUrl' => 'https://' . $parts['host'], 'project' => $project];
                    break;
                case 'bitbucket':
                    if (!preg_match('#^(?:https?://bitbucket\.org/)?([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i', $config['repository'], $m)) {
                        $errors['repository'] = "Indiquez l'adresse du dépôt, par exemple https://bitbucket.org/espace/mon-site";
                    } else {
                        $config += ['workspace' => $m[1], 'repo' => $m[2]];
                    }
                    break;
                case 'sftp':
                case 'ftp':
                    $config['host'] = preg_replace('#^(s?ftps?|ssh)://#i', '', rtrim($config['host'], '/'));
                    Net::assertPublicHost($config['host']);
                    if ($id === 'sftp' && empty($secrets['password']) && empty($secrets['privateKey'])) {
                        $errors['password'] = 'Indiquez un mot de passe ou une clé privée.';
                    }
                    $config['remoteRoot'] = '/' . trim($config['remoteRoot'], '/');
                    break;
                case 'shopify':
                    $domain = strtolower(preg_replace('#^https?://|/.*$#', '', $config['shopDomain']));
                    if (!preg_match(ShopifyAdapter::DOMAIN_RE, $domain)) {
                        $errors['shopDomain'] = "L'adresse doit se terminer par .myshopify.com (visible dans Shopify → Paramètres → Domaines).";
                    }
                    $config['shopDomain'] = $domain;
                    break;
                case 'wordpress':
                    Net::assertPublicUrl($config['siteUrl']);
                    break;
                case 'custom-api':
                    Net::assertPublicUrl($config['baseUrl']);
                    break;
            }
        } catch (AdapterError $e) {
            $first = array_values(array_filter($def['fields'], fn ($f) => empty($f['secret']) && $f['type'] !== 'select'))[0]['name'] ?? '_';
            $errors[$first] = $e->userMessage;
        }
        return compact('config', 'secrets', 'errors');
    }

    /** @param array{siteId: string, publicUrl: string, schema: ?array} $ctx */
    public static function create(string $id, array $config, array $secrets, array $ctx): Adapter
    {
        $s = fn (string $k) => (string) ($config[$k] ?? '');
        switch ($id) {
            case 'github':
                return new FileSiteAdapter(new GitHubBackend($s('owner'), $s('repo'), $s('branch') ?: 'main', (string) ($secrets['token'] ?? '')), self::fileOptions($config, $ctx, false));
            case 'gitlab':
                return new FileSiteAdapter(new GitLabBackend($s('project'), $s('branch') ?: 'main', (string) ($secrets['token'] ?? ''), $s('baseUrl') ?: 'https://gitlab.com'), self::fileOptions($config, $ctx, false));
            case 'bitbucket':
                return new FileSiteAdapter(new BitbucketBackend($s('workspace'), $s('repo'), $s('branch') ?: 'main', (string) ($secrets['token'] ?? ''), $s('username') ?: null), self::fileOptions($config, $ctx, false));
            case 'sftp':
                Net::assertPublicHost($s('host'));
                return new FileSiteAdapter(new SftpBackend($s('host'), (int) ($config['port'] ?? 22) ?: 22, $s('username'), $secrets['password'] ?? null, $secrets['privateKey'] ?? null,
                    $secrets['passphrase'] ?? null, $s('remoteRoot') ?: '/', $s('hostFingerprint') ?: null), self::fileOptions($config, $ctx, true));
            case 'ftp':
                Net::assertPublicHost($s('host'));
                return new FileSiteAdapter(new FtpBackend($s('host'), (int) ($config['port'] ?? 0), $s('username'), (string) ($secrets['password'] ?? ''), $s('tls') ?: 'explicit', $s('remoteRoot') ?: '/'), self::fileOptions($config, $ctx, true));
            case 'shopify':
                return new ShopifyAdapter($s('shopDomain'), (string) ($secrets['accessToken'] ?? ''), $s('apiVersion') ?: '2025-07');
            case 'wordpress':
                Net::assertPublicUrl($s('siteUrl'));
                return new WordPressAdapter($s('siteUrl'), $s('username'), (string) ($secrets['applicationPassword'] ?? ''), $secrets['wooConsumerKey'] ?? null, $secrets['wooConsumerSecret'] ?? null);
            case 'webflow':
                return new WebflowAdapter((string) ($secrets['token'] ?? ''), $s('siteId') ?: null);
            case 'custom-api':
                Net::assertPublicUrl($s('baseUrl'));
                return new CustomApiAdapter($s('baseUrl'), (string) ($secrets['secret'] ?? ''));
            case 'demo':
                if (!Config::demo()) {
                    throw new AdapterError('invalid', 'Connecteur réservé à la démonstration.');
                }
                $root = self::demoRoot() . '/' . basename($s('folder'));
                return new FileSiteAdapter(new LocalBackend($root), self::fileOptions($config, $ctx, !is_dir("$root/public")));
        }
        throw new AdapterError('invalid', 'Type de site inconnu.');
    }

    /** Copie de travail des sites de démonstration (les modèles d'origine restent dans demo/sites). */
    public static function demoRoot(): string
    {
        return SC_STORAGE . '/demo-sites';
    }

    private static function fileOptions(array $config, array $ctx, bool $webRoot): array
    {
        $dir = (string) ($config['contentDir'] ?? 'auto');
        $schema = $ctx['schema'] ?? null;
        $contentDir = $dir === 'auto' && isset($schema['contentDir']) ? $schema['contentDir'] : ($dir === 'auto' ? 'auto' : Paths::join($dir));
        $media = $schema['media'] ?? null;
        if (!$media) {
            $mDir = (string) ($config['mediaDir'] ?? 'auto');
            $media = $mDir !== '' && $mDir !== 'auto'
                ? ['dir' => Paths::join($mDir), 'publicPrefix' => (string) (($config['mediaPublicPrefix'] ?? '') ?: '/' . preg_replace('#^(public|static)/#', '', Paths::join($mDir)))]
                : ['dir' => 'auto', 'publicPrefix' => '/images/simplecommerce'];
        }
        return ['contentDir' => $contentDir, 'media' => $media, 'publicUrl' => $ctx['publicUrl'], 'webRoot' => $webRoot];
    }
}
