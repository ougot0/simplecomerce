<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

use SimpleCommerce\Config;

/**
 * Chiffrement des accès aux sites (XChaCha20-Poly1305, extension sodium intégrée à PHP).
 * La clé est dans config.php, hors du dossier public ; la base ne contient que du chiffré.
 * L'identifiant du site est lié au chiffré : un secret recopié sur un autre site ne se déchiffre pas.
 */
final class Vault
{
    private static function key(): string
    {
        $key = base64_decode((string) Config::get('key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('Clé de chiffrement absente ou invalide dans config.php.');
        }
        return $key;
    }

    public static function seal(array $secrets, string $siteId): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(json_encode($secrets, JSON_THROW_ON_ERROR), $siteId, $nonce, self::key());
        return 'v1:' . base64_encode($nonce . $cipher);
    }

    public static function unseal(string $sealed, string $siteId): array
    {
        if (!str_starts_with($sealed, 'v1:')) {
            throw new \RuntimeException('Format de secret inconnu.');
        }
        $raw = base64_decode(substr($sealed, 3), true);
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = $raw === false ? false : sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), $siteId, substr($raw, 0, $n), self::key());
        if ($plain === false) {
            throw new \RuntimeException('Secret illisible (clé différente ou donnée altérée).');
        }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    /** « …a3F9 » : de quoi reconnaître un secret sans jamais l'afficher. */
    public static function fingerprint(string $value): string
    {
        return mb_strlen($value) >= 8 ? '…' . mb_substr($value, -4) : 'enregistré';
    }
}
