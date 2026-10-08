<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\ConflictError;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Content\Values;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;

/**
 * Toute modification d'un site passe par ici : elle est inscrite dans l'historique avant d'être envoyée,
 * puis marquée réussie ou échouée. C'est cet historique qui permet d'annuler.
 *
 * Opération : ['type' => create|update|delete|reorder|singleton, 'section' => array, 'id'?, 'data'?, 'expected'?, 'ids'?]
 */
final class Changes
{
    public const CLOSURE_SECTION = '_fermeture';

    private static function describe(array $op, string $label): string
    {
        $s = $op['section']['label'];
        return match ($op['type']) {
            'create' => "« $label » ajouté dans $s",
            'update' => "« $label » modifié dans $s",
            'delete' => "« $label » supprimé de $s",
            'reorder' => "Ordre de $s modifié",
            default => "$s modifié",
        };
    }

    private static function labelFor(array $op): string
    {
        if (in_array($op['type'], ['reorder', 'singleton'], true)) {
            return $op['section']['label'];
        }
        $data = $op['type'] === 'delete' ? ($op['expected'] ?? []) : $op['data'];
        return ContentSchema::title($op['section'], $data);
    }

    private static function authorName(array $viewer): string
    {
        $u = $viewer['user'];
        $name = $u['name'] ?: $u['email'];
        if ($viewer['effective']['id'] !== $u['id']) {
            $name .= ' (assistance pour ' . ($viewer['effective']['name'] ?: $viewer['effective']['email']) . ')';
        }
        return $name;
    }

    /** @return array{ok: true, change: array, result: array}|array{ok: false, error: string, conflict: bool} */
    public static function publish(array $viewer, array $site, array $op, ?string $revertsId = null, bool $notify = true): array
    {
        $label = self::labelFor($op);
        $summary = ($revertsId ? 'Annulation : ' : '') . self::describe($op, $label);
        $change = Repo::insertChange([
            'site_id' => $site['id'],
            'actor_id' => $viewer['user']['id'],
            'on_behalf_of' => $viewer['effective']['id'] !== $viewer['user']['id'] ? $viewer['effective']['id'] : null,
            'action' => $revertsId ? 'revert' : ($op['type'] === 'singleton' ? 'update' : $op['type']),
            'section_key' => $op['section']['key'],
            'entry_id' => $op['id'] ?? null,
            'entry_label' => mb_substr($label, 0, 250),
            'reverts_id' => $revertsId,
        ]);

        try {
            $data = $op['data'] ?? [];
            $tokens = Values::mediaTokens($data);
            $ctx = ['author' => self::authorName($viewer), 'summary' => $summary, 'changeId' => $change['id'], 'assets' => Media::load($site['id'], $tokens)];
            $result = Sites::with($site, function (Adapter $a) use ($op, $ctx) {
                $s = $op['section'];
                return match ($op['type']) {
                    'create' => $a->createEntry($s, $op['data'], $ctx),
                    'update' => $a->updateEntry($s, $op['id'], $op['data'], $op['expected'] ?? null, $ctx),
                    'delete' => $a->deleteEntry($s, $op['id'], $op['expected'] ?? null, $ctx),
                    // On garde la permutation demandée (avec les identifiants d'avant) pour pouvoir l'annuler.
                    'reorder' => ['afterOrder' => $op['ids']] + $a->reorder($s, $op['ids'], $ctx),
                    'singleton' => $a->updateSingleton($s, $op['data'], $op['expected'] ?? null, $ctx),
                };
            });
            $patch = [
                'status' => 'applied',
                'before_json' => $result['before'] ?? null,
                'after_json' => $result['after'] ?? null,
                'before_order' => $result['beforeOrder'] ?? null,
                'after_order' => $result['afterOrder'] ?? null,
                'remote_ref' => isset($result['ref']) ? mb_substr((string) $result['ref'], 0, 250) : null,
                'entry_id' => $result['id'] ?? $change['entry_id'],
                'entry_label' => mb_substr(!empty($result['after']) && ($op['section']['kind'] ?? '') !== 'singleton' ? ContentSchema::title($op['section'], $result['after']) : $label, 0, 250),
            ];
            Repo::updateChange($change['id'], $patch);
            if ($tokens && !empty($result['after'])) {
                self::rememberMedia($data, $result['after']);
            }
            $change = array_merge($change, $patch);
            if ($notify) {
                Notifier::changed($viewer, $site, $change);
            }
            return ['ok' => true, 'change' => $change, 'result' => $result];
        } catch (\Throwable $e) {
            $message = AdapterError::userMessageFor($e);
            Log::error('modification échouée', ['site' => $site['slug'], 'change' => $change['id'], 'err' => $e instanceof AdapterError ? ($e->detail ?? $e->getMessage()) : $e]);
            Repo::updateChange($change['id'], ['status' => 'failed', 'error' => $message]);
            return ['ok' => false, 'error' => $message, 'conflict' => $e instanceof ConflictError];
        }
    }

