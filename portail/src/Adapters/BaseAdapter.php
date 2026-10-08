<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Content\Values;

/** Comportements par défaut des connecteurs par API. */
abstract class BaseAdapter implements Adapter
{
    public function capabilities(array $section): array
    {
        return ['reorder' => false, 'create' => ($section['allowCreate'] ?? true) !== false, 'delete' => ($section['allowDelete'] ?? true) !== false];
    }

    public function template(array $section): array
    {
        return [];
    }

    public function getStatus(): ?array
    {
        return null;
    }

    public function setStatus(array $status, array $ctx): array
    {
        throw new AdapterError('unsupported', 'Ce type de site se ferme depuis sa propre administration.');
    }

    public function getSingleton(array $section): array
    {
        throw new AdapterError('unsupported', 'Rubrique non disponible pour ce type de site.');
    }

    public function updateSingleton(array $section, array $data, ?array $expected, array $ctx): array
    {
        throw new AdapterError('unsupported', 'Rubrique non disponible pour ce type de site.');
    }

    public function reorder(array $section, array $orderedIds, array $ctx): array
    {
        throw new AdapterError('unsupported', "L'ordre se règle dans l'administration de la plateforme.");
    }

    public function close(): void
    {
    }

    protected function assertSame(array $section, array $current, ?array $expected): void
    {
        if ($expected !== null && !Values::equal(Values::pick($section, $current), Values::pick($section, $expected))) {
            throw new ConflictError();
        }
    }
}
