<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Bookings;
use SimpleCommerce\Services\Hours;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Support\Session;

/** Page « Rendez-vous » d'un site (réglages + liste) et annulation par le client. */
final class BookingController
{
    public static function page(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('rendezvous');
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $s = Bookings::settings($site);
        $lines = implode("\n", array_map(fn ($sv) => $sv['nom'] . ' | ' . $sv['duree'] . ($sv['prix'] !== '' ? ' | ' . $sv['prix'] : ''), $s['services']));
        return SiteController::page($ctx, 'site/bookings', ['settings' => $s, 'servicesText' => $lines, 'list' => Bookings::list($site['id']),
            'hours' => Hours::week($site), 'auto' => \SimpleCommerce\Services\Translation::supported($site), 'line' => '<script src="' . Config::url('/rdv/' . Sites::publicKey($site) . '.js') . '"></script>'], 'Rendez-vous');
    }

    public static function action(array $p): Response
    {
        \SimpleCommerce\Services\Features::need('rendezvous');
        $ctx = Access::site($p['slug']);
        $site = $ctx['site'];
        $back = "/s/{$site['slug']}/rendez-vous";
        if (Req::str('do', 20) === 'annuler') {
            $b = Db::one('SELECT * FROM sc_bookings WHERE id = ? AND site_id = ?', [Req::str('id', 64), $site['id']]);
            if (!$b) {
                throw Halt::notFound();
            }
            Bookings::cancel($b, false);
            Access::audit($ctx['viewer'], 'booking_canceled', [], $site['id']);
            Session::flash('Rendez-vous annulé. Le client a été prévenu par e-mail.');
            return Response::redirect($back);
        }
        if ($ctx['role'] === 'editor') {
            throw Halt::notFound();
        }
        if (in_array(Req::str('do', 20), ['onglet', 'retirer-onglet'], true)) {
            return self::tab($ctx, Req::str('do', 20) === 'onglet');
        }
        // Prestations : une par ligne, « Nom | durée en minutes | prix ».
        $services = [];
        $errors = [];
        foreach (preg_split('/\R/', Req::str('services', 4000)) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $dur = (int) preg_replace('/\D/', '', $parts[1] ?? '');
            if ($parts[0] === '' || $dur < 5 || $dur > 600) {
                $errors[] = 'Ligne ' . ($n + 1) . ' : écrivez « Nom | durée en minutes | prix », par exemple « Coupe femme | 45 | 35 € ».';
                continue;
            }
            $services[] = ['nom' => mb_substr($parts[0], 0, 120), 'duree' => $dur, 'prix' => mb_substr($parts[2] ?? '', 0, 40)];
        }
        if ($errors) {
            Session::flash(implode(' ', array_slice($errors, 0, 3)), 'error');
            return Response::redirect($back . '#reglages');
        }
        $week = [];
        foreach ((array) Req::arr('week') as $i => $v) {
            if ($i >= 0 && $i < 7 && is_string($v)) {
                $week[(int) $i] = mb_substr(trim($v), 0, 120);
            }
        }
        $booking = [
            'enabled' => Req::str('enabled') === '1' && $services,
            'services' => array_slice($services, 0, 30),
            'week' => array_replace(array_fill(0, 7, ''), $week),
            'notice' => max(0, min(240, (int) Req::str('notice', 4))),
            'days' => max(1, min(180, (int) Req::str('days', 4))),
            'capacity' => max(1, min(20, (int) Req::str('capacity', 3))),
            'message' => Req::str('message', 300),
            'tab' => Bookings::settings($site)['tab'] ?? null,
        ];
        Sites::setExtras($site, ['booking' => $booking]);
        Access::audit($ctx['viewer'], 'booking_settings', ['enabled' => $booking['enabled']], $site['id']);
        if (!$booking['enabled'] && !empty($booking['tab'])) {
            // Rendez-vous fermés : l'onglet et sa page quittent aussi le site.
            return self::tab(['site' => \SimpleCommerce\Repo::site($site['id'])] + $ctx, false);
        }
        Session::flash($booking['enabled'] ? 'Prise de rendez-vous enregistrée et ouverte.' : (Req::str('enabled') === '1' && !$services ? 'Ajoutez au moins une prestation pour ouvrir les rendez-vous.' : 'Réglages enregistrés. La prise de rendez-vous est fermée.'),
            Req::str('enabled') === '1' && !$services ? 'error' : 'ok');
        return Response::redirect($back);
    }