    /** Note l'adresse définitive des photos envoyées, pour la bibliothèque « Mes photos ». */
    private static function rememberMedia(mixed $sent, mixed $stored): void
    {
        if (is_string($sent) && str_starts_with($sent, Values::MEDIA_PREFIX) && is_string($stored) && $stored !== '' && !str_starts_with($stored, Values::MEDIA_PREFIX)) {
            Repo::setMediaPublicValue(substr($sent, strlen(Values::MEDIA_PREFIX)), mb_substr($stored, 0, 1000));
            return;
        }
        if (is_array($sent) && is_array($stored)) {
            foreach ($sent as $k => $v) {
                if (array_key_exists($k, $stored)) {
                    self::rememberMedia($v, $stored[$k]);
                }
            }
        }
    }

    /** L'opération inverse d'une modification réussie, ou null. */
    public static function inverse(array $change, array $section): ?array
    {
        if ($change['status'] !== 'applied' || $change['reverted_by_id']) {
            return null;
        }
        $before = $change['before_json'];
        $after = $change['after_json'];
        if (($section['kind'] ?? '') === 'singleton') {
            return $before ? ['type' => 'singleton', 'section' => $section, 'data' => $before, 'expected' => $after] : null;
        }
        if ($change['before_order'] && $change['after_order']) {
            $positional = empty($section['idField']) && array_reduce($change['before_order'], fn ($ok, $id) => $ok && preg_match('/^n\d+$/', (string) $id), true);
            $ids = $positional
                ? array_map(fn ($id) => 'n' . array_search($id, $change['after_order'], true), $change['before_order'])
                : $change['before_order'];
            return ['type' => 'reorder', 'section' => $section, 'ids' => $ids];
        }
        $id = $change['entry_id'];
        if ($before && $after && $id !== null) {
            return ['type' => 'update', 'section' => $section, 'id' => $id, 'data' => $before, 'expected' => $after];
        }
        if (!$before && $after && $id !== null) {
            return ['type' => 'delete', 'section' => $section, 'id' => $id, 'expected' => $after];
        }
        if ($before && !$after) {
            return ['type' => 'create', 'section' => $section, 'data' => $before];
        }
        return null;
    }

    public static function revert(array $viewer, array $site, array $change, array $section): array
    {
        $op = self::inverse($change, $section);
        if (!$op) {
            return ['ok' => false, 'error' => 'Cette modification ne peut pas être annulée.', 'conflict' => false];
        }
        $r = self::publish($viewer, $site, $op, $change['id']);
        if ($r['ok']) {
            Repo::updateChange($change['id'], ['reverted_by_id' => $r['change']['id']]);
        }
        return $r;
    }

    /** Ferme ou rouvre le site, avec une ligne dans l'historique. */
    public static function setClosure(array $viewer, array $site, array $status): array
    {
        $label = $status['closed'] ? 'Site fermé temporairement' : 'Site rouvert';
        $change = Repo::insertChange([
            'site_id' => $site['id'],
            'actor_id' => $viewer['user']['id'],
            'on_behalf_of' => $viewer['effective']['id'] !== $viewer['user']['id'] ? $viewer['effective']['id'] : null,
            'action' => 'update',
            'section_key' => self::CLOSURE_SECTION,
            'entry_label' => $label,
        ]);
        try {
            $result = Sites::with($site, fn (Adapter $a) => $a->setStatus($status, ['author' => self::authorName($viewer), 'summary' => $label, 'changeId' => $change['id'], 'assets' => []]));
            Repo::updateChange($change['id'], ['status' => 'applied', 'before_json' => $result['before'] ?? null, 'after_json' => $result['after'] ?? null, 'remote_ref' => $result['ref'] ?? null]);
            Notifier::changed($viewer, $site, ['entry_label' => $label, 'section_key' => self::CLOSURE_SECTION, 'action' => 'update']);
            return ['ok' => true];
        } catch (\Throwable $e) {
            $message = AdapterError::userMessageFor($e);
            Log::error('fermeture du site échouée', ['site' => $site['slug'], 'err' => $e instanceof AdapterError ? ($e->detail ?? $e->getMessage()) : $e]);
            Repo::updateChange($change['id'], ['status' => 'failed', 'error' => $message]);
            return ['ok' => false, 'error' => $message];
        }
    }

    /** Publie un brouillon (à la main ou à l'heure programmée). */
    public static function publishDraft(array $viewer, array $site, array $draft, array $schema): array
    {
        $section = ContentSchema::find($schema, $draft['section_key']);
        if (!$section) {
            return ['ok' => false, 'error' => "Cette rubrique n'existe plus.", 'conflict' => false];
        }
        $op = ($section['kind'] ?? '') === 'singleton'
            ? ['type' => 'singleton', 'section' => $section, 'data' => $draft['data'], 'expected' => $draft['base_data']]
            : ($draft['entry_id'] !== null
                ? ['type' => 'update', 'section' => $section, 'id' => $draft['entry_id'], 'data' => $draft['data'], 'expected' => $draft['base_data']]
                : ['type' => 'create', 'section' => $section, 'data' => $draft['data']]);
        $r = self::publish($viewer, $site, $op);
        if ($r['ok']) {
            Repo::deleteDraft($draft['id']);
        }
        return $r;
    }
}
