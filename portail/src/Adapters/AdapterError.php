<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

/**
 * Erreur d'un connecteur : un message pour le client (français simple), un détail technique pour le journal.
 * Codes : auth, not_found, conflict, network, invalid, rate_limited, unsupported, remote.
 */
class AdapterError extends \RuntimeException
{
    public function __construct(public readonly string $codeName, public readonly string $userMessage, public readonly ?string $detail = null)
    {
        parent::__construct($detail ? "$userMessage — $detail" : $userMessage);
    }

    public static function userMessageFor(\Throwable $e): string
    {
        return $e instanceof self ? $e->userMessage : "Une erreur inattendue s'est produite. Réessayez dans un instant ; si cela continue, contactez votre administrateur.";
    }
}
