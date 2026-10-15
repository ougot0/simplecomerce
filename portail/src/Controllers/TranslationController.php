<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Billing;
use SimpleCommerce\Services\Translation;
use SimpleCommerce\Services\Writer;
use SimpleCommerce\Support\Session;
use SimpleCommerce\Support\Text;

/** Page « Version anglaise » d'un site fait de pages HTML. */
final class TranslationController
{
    private static function ctx(array $p): array
    {
        $ctx = Access::site($p['slug'], 'owner');
        if (!Translation::supported($ctx['site'])) {
            throw Halt::notFound();
        }
        return $ctx;
    }

    public static function page(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('anglais');
        $ctx = self::ctx($p);
        return SiteController::page($ctx, 'site/english', ['state' => Translation::state($ctx['site']), 'ready' => Writer::enabled()], 'Version anglaise');
    }

    /**
     * do = preparer | etape | publier (pas à pas, appelés par la page avec une barre de progression),
     * tout (sans JavaScript : tout d'un coup), retirer.
     */
    public static function action(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('anglais');
        $ctx = self::ctx($p);
        $site = $ctx['site'];
        $back = "/s/{$site['slug']}/anglais";
        $json = Req::wantsJson();
        $do = Req::str('do', 12);
        $fail = function (string $msg) use ($json, $back) {
            if ($json) {
                return Response::json(['error' => $msg], 422);
            }
            Session::flash($msg, 'error');
            return Response::redirect($back);
        };
        if ($do !== 'retirer') {
            if (!Writer::enabled()) {
                return $fail('La traduction automatique n\'est pas activée sur ce portail.');
            }
            if (!Billing::siteAllowed($site, $ctx['viewer'])) {
                return $fail(Billing::blockedMessage());
            }
        }
        @set_time_limit(300);
        try {
            switch ($do) {
                case 'preparer':
                    if (Repo::tooManyAttempts('anglais:' . $site['id'], 20, 86400)) {
                        return $fail('Vous avez déjà traduit ce site plusieurs fois aujourd\'hui. Réessayez demain.');
                    }
                    Repo::recordAttempt('anglais:' . $site['id']);
                    return Response::json(Translation::prepare($site));
                case 'etape':
                    return Response::json(Translation::step($site));
                case 'publier':
                    return Response::json(self::published($ctx, Translation::publish($site), $json));
                case 'tout':
                    if (Repo::tooManyAttempts('anglais:' . $site['id'], 20, 86400)) {
                        return $fail('Vous avez déjà traduit ce site plusieurs fois aujourd\'hui. Réessayez demain.');
                    }
                    Repo::recordAttempt('anglais:' . $site['id']);
                    Translation::prepare($site);
                    for ($i = 0; $i < 60 && Translation::step($site)['todo'] > 0; $i++) {
                    }
                    self::published($ctx, Translation::publish($site), false);
                    return Response::redirect($back);
                case 'retirer':
                    $n = Translation::remove($site);
                    Access::audit($ctx['viewer'], 'english_removed', ['pages' => $n], $site['id']);
                    Session::flash($n ? 'Version anglaise retirée (' . Text::plural($n, 'page') . ' supprimée' . ($n > 1 ? 's' : '') . ').' : 'Il n\'y avait pas de version anglaise sur votre site.');
                    return Response::redirect($back);
            }
        } catch (\RuntimeException $e) {
            return $fail($e->getMessage());
        } catch (\Throwable $e) {
            return $fail(AdapterError::userMessageFor($e));
        }
        throw Halt::notFound();
    }

    private static function published(array $ctx, array $r, bool $json): array
    {
        Access::audit($ctx['viewer'], 'english_published', ['pages' => $r['pages']], $ctx['site']['id']);
        $msg = 'Version anglaise publiée : ' . Text::plural($r['pages'], 'page') . ' traduite' . ($r['pages'] > 1 ? 's' : '') . '. Un bouton « English » apparaît en bas de chaque page de votre site.'
            . ($r['skipped'] ? ' Pages laissées de côté (une version anglaise existait déjà) : ' . implode(', ', array_slice($r['skipped'], 0, 5)) . '.' : '');
        Session::flash($msg);
        return ['ok' => true, 'redirect' => "/s/{$ctx['site']['slug']}/anglais"];
    }
}
