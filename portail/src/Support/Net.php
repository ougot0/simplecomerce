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
        if (!filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP)) {
            return true;
        }
        $bin = inet_pton(trim($ip, '[]'));
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 16) {
            // Adresses IPv6 qui cachent une adresse IPv4 (::ffff:a.b.c.d sous toutes ses écritures, ::a.b.c.d,
            // 64:ff9b::a.b.c.d, 2002:aabb:ccdd::) : on vérifie l'adresse IPv4 cachée.
            $hex = bin2hex($bin);
            $v4 = match (true) {
                str_starts_with($hex, '00000000000000000000ffff'), str_starts_with($hex, '000000000000000000000000') => substr($bin, 12, 4),
                str_starts_with($hex, '0064ff9b0000000000000000') => substr($bin, 12, 4),
                str_starts_with($hex, '2002') => substr($bin, 2, 4),
                default => null,
            };
            if ($v4 !== null) {
                return self::isPrivateIp((string) inet_ntop($v4));
            }
            if (str_starts_with($hex, 'fec') || str_starts_with($hex, 'fed') || str_starts_with($hex, 'fee') || str_starts_with($hex, 'fef')) {
                return true;
            }
            $ip = (string) inet_ntop($bin);
        }
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.|169\.254\.|fe80:|fc|fd)/i', $ip) === 1;
    }

    public static function privateAllowed(): bool
    {
        // Réseau interne autorisé : démonstration, ou réglage explicite 'allow_private_hosts' => true dans config.php (tests, intranet).
        return (Config::demo() && getenv('SC_ALLOW_PRIVATE') === '1') || Config::get('allow_private_hosts') === true;
    }

    /**
     * Vérifie que le nom pointe vers Internet et renvoie l'adresse IP vérifiée, à utiliser telle quelle pour se connecter
     * (sinon le nom pourrait pointer ailleurs juste après la vérification : « DNS rebinding »).
     */
    public static function assertPublicHost(string $host): string
    {
        if (self::privateAllowed()) {
            return trim($host, '[]');
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
        return $ips[0];
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
