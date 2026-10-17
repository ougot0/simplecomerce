<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Config;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Orders;
use SimpleCommerce\Services\Reviews;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Services\Stats;
use SimpleCommerce\Services\Waitlist;
use SimpleCommerce\Support\Session;

/** Pages « boutique » d'un site : commandes, liste d'attente, avis, statistiques. */
final class ShopController
{
    // --- Commandes ---

    public static function orders(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        if ($site['connector'] !== 'catalogue') {
            throw Halt::notFound();
        }
        $status = Req::query('etat', 20);
        $status = isset(Orders::STATUSES[$status]) ? $status : null;
        return SiteController::page($ctx, 'site/orders', [
            'orders' => Orders::list($site['id'], $status ?? null),
            'counts' => Orders::counts($site['id']),
            'status' => $status,
            'enabled' => Orders::enabled($site),
            'waiting' => Waitlist::pending($site['id']),
        ], 'Commandes');
    }

    public static function orderStatus(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $r = Orders::setStatus($ctx['viewer'], $site, Req::str('id', 64), Req::str('status', 20));
        Session::flash($r['ok'] ? 'Commande mise à jour.' : $r['error'], $r['ok'] ? 'ok' : 'error');
        $back = Req::str('back', 20);
        return Response::redirect("/s/{$site['slug']}/commandes" . (isset(Orders::STATUSES[$back]) ? "?etat=$back" : ''));
    }

    /** Bon de commande ou facture d'une commande, prêt à imprimer ou à enregistrer en PDF. */
    public static function orderDocument(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $o = \SimpleCommerce\Db::one('SELECT * FROM sc_orders WHERE id = ? AND site_id = ?', [$p['id'], $site['id']]);
        if (!$o || $site['connector'] !== 'catalogue') {
            throw Halt::notFound();
        }
        $o['items'] = json_decode((string) $o['items'], true) ?: [];
        $invoice = $p['doc'] === 'facture';
        if ($invoice && $o['status'] === 'annulee') {
            Session::flash('Une commande annulée n\'a pas de facture.', 'error');
            return Response::redirect("/s/{$site['slug']}/commandes");
        }
        $number = $invoice ? Orders::invoiceNumber($site, $o) : null;
        return Response::page('site/order-document', ['site' => $site, 'order' => $o, 'invoice' => $invoice, 'number' => $number,
            'x' => Sites::extras($site), 'title' => ($invoice ? 'Facture ' . $number : 'Bon de commande n° ' . $o['number'])], 'bare');
    }

    public static function ordersCsv(array $p): Response
    {
        // Fichier avec les coordonnées de tous les clients : réservé à qui gère tout le site.
        $ctx = Access::site($p['slug'], 'owner');
        if ($ctx['site']['connector'] !== 'catalogue') {
            throw Halt::notFound();
        }
        return Response::file(Orders::csv($ctx['site']['id']), 'text/csv; charset=utf-8', 'commandes-' . $ctx['site']['slug'] . '-' . date('Y-m-d') . '.csv', 'no-store');
    }

    // --- Avis ---

    public static function reviews(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('avis');
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $status = Req::query('etat', 20);
        $status = isset(Reviews::STATUSES[$status]) ? $status : 'attente';
        return SiteController::page($ctx, 'site/reviews', [
            'reviews' => Reviews::list($site['id'], $status),
            'counts' => Reviews::counts($site['id']),
            'status' => $status,
            'enabled' => !empty(Sites::extras($site)['reviews']),
            'line' => '<script src="' . Config::url('/avis/' . Sites::publicKey($site) . '.js') . '"></script>',
        ], 'Avis');
    }

    public static function reviewAction(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('avis');
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $back = "/s/{$site['slug']}/avis";
        $id = Req::str('id', 64);
        switch (Req::str('do', 20)) {
            case 'activer':
            case 'desactiver':
                if ($ctx['role'] === 'editor') {
                    throw Halt::notFound();
                }
                $on = Req::str('do', 20) === 'activer';
                Sites::setExtras($site, ['reviews' => $on]);
                if (!$on && \SimpleCommerce\Services\SiteTabs::current($site, 'avis')) {
                    try {
                        \SimpleCommerce\Services\SiteTabs::set(\SimpleCommerce\Repo::site($site['id']), 'avis', false);
                    } catch (\RuntimeException $e) {
                        Session::flash('Avis fermés, mais l\'onglet n\'a pas pu être retiré : ' . $e->getMessage(), 'error');
                        return Response::redirect($back);
                    }
                }
                Access::audit($ctx['viewer'], $on ? 'reviews_on' : 'reviews_off', [], $site['id']);
                Session::flash($on ? 'Les avis sont ouverts. Ajoutez maintenant l\'onglet « Avis » à votre site.' : 'Les avis sont fermés et ne sont plus affichés sur votre site.');
                return Response::redirect($back);
            case 'publier':
                Reviews::update($site['id'], $id, ['status' => 'publie']);
                Session::flash('Avis affiché sur votre site.');
                return Response::redirect($back);
            case 'refuser':
                Reviews::update($site['id'], $id, ['status' => 'refuse']);
                Session::flash('Avis refusé : il ne sera pas affiché.');
                return Response::redirect($back);
            case 'repondre':
                Reviews::update($site['id'], $id, ['reply' => Req::str('reply', 1000)]);
                Session::flash('Réponse enregistrée.');
                return Response::redirect($back . '?etat=' . rawurlencode(Req::str('back', 20) ?: 'attente'));
            case 'supprimer':
                Reviews::delete($site['id'], $id);
                Session::flash('Avis supprimé.');
                return Response::redirect($back . '?etat=' . rawurlencode(Req::str('back', 20) ?: 'attente'));
        }
        throw Halt::notFound();
    }

