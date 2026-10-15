<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Features;
use SimpleCommerce\Services\Studio;
use SimpleCommerce\Support\Session;

/** /studio : le propriétaire du portail modifie Simple Commerce (brouillon, aperçu, publication). */
final class StudioController
{
    public static function show(): Response
    {
        $v = Access::requireAdmin();
        return Response::page('studio', ['viewer' => $v, 's' => Studio::draft(), 'pending' => Studio::hasDraft(),
            'preview' => (bool) Session::get('studio_apercu'), 'title' => 'Studio', 'wide' => true], 'plain');
    }

    public static function action(): Response
    {
        $v = Access::requireAdmin();
        switch (Req::str('do', 20)) {
            case 'publier':
                Studio::publish();
                Session::forget('studio_apercu');
                Repo::log($v['user']['id'], null, null, 'studio_published');
                Session::flash('Publié : tous vos clients voient maintenant ces changements.');
                return Response::redirect('/studio');
            case 'annuler':
                Studio::discard();
                Session::forget('studio_apercu');
                Session::flash('Brouillon effacé : rien n\'a changé pour vos clients.');
                return Response::redirect('/studio');
            case 'quitter':
                Session::forget('studio_apercu');
                return Response::redirect('/studio');
        }
        $colors = [];
        foreach (Studio::COLORS as $k => [, $def]) {
            $c = strtolower(Req::str("color_$k", 7));
            $colors[$k] = preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : $def;
        }
        $keep = array_keys((array) Req::arr('on'));
        Studio::saveDraft([
            'name' => trim(Req::str('name', 40)) ?: 'Simple Commerce',
            'colors' => $colors,
            'off' => array_values(array_diff(array_keys(Features::LIST), $keep)),
            'notice' => trim(Req::str('notice', 300)),
        ]);
        if (Req::str('do', 20) === 'defaut') {
            Studio::saveDraft(['off' => Studio::draft()['off']] + Studio::defaults());
        }
        Session::set('studio_apercu', true);
        Session::flash('Brouillon enregistré. Regardez l\'aperçu à droite : vos clients ne voient encore rien.');
        return Response::redirect('/studio');
    }

    /** Couleurs choisies, chargées après app.css sur toutes les pages. */
    public static function css(): Response
    {
        return new Response(Studio::css(), 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'private, max-age=31536000']);
    }
}
