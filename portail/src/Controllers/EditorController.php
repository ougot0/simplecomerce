<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Content\FormInput;
use SimpleCommerce\Content\Values;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Changes;
use SimpleCommerce\Services\Media;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Session;

/** Formulaires de modification et actions sur le contenu. */
final class EditorController
{
    private const CONFLICT = "Ce contenu a été modifié entre-temps (par quelqu'un d'autre ou directement sur le site). Rechargez la page pour partir de la version actuelle.";

    /** @param array{entryId: ?string, draft: ?array, values: array, fingerprint: string, error?: ?string, conflict?: bool, errors?: array, canDelete?: bool, title?: string} $e */
    private static function editor(array $ctx, array $section, array $e, int $status = 200): Response
    {
        $base = "/s/{$ctx['site']['slug']}/r/{$section['key']}";
        $isSingleton = ($section['kind'] ?? '') === 'singleton';
        return SiteController::page($ctx, 'site/editor', $e + [
            'section' => $section,
            'error' => null,
            'conflict' => false,
            'errors' => [],
            'canDelete' => false,
            'cancel' => $isSingleton ? "/s/{$ctx['site']['slug']}" : $base,
            'sectionBase' => $base,
            'otherDraft' => null,
        ], $e['title'] ?? $section['label'], $status);
    }

    private static function draftFromQuery(array $ctx, string $sectionKey, ?string $entryId): ?array
    {
        $id = Req::query('brouillon', 64);
        if ($id === '') {
            return null;
        }
        $d = Repo::draft($id);
        if (!$d || $d['site_id'] !== $ctx['site']['id'] || $d['section_key'] !== $sectionKey || $d['entry_id'] !== $entryId) {
            throw Halt::notFound();
        }
        return $d;
    }

    public static function singleton(array $ctx, array $section): Response
    {
        try {
            $data = Sites::with($ctx['site'], fn (Adapter $a) => $a->getSingleton($section));
        } catch (\Throwable $e) {
            return SiteController::page($ctx, 'site/error', ['title' => $section['label'], 'message' => AdapterError::userMessageFor($e)], $section['label']);
        }
        $draft = self::draftFromQuery($ctx, $section['key'], '_');
        return self::editor($ctx, $section, [
            'entryId' => null,
            'draft' => $draft,
            'otherDraft' => $draft ? null : Repo::findDraft($ctx['site']['id'], $section['key'], '_'),
            'values' => $draft ? $draft['data'] + $data : $data,
            'fingerprint' => FormInput::fingerprint($section, $draft['base_data'] ?? $data),
        ]);
    }