    /** Bouton « Ajouter l'onglet … à mon site » : page et onglet posés tout seuls (avis, horaires…). */
    public static function tabAction(array $p): Response
    {
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $kind = Req::str('quoi', 20);
        if ($ctx['role'] === 'editor' || !isset(\SimpleCommerce\Services\SiteTabs::KINDS[$kind])) {
            throw Halt::notFound();
        }
        $back = "/s/{$site['slug']}" . \SimpleCommerce\Services\SiteTabs::KINDS[$kind]['back'];
        $on = Req::str('do', 20) === 'ajouter';
        if ($on && !\SimpleCommerce\Services\Billing::siteAllowed($site, $ctx['viewer'])) {
            Session::flash(\SimpleCommerce\Services\Billing::blockedMessage(), 'error');
            return Response::redirect($back);
        }
        try {
            Session::flash(\SimpleCommerce\Services\SiteTabs::set($site, $kind, $on));
            Access::audit($ctx['viewer'], $on ? 'tab_added' : 'tab_removed', ['page' => $kind], $site['id']);
        } catch (\RuntimeException $e) {
            Session::flash($e->getMessage(), 'error');
        }
        return Response::redirect($back);
    }

    // --- Statistiques ---

    private static function statsLine(array $site): string
    {
        return '<script src="' . Config::url('/p/' . Sites::publicKey($site) . '.js') . '" defer></script>';
    }

    public static function stats(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('statistiques');
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $days = Req::query('jours', 3) === '7' ? 7 : (Req::query('jours', 3) === '90' ? 90 : 30);
        return SiteController::page($ctx, 'site/stats', [
            'stats' => Stats::summary($site['id'], $days),
            'days' => $days,
            'enabled' => !empty(Sites::extras($site)['stats']),
            'inserted' => !empty(Sites::extras($site)['statsInserted']),
            'canInsert' => in_array($site['connector'], ['sftp', 'ftp', 'github', 'gitlab', 'bitbucket', 'netlify', 'demo'], true),
            'line' => self::statsLine($site),
        ], 'Statistiques');
    }

    public static function statsAction(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('statistiques');
        $ctx = Access::site($p['slug'], 'owner');
        $site = $ctx['site'];
        $back = "/s/{$site['slug']}/statistiques";
        $do = Req::str('do', 20);
        if ($do === 'activer' || $do === 'desactiver') {
            Sites::setExtras($site, ['stats' => $do === 'activer']);
            Access::audit($ctx['viewer'], 'stats_' . $do, [], $site['id']);
            Session::flash($do === 'activer' ? 'Statistiques activées.' : 'Statistiques arrêtées : plus rien n\'est compté.');
            return Response::redirect($back);
        }
        if ($do === 'inserer' || $do === 'retirer') {
            $on = $do === 'inserer';
            $src = Config::url('/p/' . Sites::publicKey($site) . '.js');
            try {
                $n = Sites::with($site, function (Adapter $a) use ($src, $on) {
                    if (!$a instanceof FileSiteAdapter) {
                        throw new \RuntimeException('Ce site ne peut pas être modifié page par page.');
                    }
                    return $a->setPageScript($src, $on, $on ? 'Ajout du compteur de visites Simple Commerce' : 'Retrait du compteur de visites Simple Commerce');
                }, null, true);
            } catch (\Throwable $e) {
                Session::flash(AdapterError::userMessageFor($e), 'error');
                return Response::redirect($back);
            }
            if ($on && $n['pages'] === 0) {
                Session::flash('Aucune page à compléter n\'a été trouvée sur votre site. Collez la ligne ci-dessous vous-même, puis activez les statistiques.', 'error');
                return Response::redirect($back);
            }
            Sites::setExtras($site, ['stats' => $on ? true : !empty(Sites::extras($site)['stats']), 'statsInserted' => $on]);
            Access::audit($ctx['viewer'], $on ? 'stats_inserted' : 'stats_removed', ['pages' => $n['changed']], $site['id']);
            Session::flash($on
                ? ($n['changed'] ? 'Compteur ajouté à ' . \SimpleCommerce\Support\Text::plural($n['changed'], 'page') . ' de votre site. Les prochaines visites apparaîtront ici.' : 'Le compteur était déjà sur toutes vos pages.')
                : ($n['changed'] ? 'Compteur retiré de ' . \SimpleCommerce\Support\Text::plural($n['changed'], 'page') . '.' : 'Le compteur n\'était sur aucune page.'));
            return Response::redirect($back);
        }
        throw Halt::notFound();
    }
}
