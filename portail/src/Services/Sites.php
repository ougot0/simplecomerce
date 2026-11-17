<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Adapters\Files\Fingerprinted;
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

    /** Nouvelle détection, en gardant les noms, champs cachés et rubriques masquées choisis auparavant. */
    public static function mergeSchema(?array $current, array $found): array
    {
        $old = [];
        foreach ($current['sections'] ?? [] as $s) {
            $old[$s['key']] = $s;
        }
        $fresh = array_column($found['sections'], 'label');
        foreach ($found['sections'] as &$s) {
            if (!isset($old[$s['key']])) {
                continue;
            }
            $o = $old[$s['key']];
            $s['label'] = $o['label'];
            $s['hidden'] = $o['hidden'] ?? false;
            if (isset($o['itemLabel'])) {
                $s['itemLabel'] = $o['itemLabel'];
            }
            $oldFields = array_column($o['fields'], null, 'key');
            foreach ($s['fields'] as &$f) {
                if (isset($oldFields[$f['key']])) {
                    foreach (['label', 'hidden', 'help'] as $k) {
                        if (isset($oldFields[$f['key']][$k])) {
                            $f[$k] = $oldFields[$f['key']][$k];
                        }
                    }
                }
            }
            unset($f);
        }
        unset($s);
        // Deux rubriques au même nom (deux pages au même titre) : on reprend les noms distincts de la nouvelle lecture.
        $count = array_count_values(array_column($found['sections'], 'label'));
        foreach ($found['sections'] as $i => &$s) {
            if (($count[$s['label']] ?? 0) > 1 && isset($fresh[$i])) {
                $s['label'] = $fresh[$i];
            }
        }
        unset($s);
        return ContentSchema::parse($found);
    }

    /** Après l'ajout ou la suppression d'une page (projet…), les rubriques du site sont relues. */
    public static function rediscover(array $site, ?string $by): void
    {
        try {
            $found = self::discover($site);
            Repo::saveSchema($site['id'], self::mergeSchema(Repo::schema($site['id']), $found['schema']), $by);
            if (!empty(self::extras($site)['wrongFolder'])) {
                self::setExtras($site, ['wrongFolder' => null]);
            }
        } catch (AdapterError $e) {
            if ($e->codeName === 'several_sites') {
                // Relié au dossier de tout l'hébergement : les rubriques des autres sites sont retirées tout de suite.
                Repo::saveSchema($site['id'], ['version' => 1, 'sections' => []], $by);
                self::setExtras($site, ['wrongFolder' => $e->userMessage]);
            }
            Log::warn('relecture des rubriques échouée', ['site' => $site['slug'], 'err' => $e]);
        } catch (\Throwable $e) {
            Log::warn('relecture des rubriques échouée', ['site' => $site['slug'], 'err' => $e]);
        }
    }

    /**
     * Après une mise à jour du portail, le contenu du site est relu tout seul une fois (nouvelles rubriques ou
     * nouveaux champs reconnus), sans que personne ait à cliquer sur « Relire le contenu du site ».
     * @return bool vrai si les rubriques ont été relues
     */
    public static function refreshIfNew(array $site): bool
    {
        if (!Repo::schema($site['id'])) {
            return false;
        }
        $x = self::extras($site);
        $now = time();
        $newVersion = ($x['readWith'] ?? '') !== SC_VERSION;
        $files = in_array($site['connector'], Translation::FILE_CONNECTORS, true);
        // Site modifié ailleurs (le développeur sur GitHub, un envoi FTP…) : vérifié au plus toutes les 2 minutes.
        if (!$newVersion && (!$files || $now - (int) ($x['readCheck'] ?? 0) < 120)) {
            return false;
        }
        $sig = null;
        if ($files) {
            try {
                $sig = self::with($site, fn (Adapter $a) => $a instanceof FileSiteAdapter && $a->backend instanceof Fingerprinted ? $a->backend->fingerprint() : null);
            } catch (\Throwable $e) {
                self::setExtras($site, ['readCheck' => $now]); // site injoignable : on réessaiera plus tard
                return false;
            }
        }
        $changed = $sig !== null ? $sig !== ($x['readSig'] ?? null)
            : $files && $now - (int) ($x['readAt'] ?? 0) >= 1800; // sans empreinte (FTP) : relu toutes les 30 minutes
        if (!$newVersion && !$changed) {
            self::setExtras($site, ['readCheck' => $now]);
            return false;
        }
        // Une seule tentative par version et par état du site, même si la lecture échoue.
        self::setExtras($site, ['readWith' => SC_VERSION, 'readSig' => $sig, 'readCheck' => $now, 'readAt' => $now]);
        self::rediscover($site, null);
        return true;
    }

    /**
     * Vérifie (au plus toutes les 6 heures, et après chaque mise à jour du portail) que le dossier relié est bien celui
     * qu'affiche l'adresse du site ; se corrige tout seul si le bon dossier est trouvé, sinon prévient sur chaque page.
     */
    public static function liveCheckIfDue(array $site, bool $force = false): void
    {
        $x = self::extras($site);
        if (!$force && ($x['liveWith'] ?? '') === SC_VERSION && time() - (int) ($x['liveAt'] ?? 0) < 6 * 3600) {
            return;
        }
        self::setExtras($site, ['liveWith' => SC_VERSION, 'liveAt' => time()]);
        // Dossier choisi par le commerçant lui-même dans la liste : on ne le contredit plus.
        if (($x['liveChosen'] ?? null) !== null && ($x['liveChosen'] ?? '') === (string) ($site['config']['remoteRoot'] ?? '')) {
            return;
        }
        $r = LiveCheck::run($site);
        $host = (string) (parse_url((string) $site['public_url'], PHP_URL_HOST) ?: 'votre site');
        if ($r['state'] === 'fixed') {
            $fresh = Repo::site($site['id']) ?? $site;
            self::setExtras($fresh, ['liveMismatch' => null, 'readSig' => null, 'readCheck' => 0,
                'folderNotice' => "Simple Commerce a trouvé le dossier qui est vraiment affiché sur $host ({$r['folder']}) et s'y est relié : vos modifications y seront désormais publiées. Refaites la dernière modification si elle n'apparaît pas."]);
            self::rediscover(Repo::site($site['id']) ?? $fresh, null);
        } elseif ($r['state'] === 'mismatch') {
            $c = $r['candidates'] ?? [];
            self::setExtras($site, ['folderChoices' => $r['choices'] ?? [], 'liveMismatch' => "Attention : les fichiers que Simple Commerce modifie ne sont pas ceux affichés sur $host. Vos modifications n'apparaissent donc pas en ligne. "
                . ($c ? 'Dans Réglages → Connexion, mettez « ' . implode(' » ou « ', $c) . ' » dans « Dossier du site sur le serveur », puis enregistrez.'
                    : (!empty($r['choices']) ? 'Choisissez ci-dessous, dans la liste des sites de votre hébergement, celui qui s\'affiche à cette adresse.'
                    : 'Dans Réglages → Connexion, indiquez le dossier exact du site (chez OVH : onglet Multisite, colonne « Dossier racine »), ou reliez le site par le service qui le met en ligne (GitHub…).'))]);
        } elseif ($r['state'] === 'ok' && !empty($x['liveMismatch'])) {
            self::setExtras($site, ['liveMismatch' => null]);
        }
    }

    /** Clé publique du site (scripts à coller : catalogue, statistiques, avis). Créée à la première demande. */
    public static function publicKey(array $site): string
    {
        $key = (string) ($site['config']['publicKey'] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
            $key = bin2hex(random_bytes(12));
            Repo::updateSite($site['id'], ['config' => ['publicKey' => $key] + $site['config']]);
        }
        return $key;
    }

    public static function byPublicKey(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
            return null;
        }
        foreach (Repo::sites() as $s) {
            if (($s['config']['publicKey'] ?? '') === $key && $s['status'] === 'active') {
                return $s;
            }
        }
        return null;
    }

    /** Le premier propriétaire du site, au nom de qui sont faites les actions automatiques (fermeture prévue, stock d'une commande). */
    public static function ownerViewer(array $site): ?array
    {
        foreach (Repo::members($site['id']) as $m) {
            $u = $m['role'] === 'owner' ? Repo::user($m['user_id']) : null;
            if ($u) {
                return ['user' => $u, 'effective' => $u, 'assist' => null];
            }
        }
        return null;
    }

    /** Réglages du portail pour ce site (seuil de stock, horaires, fermetures prévues…), rangés à part de la connexion. */
    public static function extras(array $site): array
    {
        return (array) ($site['config']['extras'] ?? []);
    }

    public static function setExtras(array $site, array $patch): array
    {
        $fresh = Repo::site($site['id']) ?? $site;
        $config = $fresh['config'];
        $config['extras'] = array_replace(self::extras($fresh), $patch);
        Repo::updateSite($site['id'], ['config' => $config]);
        return $config['extras'];
    }

    /** Délai d'apparition sur le site, selon la façon dont il est relié. */
    public static function delayText(array $site): string
    {
        return in_array($site['connector'], ['github', 'gitlab', 'bitbucket'], true)
            ? "Votre site sera à jour d'ici une à deux minutes, le temps qu'il se reconstruise."
            : "C'est déjà en ligne. Pensez à rafraîchir la page de votre site.";
    }
}