    public static function cancelPage(array $p, ?string $done = null): Response
    {
        $b = Bookings::byToken($p['token']);
        $site = $b ? \SimpleCommerce\Repo::site($b['site_id']) : null;
        if (!$b || !$site) {
            return Response::page('message', ['title' => 'Lien introuvable', 'text' => 'Ce lien d\'annulation n\'est pas valable.'], 'auth', 404);
        }
        return Response::page('booking-cancel', ['b' => $b, 'site' => $site, 'token' => $p['token'], 'done' => $done, 'title' => 'Votre rendez-vous'], 'auth');
    }

    public static function cancelByCustomer(array $p): Response
    {
        $b = Bookings::byToken($p['token']);
        if (!$b) {
            return self::cancelPage($p);
        }
        if ($b['status'] === 'confirme' && $b['starts_at'] > Db::now()) {
            Bookings::cancel($b, true);
        }
        return self::cancelPage($p, 'Votre rendez-vous est annulé. L\'établissement a été prévenu.');
    }

    /** Onglet « Rendez-vous » ajouté (ou retiré) automatiquement dans le menu du site, avec sa page. */
    private static function tab(array $ctx, bool $on): Response
    {
        $site = $ctx['site'];
        $back = "/s/{$site['slug']}/rendez-vous";
        if (!\SimpleCommerce\Services\Translation::supported($site)) {
            Session::flash('Pour ce type de site, collez la ligne ci-dessous dans votre site.', 'error');
            return Response::redirect($back);
        }
        if ($on && !\SimpleCommerce\Services\Billing::siteAllowed($site, $ctx['viewer'])) {
            Session::flash(\SimpleCommerce\Services\Billing::blockedMessage(), 'error');
            return Response::redirect($back);
        }
        $src = Config::url('/rdv/' . Sites::publicKey($site) . '.js');
        $body = '<section data-sc-rendez-vous style="max-width:880px;margin:0 auto;padding:56px 20px 80px">'
            . "\n  <h1>Prendre rendez-vous</h1>"
            . "\n  <p>Choisissez une prestation, puis le jour et l'heure qui vous conviennent. Vous recevrez une confirmation par e-mail.</p>"
            . "\n  <script src=\"" . htmlspecialchars($src, ENT_QUOTES) . "\"></script>\n</section>";
        try {
            $r = Sites::with($site, function (\SimpleCommerce\Adapters\Adapter $a) use ($on, $site, $body) {
                if (!$a instanceof \SimpleCommerce\Adapters\Files\FileSiteAdapter) {
                    throw new \RuntimeException('Ce site ne peut pas recevoir de nouvel onglet automatiquement.');
                }
                return $a->setTabPage('rdv', 'rendez-vous.html', 'Rendez-vous', 'Prendre rendez-vous — ' . $site['name'], $body, $on,
                    $on ? 'Ajout de l\'onglet Rendez-vous' : 'Retrait de l\'onglet Rendez-vous');
            }, null, true);
        } catch (\RuntimeException $e) {
            Session::flash($e->getMessage(), 'error');
            return Response::redirect($back);
        } catch (\Throwable $e) {
            Session::flash(\SimpleCommerce\Adapters\AdapterError::userMessageFor($e), 'error');
            return Response::redirect($back);
        }
        $booking = Bookings::settings($site);
        $booking['tab'] = $on ? $r['file'] : null;
        Sites::setExtras($site, ['booking' => $booking]);
        Access::audit($ctx['viewer'], $on ? 'booking_tab_added' : 'booking_tab_removed', [], $site['id']);
        Session::flash($on ? 'C\'est fait : l\'onglet « Rendez-vous » est sur votre site, dans le menu de toutes les pages. Rechargez votre site pour le voir.'
            : 'Prise de rendez-vous fermée. L\'onglet « Rendez-vous » et sa page ont été retirés de votre site.');
        return Response::redirect($back);
    }
}