    public static function newEntry(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, $p['section']);
        if (($section['kind'] ?? '') !== 'collection') {
            throw Halt::notFound();
        }
        $draft = self::draftFromQuery($ctx, $section['key'], null);
        try {
            $template = Sites::with($ctx['site'], fn (Adapter $a) => $a->capabilities($section)['create'] ? $a->template($section) : null);
        } catch (\Throwable $e) {
            return SiteController::page($ctx, 'site/error', ['title' => $section['label'], 'message' => AdapterError::userMessageFor($e)], $section['label']);
        }
        if ($template === null) {
            throw Halt::notFound();
        }
        return self::editor($ctx, $section, ['entryId' => null, 'draft' => $draft, 'values' => $draft['data'] ?? $template, 'fingerprint' => '', 'title' => \SimpleCommerce\Support\Text::addLabel($section['itemLabel'] ?? null)]);
    }

    public static function editEntry(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, $p['section']);
        if (($section['kind'] ?? '') !== 'collection') {
            throw Halt::notFound();
        }
        $id = $p['id'];
        try {
            [$entry, $caps] = Sites::with($ctx['site'], fn (Adapter $a) => [$a->getEntry($section, $id), $a->capabilities($section)]);
        } catch (\Throwable $e) {
            return SiteController::page($ctx, 'site/error', ['title' => $section['label'], 'message' => AdapterError::userMessageFor($e)], $section['label']);
        }
        if (!$entry) {
            return SiteController::page($ctx, 'site/error', ['title' => 'Introuvable', 'message' => "Cet élément n'existe plus sur votre site. Il a peut-être été supprimé.", 'back' => ["/s/{$p['slug']}/r/{$section['key']}", 'Retour à ' . $section['label']]], 'Introuvable', 404);
        }
        $draft = self::draftFromQuery($ctx, $section['key'], $id);
        return self::editor($ctx, $section, [
            'entryId' => $id,
            'draft' => $draft,
            'otherDraft' => $draft ? null : Repo::findDraft($ctx['site']['id'], $section['key'], $id),
            'values' => $draft ? $draft['data'] + $entry['data'] : $entry['data'],
            'fingerprint' => FormInput::fingerprint($section, $draft['base_data'] ?? $entry['data']),
            'canDelete' => $caps['delete'],
            'title' => ContentSchema::title($section, $entry['data']),
        ]);
    }

    private static function cleanId(string $raw): ?string
    {
        return $raw !== '' && strlen($raw) <= 200 && !preg_match('#[\0/\\\\]#', $raw) ? $raw : null;
    }

    /** Contenu actuel sur le site. @return array{0: array, 1: bool} [données, existe] */
    private static function current(array $site, array $section, ?string $entryId): array
    {
        return Sites::with($site, function (Adapter $a) use ($section, $entryId) {
            if (($section['kind'] ?? '') === 'singleton') {
                return [$a->getSingleton($section), true];
            }
            if ($entryId !== null) {
                $e = $a->getEntry($section, $entryId);
                if (!$e) {
                    throw new AdapterError('conflict', "Cet élément n'existe plus sur le site.");
                }
                return [$e['data'], true];
            }
            return [$a->template($section), false];
        });
    }

    /** Date « 2026-10-12T08:30 » (heure de Paris) → ISO UTC, ou null. */
    private static function parseSchedule(string $raw): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw)) {
            return null;
        }
        $t = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, new \DateTimeZone('Europe/Paris'));
        return $t ? $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null;
    }

    public static function save(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, Req::str('section', 120));
        $site = $ctx['site'];
        $isSingleton = ($section['kind'] ?? '') === 'singleton';
        $entryId = $isSingleton ? null : self::cleanId(Req::str('entryId', 200));
        $draftId = Req::str('draftId', 64);
        $draft = $draftId !== '' ? Repo::draft($draftId) : null;
        if ($draft && $draft['site_id'] !== $site['id']) {
            throw Halt::notFound();
        }
        $mode = in_array(Req::str('mode'), ['draft', 'schedule'], true) ? Req::str('mode') : 'publish';
        $raw = $_POST['data'] ?? [];
        $input = FormInput::coerce($section['fields'], is_array($raw) ? $raw : []);
        $fingerprint = Req::str('fingerprint', 64);
        $back = fn (array $extra, int $status = 422) => self::editor($ctx, $section, $extra + ['entryId' => $entryId, 'draft' => $draft, 'fingerprint' => $fingerprint, 'values' => $input + ($extra['values'] ?? [])], $status);

        try {
            [$current, $exists] = self::current($site, $section, $entryId);
        } catch (\Throwable $e) {
            return $back(['error' => AdapterError::userMessageFor($e), 'conflict' => $e instanceof AdapterError && $e->codeName === 'conflict']);
        }
        if ($exists && $fingerprint !== '' && !hash_equals(FormInput::fingerprint($section, $current), $fingerprint)) {
            return $back(['error' => self::CONFLICT, 'conflict' => true, 'values' => $current]);
        }
        [$data, $errors] = Values::normalize($section['fields'], $input, $current);
        if ($errors) {
            return $back(['errors' => $errors, 'error' => 'Certains champs sont à corriger.', 'values' => $current]);
        }

        $sectionUrl = "/s/{$site['slug']}/r/{$section['key']}";
        if ($mode !== 'publish') {
            $at = null;
            if ($mode === 'schedule') {
                $at = self::parseSchedule(Req::str('publishAt', 20));
                if (!$at || $at <= gmdate('Y-m-d\TH:i:s\Z')) {
                    return $back(['errors' => ['_publishAt' => 'Choisissez une date et une heure à venir.'], 'error' => 'La date de publication est à corriger.', 'values' => $data]);
                }
            }
            $draft ??= Repo::findDraft($site['id'], $section['key'], $isSingleton ? '_' : $entryId);
            Repo::saveDraft([
                'id' => $draft['id'] ?? null,
                'site_id' => $site['id'],
                'section_key' => $section['key'],
                'entry_id' => $isSingleton ? '_' : $entryId,
                'label' => ContentSchema::title($section, $data),
                'data' => Values::pick($section, $data),
                'base_data' => $exists ? Values::pick($section, $current) : null,
                'publish_at' => $at,
                'updated_by' => $ctx['viewer']['user']['id'],
            ]);
            return Response::redirect($sectionUrl . '?ok=' . ($mode === 'schedule' ? 'programme' : 'brouillon'));
        }

        $op = $isSingleton
            ? ['type' => 'singleton', 'section' => $section, 'data' => $data, 'expected' => Values::pick($section, $current)]
            : ($entryId !== null
                ? ['type' => 'update', 'section' => $section, 'id' => $entryId, 'data' => $data, 'expected' => Values::pick($section, $current)]
                : ['type' => 'create', 'section' => $section, 'data' => $data]);
        $r = Changes::publish($ctx['viewer'], $site, $op);
        if (!$r['ok']) {
            return $back(['error' => $r['conflict'] ? self::CONFLICT : $r['error'], 'conflict' => $r['conflict'], 'values' => $data]);
        }
        if ($draft) {
            Repo::deleteDraft($draft['id']);
        }
        return Response::redirect($sectionUrl . '?ok=publie');
    }

    public static function delete(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, Req::str('section', 120));
        $entryId = self::cleanId(Req::str('entryId', 200));
        $url = "/s/{$p['slug']}/r/{$section['key']}";
        if ($entryId === null || ($section['kind'] ?? '') !== 'collection' || ($section['allowDelete'] ?? true) === false) {
            Session::flash('Suppression impossible.', 'error');
            return Response::redirect($url);
        }
        try {
            [$current] = self::current($ctx['site'], $section, $entryId);
        } catch (\Throwable $e) {
            Session::flash(AdapterError::userMessageFor($e), 'error');
            return Response::redirect($url);
        }
        $fp = Req::str('fingerprint', 64);
        if ($fp !== '' && !hash_equals(FormInput::fingerprint($section, $current), $fp)) {
            Session::flash(self::CONFLICT, 'error');
            return Response::redirect($url . '/e/' . rawurlencode($entryId));
        }
        $r = Changes::publish($ctx['viewer'], $ctx['site'], ['type' => 'delete', 'section' => $section, 'id' => $entryId, 'expected' => Values::pick($section, $current)]);
        if (!$r['ok']) {
            Session::flash($r['error'], 'error');
            return Response::redirect($url);
        }
        if ($d = Repo::findDraft($ctx['site']['id'], $section['key'], $entryId)) {
            Repo::deleteDraft($d['id']);
        }
        return Response::redirect("$url?ok=supprime");
    }

    public static function reorder(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, Req::str('section', 120));
        $ids = Req::arr('ids');
        $url = "/s/{$p['slug']}/r/{$section['key']}";
        if (!$ids || count($ids) > 2000 || array_filter($ids, fn ($i) => !is_string($i) || strlen($i) > 200)) {
            Session::flash('Ordre invalide.', 'error');
            return Response::redirect($url);
        }
        $r = Changes::publish($ctx['viewer'], $ctx['site'], ['type' => 'reorder', 'section' => $section, 'ids' => array_values($ids)]);
        if (!$r['ok']) {
            Session::flash($r['error'], 'error');
            return Response::redirect("$url?ordre=1");
        }
        return Response::redirect("$url?ok=ordre");
    }

    /** Interrupteur « visible / masqué » directement depuis la liste. */
    public static function toggleVisibility(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, Req::str('section', 120));
        $key = ContentSchema::visibilityField($section);
        $entryId = self::cleanId(Req::str('entryId', 200));
        $url = "/s/{$p['slug']}/r/{$section['key']}";
        if (!$key || $entryId === null) {
            throw Halt::notFound();
        }
        try {
            [$current] = self::current($ctx['site'], $section, $entryId);
        } catch (\Throwable $e) {
            Session::flash(AdapterError::userMessageFor($e), 'error');
            return Response::redirect($url);
        }
        $data = [$key => Req::str('visible') === '1'] + $current;
        $r = Changes::publish($ctx['viewer'], $ctx['site'], ['type' => 'update', 'section' => $section, 'id' => $entryId, 'data' => $data, 'expected' => Values::pick($section, $current)]);
        if (!$r['ok']) {
            Session::flash($r['error'], 'error');
            return Response::redirect($url);
        }
        $label = ContentSchema::title($section, $current);
        Session::flash(($data[$key] ? "« $label » est de nouveau visible sur votre site. " : "« $label » est masqué : vos visiteurs ne le voient plus. ") . Sites::delayText($ctx['site']));
        return Response::redirect($url);
    }

    /** Dupliquer un élément (la copie est masquée si la rubrique le permet). */
    public static function duplicate(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, Req::str('section', 120));
        $entryId = self::cleanId(Req::str('entryId', 200));
        $url = "/s/{$p['slug']}/r/{$section['key']}";
        if ($entryId === null || ($section['kind'] ?? '') !== 'collection') {
            throw Halt::notFound();
        }
        try {
            [$current] = self::current($ctx['site'], $section, $entryId);
        } catch (\Throwable $e) {
            Session::flash(AdapterError::userMessageFor($e), 'error');
            return Response::redirect($url);
        }
        $data = $current;
        $titleKey = $section['titleField'] ?? null;
        if ($titleKey && is_string($data[$titleKey] ?? null)) {
            $data[$titleKey] = $data[$titleKey] . ' (copie)';
        }
        if ($vis = ContentSchema::visibilityField($section)) {
            $data[$vis] = false;
        }
        $r = Changes::publish($ctx['viewer'], $ctx['site'], ['type' => 'create', 'section' => $section, 'data' => $data]);
        if (!$r['ok'] || ($r['change']['entry_id'] ?? null) === null) {
            Session::flash($r['error'] ?? 'Copie impossible.', 'error');
            return Response::redirect($url);
        }
        Session::flash($vis ? 'Copie créée. Elle est masquée sur votre site tant que vous ne la rendez pas visible.' : 'Copie créée et publiée sur votre site.');
        return Response::redirect("$url/e/" . rawurlencode((string) $r['change']['entry_id']));
    }

    /** Envoi d'une photo (depuis l'éditeur) : gardée en attente jusqu'à l'enregistrement. */
    public static function upload(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $uid = $ctx['viewer']['user']['id'];
        if (Repo::tooManyAttempts("upload:$uid", 120, 3600)) {
            return Response::json(['error' => 'Trop de photos envoyées en une heure. Patientez un peu.'], 429);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $tooBig = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return Response::json(['error' => $tooBig ? 'La photo est trop lourde (8 Mo maximum).' : 'Aucune photo reçue.'], 422);
        }
        if ((int) $file['size'] > Media::MAX_BYTES) {
            return Response::json(['error' => 'La photo est trop lourde (8 Mo maximum).'], 422);
        }
        Repo::recordAttempt("upload:$uid");
        try {
            $m = Media::stage($ctx['site']['id'], $uid, (string) file_get_contents($file['tmp_name']), (string) ($file['name'] ?? 'photo'), Req::str('alt', 300), (int) Req::str('maxWidth', 6) ?: 2000);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('envoi de photo échoué', ['site' => $ctx['site']['slug'], 'err' => $e]);
            return Response::json(['error' => "La photo n'a pas pu être enregistrée. Réessayez."], 500);
        }
        return Response::json(['token' => Values::MEDIA_PREFIX . $m['id'], 'url' => '/media/' . $m['id']]);
    }
}
