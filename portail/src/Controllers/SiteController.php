<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Http\View;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Changes;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Session;

/** Espace d'un site : accueil, rubriques, brouillons, historique, sauvegarde. */
final class SiteController
{
    public const FLASH = [
        'publie' => null,
        'brouillon' => "Enregistré en brouillon. Ce n'est pas encore visible sur votre site : publiez-le depuis « Brouillons » quand vous êtes prêt.",
        'programme' => 'Publication programmée. Vous pouvez la modifier ou l\'annuler depuis « Brouillons ».',
        'supprime' => 'Supprimé du site.',
        'ordre' => null,
        'annule' => 'Modification annulée.',
        'jete' => 'Brouillon supprimé.',
        'duplique' => 'Copie créée et publiée. Modifiez-la ci-dessous.',
        'visibilite' => null,
    ];

    /** Page dans la mise en page « site » (menu latéral). */
    public static function page(array $ctx, string $template, array $vars = [], string $title = '', int $status = 200): Response
    {
        $ok = Req::query('ok', 20);
        $flash = Session::takeFlash();
        if ($ok !== '' && array_key_exists($ok, self::FLASH)) {
            $flash[] = ['kind' => $ok === 'brouillon' || $ok === 'programme' ? 'warn' : 'ok', 'text' => self::FLASH[$ok] ?? ($ok === 'visibilite' ? 'Enregistré. ' : 'Publié sur votre site. ') . Sites::delayText($ctx['site'])];
        }
        $vars += ['ctx' => $ctx, 'site' => $ctx['site'], 'role' => $ctx['role'], 'base' => '/s/' . $ctx['site']['slug'], 'title' => $title, 'flash' => $flash];
        return Response::html(View::render($template, $vars, 'site'), $status);
    }

    public static function home(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $changes = Repo::changes($site['id'], 8);
        $last = [];
        foreach (array_reverse($changes) as $c) {
            if ($c['status'] === 'applied') {
                $last[$c['section_key']] = $c['created_at'];
            }
        }
        $closure = null;
        try {
            $closure = Sites::with($site, fn (Adapter $a) => $a->getStatus());
        } catch (\Throwable) {
        }
        return self::page($ctx, 'site/home', ['changes' => $changes, 'last' => $last, 'closure' => $closure, 'drafts' => Repo::drafts($site['id']),
            'welcome' => Req::query('bienvenue') === '1', 'people' => self::people($changes)], 'Accueil');
    }

    /** Noms des personnes citées dans une liste (historique, brouillons). */
    public static function people(array $rows): array
    {
        $names = [];
        foreach ($rows as $r) {
            foreach (['actor_id', 'on_behalf_of', 'updated_by'] as $k) {
                if (!empty($r[$k]) && !isset($names[$r[$k]])) {
                    $u = Repo::user($r[$k]);
                    $names[$r[$k]] = $u ? ($u['name'] ?: $u['email']) : 'Compte supprimé';
                }
            }
        }
        return $names;
    }

    public static function section(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $section = Access::section($ctx, $p['section']);
        if (($section['kind'] ?? '') === 'singleton') {
            return EditorController::singleton($ctx, $section);
        }
        $site = $ctx['site'];
        $drafts = array_values(array_filter(Repo::drafts($site['id']), fn ($d) => $d['section_key'] === $section['key']));
        $error = null;
        $entries = [];
        $caps = ['reorder' => false, 'create' => false, 'delete' => false];
        try {
            [$entries, $caps] = Sites::with($site, fn (Adapter $a) => [$a->listEntries($section), $a->capabilities($section)]);
        } catch (\Throwable $e) {
            $error = AdapterError::userMessageFor($e);
        }
        $vars = ['section' => $section, 'entries' => $entries, 'caps' => $caps, 'error' => $error, 'drafts' => $drafts, 'visKey' => ContentSchema::visibilityField($section)];
        if (Req::query('ordre') === '1' && $caps['reorder'] && !$error) {
            return self::page($ctx, 'site/reorder', $vars, 'Changer l\'ordre');
        }
        return self::page($ctx, 'site/section', $vars, $section['label']);
    }

