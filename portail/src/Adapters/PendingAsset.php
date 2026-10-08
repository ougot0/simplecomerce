<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

/** Photo prête à être envoyée sur le site (déjà vérifiée et réencodée). */
final class PendingAsset
{
    public function __construct(
        public readonly string $token,
        public readonly string $bytes,
        public readonly string $fileName,
        public readonly string $mime,
        public readonly string $alt = '',
    ) {
    }

    /** @param PendingAsset[] $assets */
    public static function find(array $assets, string $token): self
    {
        foreach ($assets as $a) {
            if ($a->token === $token) {
                return $a;
            }
        }
        throw new AdapterError('invalid', "Une photo n'a pas été retrouvée. Envoyez-la à nouveau.", $token);
    }
}
