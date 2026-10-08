<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

/** Fermeture temporaire : format du fichier simplecommerce-statut.json lu par le site. */
final class Status
{
    public const OPEN = ['closed' => false, 'message' => '', 'reopenOn' => null];

    public static function toFile(array $s): array
    {
        return ['ferme' => (bool) $s['closed'], 'message' => (string) $s['message'], 'reouverture' => $s['reopenOn'] ?: null];
    }

    public static function fromFile(mixed $d): array
    {
        $d = is_array($d) ? $d : [];
        $date = $d['reouverture'] ?? null;
        return [
            'closed' => ($d['ferme'] ?? false) === true,
            'message' => is_string($d['message'] ?? null) ? $d['message'] : '',
            'reopenOn' => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
        ];
    }
}