    /** Bibliothèque « Mes photos » (pour l'éditeur). */
    public static function photos(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $list = array_map(fn ($m) => ['value' => $m['public_value'], 'src' => image_src($m['public_value'], $ctx['site']['public_url']), 'alt' => $m['alt'], 'name' => $m['file_name']],
            Repo::siteMedia($ctx['site']['id']));
        return Response::json(['photos' => $list]);
    }

    public static function drafts(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $drafts = Repo::drafts($ctx['site']['id']);
        return self::page($ctx, 'site/drafts', ['drafts' => $drafts, 'people' => self::people($drafts)], 'Brouillons');
    }

    private static function ownDraft(array $ctx): array
    {
        $d = Repo::draft(Req::str('draftId', 64));
        if (!$d || $d['site_id'] !== $ctx['site']['id']) {
            throw \SimpleCommerce\Http\Halt::notFound();
        }
        return $d;
    }

    public static function publishDraft(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $draft = self::ownDraft($ctx);
        $r = Changes::publishDraft($ctx['viewer'], $ctx['site'], $draft, $ctx['schema']);
        if (!$r['ok']) {
            Session::flash($r['conflict'] ? 'Le site a été modifié depuis ce brouillon. Ouvrez-le pour vérifier, puis publiez-le depuis le formulaire.' : $r['error'], 'error');
            return Response::redirect("/s/{$p['slug']}/brouillons");
        }
        return Response::redirect("/s/{$p['slug']}/brouillons?ok=publie");
    }

    public static function discardDraft(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $draft = self::ownDraft($ctx);
        Repo::deleteDraft($draft['id']);
        Access::audit($ctx['viewer'], 'draft_discarded', ['label' => $draft['label']], $ctx['site']['id']);
        return Response::redirect("/s/{$p['slug']}/brouillons?ok=jete");
    }

    public static function history(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $filter = Req::query('rubrique', 120);
        $changes = Repo::changes($ctx['site']['id'], 150, $filter !== '' ? $filter : null);
        return self::page($ctx, 'site/history', ['changes' => $changes, 'people' => self::people($changes), 'filter' => $filter], 'Historique');
    }

    public static function revert(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $change = Repo::change(Req::str('changeId', 64));
        if (!$change || $change['site_id'] !== $ctx['site']['id']) {
            throw \SimpleCommerce\Http\Halt::notFound();
        }
        $section = ContentSchema::find($ctx['schema'], $change['section_key']);
        if (!$section) {
            Session::flash("Cette rubrique n'existe plus.", 'error');
            return Response::redirect("/s/{$p['slug']}/historique");
        }
        $r = Changes::revert($ctx['viewer'], $ctx['site'], $change, $section);
        if (!$r['ok']) {
            Session::flash($r['conflict'] ? "Impossible d'annuler : ce contenu a été modifié depuis. Modifiez-le directement." : $r['error'], 'error');
            return Response::redirect("/s/{$p['slug']}/historique");
        }
        return Response::redirect("/s/{$p['slug']}/historique?ok=annule");
    }

    /** Sauvegarde : tout le contenu modifiable du site, dans un fichier à garder chez soi. */
    public static function backup(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        try {
            $data = Sites::with($site, function (Adapter $a) use ($ctx) {
                $out = [];
                foreach ($ctx['schema']['sections'] as $s) {
                    $out[$s['label']] = ($s['kind'] ?? '') === 'singleton'
                        ? $a->getSingleton($s)
                        : array_map(fn ($e) => $e['data'], $a->listEntries($s));
                }
                return $out;
            });
        } catch (\Throwable $e) {
            Session::flash('La sauvegarde a échoué : ' . AdapterError::userMessageFor($e), 'error');
            return Response::redirect("/s/{$p['slug']}");
        }
        Access::audit($ctx['viewer'], 'backup_downloaded', [], $site['id']);
        $json = json_encode(['site' => $site['name'], 'adresse' => $site['public_url'], 'date' => date('c'), 'contenu' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return Response::file((string) $json, 'application/json; charset=utf-8', 'sauvegarde-' . $site['slug'] . '-' . date('Y-m-d') . '.json', 'no-store');
    }
}
