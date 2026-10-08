<?php
declare(strict_types=1);

namespace SimpleCommerce\Http;

/** Interrompt une page : redirection ou page introuvable. */
final class Halt extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $location = null)
    {
        parent::__construct("HTTP $status");
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    public static function redirect(string $to): self
    {
        return new self(303, $to);
    }
}
