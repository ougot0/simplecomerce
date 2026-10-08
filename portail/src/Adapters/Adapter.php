<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

/**
 * Interface commune à tous les types de sites.
 *
 * Entrée : ['id' => string, 'data' => array]
 * Contexte d'écriture : ['author' => string, 'summary' => string, 'changeId' => string, 'assets' => PendingAsset[]]
 * Résultat d'écriture : ['id' => ?string, 'before' => ?array, 'after' => ?array, 'ref' => ?string, 'beforeOrder' => ?array, 'afterOrder' => ?array]
 * Statut de fermeture : ['closed' => bool, 'message' => string, 'reopenOn' => ?string]
 */
interface Adapter
{
    /** @return array{reorder: bool, create: bool, delete: bool} */
    public function capabilities(array $section): array;

    /** @return array{ok: bool, checks: array<int, array{label: string, ok: bool, hint?: string}>} */
    public function test(): array;

    /** @return array{schema: array, notes: string[]} */
    public function discover(): array;

    public function listEntries(array $section): array;

    public function getEntry(array $section, string $id): ?array;

    public function createEntry(array $section, array $data, array $ctx): array;

    public function updateEntry(array $section, string $id, array $data, ?array $expected, array $ctx): array;

    public function deleteEntry(array $section, string $id, ?array $expected, array $ctx): array;

    public function reorder(array $section, array $orderedIds, array $ctx): array;

    public function getSingleton(array $section): array;

    public function updateSingleton(array $section, array $data, ?array $expected, array $ctx): array;

    /** Valeurs de départ d'un nouvel élément. */
    public function template(array $section): array;

    /** Fermeture temporaire : null si ce type de site ne le permet pas. */
    public function getStatus(): ?array;

    public function setStatus(array $status, array $ctx): array;

    public function close(): void;
}
