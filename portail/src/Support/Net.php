<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Config;

/**
 * Protection contre les requêtes vers le réseau interne : les adresses saisies par les clients
 * (site WordPress, serveur SFTP, API…) doivent pointer vers Internet.
 */
final class Net
{
    public static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.|169\.254\.|fe80:|fc|fd)/i', $ip) === 1;
    }

    public static function assertPublicHost(string $host): void
    {
        if (Config::demo() && getenv('SC_ALLOW_PRIVATE') === '1') {
            return;
        }
        $host = trim($host, '[]');
        if ($host === '' || preg_match('/^(localhost|.*\.local|.*\.internal)$/i', $host)) {
            throw new AdapterError('invalid', "Cette adresse n'est pas autorisée.");
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(gethostbynamel($host) ?: [], self::aaaa($host));
        if (!$ips) {
            throw new AdapterError('network', "L'adresse « $host » est introuvable. Vérifiez l'orthographe.");
        }
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new AdapterError('invalid', 'Cette adresse pointe vers un réseau privé : elle n\'est pas autorisée.');
            }
        }
    }

    private static function aaaa(string $host): array
    {
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        return array_values(array_filter(array_map(fn ($r) => $r['ipv6'] ?? null, $records)));
    }

    public static function assertPublicUrl(string $url, bool $httpsOnly = true): array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            throw new AdapterError('invalid', 'Adresse invalide. Elle doit ressembler à https://www.mon-site.fr');
        }
        if ($parts['scheme'] !== 'https' && ($httpsOnly || $parts['scheme'] !== 'http')) {
            throw new AdapterError('invalid', "L'adresse doit commencer par https://");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new AdapterError('invalid', "L'adresse ne doit pas contenir d'identifiants.");
        }
        self::assertPublicHost($parts['host']);
        return $parts;
    }
}
