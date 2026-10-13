<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Legal;
use SimpleCommerce\Support\Session;

/** Pages légales publiques (mentions, CGU, CGV, confidentialité) et leur réglage par l'administrateur. */
final class LegalController
{
    public const PAGES = [
        'mentions-legales' => ['legal/mentions', 'Mentions légales'],
        'conditions-generales-utilisation' => ['legal/cgu', "Conditions générales d'utilisation"],
        'conditions-generales-vente' => ['legal/cgv', 'Conditions générales de vente'],
        'confidentialite' => ['legal/confidentialite', 'Politique de confidentialité'],
    ];

    public static function page(array $p): Response
    {
        $page = self::PAGES[$p['page']] ?? null;
        if (!$page) {
            throw Halt::notFound();
        }
        return Response::page($page[0], ['title' => $page[1], 'info' => Legal::info(), 'viewer' => Access::viewer()], 'plain');
    }

    public static function admin(array $p = [], array $state = []): Response
    {
        $v = Access::requireAdmin();
        return Response::page('admin/legal', $state + ['viewer' => $v, 'info' => Legal::info(), 'title' => 'Informations légales'], 'plain');
    }

    public static function save(): Response
    {
        $v = Access::requireAdmin();
        $values = [];
        foreach (array_keys(Legal::FIELDS) as $k) {
            $values[$k] = Req::str($k, 300);
        }
        Legal::save($values);
        Repo::log($v['user']['id'], null, null, 'legal_updated');
        Session::flash('Informations légales enregistrées. Elles apparaissent dans les mentions légales et les conditions générales.');
        return Response::redirect('/admin/informations-legales');
    }
}
