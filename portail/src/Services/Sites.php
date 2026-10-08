<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Adapters\Files\SftpBackend;
use SimpleCommerce\Adapters\Registry;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Vault;

/** Ouverture de la connexion vers un site. Les secrets ne quittent jamais ce service. */
final class Sites
{
    public static function secrets(array $site): array
    {
        $c = Repo::credentials($site['id']);
        return $c ? Vault::unseal($c['sealed'], $site['id']) : [];
    }

    public static function open(array $site, ?array $schema = null, bool $useStored = true): Adapter
    {
        $schema = $useStored ? ($schema ?? Repo::schema($site['id'])) : $schema;
        return Registry::create($site['connector'], $site['config'], self::secrets($site), ['siteId' => $site['id'], 'publicUrl' => $site['public_url'], 'schema' => $schema]);
    }

    /** Ouvre, exécute, referme (même en cas d'erreur). */
    public static function with(array $site, callable $fn, ?array $schema = null, bool $useStored = true): mixed
    {
        $adapter = self::open($site, $schema, $useStored);
        try {
            return $fn($adapter);
        } finally {
            $adapter->close();
        }
    }

    public static function storeSecrets(string $siteId, array $secrets, string $by): void
    {
        $fingerprints = [];
        foreach ($secrets as $k => $v) {
            if (is_string($v) && $v !== '') {
                $fingerprints[$k] = Vault::fingerprint($v);
            }
        }
        Repo::setCredentials($siteId, Vault::seal($secrets, $siteId), $fingerprints, $by);
    }

    /** Teste des accès (enregistrés ou non) sans rien écrire sur le site. @return array{report: array, fingerprint: ?string} */
    public static function test(string $connector, array $config, array $secrets, string $publicUrl): array
    {
        $adapter = null;
        try {
            $adapter = Registry::create($connector, $config, $secrets, ['siteId' => 'test', 'publicUrl' => $publicUrl, 'schema' => null]);
            $report = $adapter->test();
            $fp = $adapter instanceof FileSiteAdapter && $adapter->backend instanceof SftpBackend ? $adapter->backend->seenFingerprint : null;
            return ['report' => $report, 'fingerprint' => $fp];
        } catch (\Throwable $e) {
            Log::warn('test de connexion échoué', ['connector' => $connector, 'err' => $e]);
            return ['report' => ['ok' => false, 'checks' => [['label' => 'Connexion', 'ok' => false, 'hint' => AdapterError::userMessageFor($e)]]], 'fingerprint' => null];
        } finally {
            $adapter?->close();
        }
    }

    /** @return array{schema: array, notes: string[]} */
    public static function discover(array $site): array
    {
        return self::with($site, function (Adapter $a) {
            $r = $a->discover();
            return ['schema' => ContentSchema::parse($r['schema']), 'notes' => $r['notes']];
        }, null, false);
    }

    /** Délai d'apparition sur le site, selon la façon dont il est relié. */
    public static function delayText(array $site): string
    {
        return in_array($site['connector'], ['github', 'gitlab', 'bitbucket'], true)
            ? "Votre site sera à jour d'ici une à deux minutes, le temps qu'il se reconstruise."
            : "C'est déjà en ligne. Pensez à rafraîchir la page de votre site.";
    }
}
