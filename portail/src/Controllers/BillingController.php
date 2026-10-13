<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Billing;
use SimpleCommerce\Support\Session;

/** Abonnement du client (Mon compte → Abonnement) et réglages Stripe de l'administrateur. */
final class BillingController
{
    public static function show(): Response
    {
        $v = Access::requireViewer();
        $user = $v['effective'];
        if (!Billing::enabled() || $user['is_admin']) {
            return Response::page('billing', ['viewer' => $v, 'off' => true, 'title' => 'Abonnement'], 'plain');
        }
        $back = Req::query('retour', 250);
        if ($back !== '' && !$v['assist']) {
            try {
                if (Billing::confirmCheckout($user, $back)) {
                    Session::flash('Merci ! Votre abonnement est en place.');
                }
            } catch (\Throwable $e) {
                Session::flash('Paiement reçu par Stripe : votre abonnement sera visible ici dans quelques instants.', 'warn');
            }
            return Response::redirect('/compte/abonnement');
        }
        return Response::page('billing', ['viewer' => $v, 'off' => false, 'row' => Billing::row($user), 'settings' => Billing::settings(),
            'active' => Billing::active($user), 'title' => 'Abonnement'], 'plain');
    }

    public static function pay(): Response
    {
        $v = Access::requireViewer();
        if ($v['assist'] || !Billing::enabled() || Req::inApp()) {
            throw Halt::notFound();
        }
        try {
            return Response::redirect(Billing::checkoutUrl($v['user'], Req::str('plan', 4)));
        } catch (\Throwable $e) {
            Session::flash(AdapterError::userMessageFor($e), 'error');
            return Response::redirect('/compte/abonnement');
        }
    }

    public static function manage(): Response
    {
        $v = Access::requireViewer();
        if ($v['assist'] || !Billing::enabled() || Req::inApp()) {
            throw Halt::notFound();
        }
        try {
            return Response::redirect(Billing::portalUrl($v['user']));
        } catch (\Throwable $e) {
            Session::flash(AdapterError::userMessageFor($e), 'error');
            return Response::redirect('/compte/abonnement');
        }
    }

    /** Avis de Stripe (adresse publique, sans session). */
    public static function webhook(): Response
    {
        $payload = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
        $ok = Billing::webhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
        return Response::json(['received' => $ok], $ok ? 200 : 400);
    }

    // --- Administration ---

    public static function admin(array $p = [], array $state = []): Response
    {
        $v = Access::requireAdmin();
        $rows = [];
        foreach (Db::all('SELECT * FROM sc_billing') as $r) {
            $rows[$r['user_id']] = $r;
        }
        return Response::page('admin/billing', $state + ['viewer' => $v, 'settings' => Billing::settings(), 'ready' => Billing::ready(),
            'keyHint' => Billing::fingerprint('secretKey'), 'hookHint' => Billing::fingerprint('webhookSecret'), 'users' => Repo::users(), 'rows' => $rows,
            'webhookUrl' => Config::url('/stripe/webhook'), 'title' => 'Abonnements', 'wide' => true], 'plain');
    }

    public static function saveAdmin(): Response
    {
        $v = Access::requireAdmin();
        if (Req::str('intent') === 'compte') {
            $uid = Req::str('userId', 64);
            $u = Repo::user($uid);
            if (!$u) {
                throw Halt::notFound();
            }
            Billing::row($u);
            $what = Req::str('status', 20);
            if ($what === 'gratuit') {
                Billing::update($uid, ['status' => 'gratuit']);
            } elseif ($what === 'essai') {
                Billing::update($uid, ['status' => 'essai', 'trial_ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400)]);
            } elseif ($what === 'normal') {
                $r = Billing::row($u);
                Billing::update($uid, ['status' => $r['subscription_id'] ? 'actif' : 'essai']);
            }
            Repo::log($v['user']['id'], null, null, 'billing_admin', ['user' => $u['email'], 'status' => $what]);
            Session::flash('Compte mis à jour.');
            return Response::redirect('/admin/abonnements#comptes');
        }
        if (Req::str('intent') === 'eteindre' || Req::str('intent') === 'allumer') {
            $on = Req::str('intent') === 'allumer';
            if ($on && !Billing::ready()) {
                return self::admin([], ['error' => 'Préparez d\'abord Stripe ci-dessous.']);
            }
            Billing::saveSettings(['enabled' => $on]);
            Repo::log($v['user']['id'], null, null, 'billing_settings', ['enabled' => $on]);
            Session::flash($on ? 'Abonnements activés.' : 'Abonnements éteints : tout le monde publie librement.');
            return Response::redirect('/admin/abonnements');
        }
        $key = trim(Req::raw('secretKey'));
        if ($key !== '' && !preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', $key)) {
            return self::admin([], ['error' => 'La clé secrète commence par « sk_live_ » (ou « sk_test_ » pour faire un essai). Stripe → Développeurs → Clés API → Clé secrète → Révéler.']);
        }
        if ($key === '' && Billing::fingerprint('secretKey') === '') {
            return self::admin([], ['error' => 'Collez votre clé secrète Stripe.']);
        }
        $num = fn (string $k, float $def) => max(0, min(9999, (float) str_replace(',', '.', Req::str($k, 10) !== '' ? Req::str($k, 10) : (string) $def)));
        $monthly = $num('monthly', 9);
        $yearly = $num('yearly', 90);
        if ($monthly <= 0 && $yearly <= 0) {
            return self::admin([], ['error' => 'Indiquez au moins un prix (par mois ou par an).']);
        }
        $patch = ['trialDays' => max(0, min(730, (int) Req::str('trialDays', 4)))];
        if ($key !== '') {
            $old = Billing::settings();
            // Nouvelle clé (autre compte Stripe, ou passage de l'essai au réel) : tout est à recréer de ce côté.
            $patch += ['secretKey' => $key, 'productId' => '', 'priceId' => '', 'priceIdYear' => '', 'webhookId' => '', 'webhookSecret' => ''];
        }
        Billing::saveSettings($patch);
        try {
            $done = Billing::setupStripe($monthly, $yearly);
        } catch (\Throwable $e) {
            $detail = $e instanceof AdapterError ? (string) $e->detail : '';
            return self::admin([], ['error' => 'Stripe a refusé : ' . ($detail !== '' ? $detail : 'vérifiez la clé et votre connexion.')]);
        }
        $promo = Req::str('promoCode', 30);
        if ($promo !== '') {
            try {
                $done[] = 'code promo « ' . Billing::setupPromo($promo) . ' » (0 €) prêt';
            } catch (\Throwable $e) {
                $detail = $e instanceof AdapterError ? ($e->detail ?: $e->userMessage) : '';
                return self::admin([], ['error' => 'Prix enregistrés, mais le code promo a été refusé : ' . $detail]);
            }
        }
        Billing::saveSettings(['enabled' => true]);
        Repo::log($v['user']['id'], null, null, 'billing_settings', ['enabled' => true, 'done' => $done]);
        $hookMissing = Billing::fingerprint('webhookSecret') === '';
        Session::flash('Abonnements activés' . ($done ? ' (' . implode(', ', $done) . ')' : '') . '. Pour publier, chaque client s\'abonne avec sa carte : ' . (Billing::freeLabel($patch['trialDays']) !== '' ? Billing::freeLabel($patch['trialDays']) . ' offert' . (str_starts_with(Billing::freeLabel($patch['trialDays']), 'les') ? 's' : (str_starts_with(Billing::freeLabel($patch['trialDays']), 'la') ? 'e' : '')) . ', puis prélèvement.' : 'prélèvement tout de suite.')
            . ($hookMissing ? ' Attention : les avis de paiement n\'ont pas pu être branchés (adresse du portail sans https).' : ''), $hookMissing ? 'warn' : 'ok');
        return Response::redirect('/admin/abonnements');
    }

    public static function revenue(): Response
    {
        $v = Access::requireAdmin();
        $report = null;
        $error = null;
        if (Billing::ready()) {
            try {
                $report = Billing::revenue();
            } catch (\Throwable $e) {
                $error = 'Stripe ne répond pas pour le moment. Réessayez dans quelques minutes.';
            }
        }
        $local = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM sc_billing GROUP BY status') as $r) {
            $local[$r['status']] = (int) $r['n'];
        }
        return Response::page('admin/revenue', ['viewer' => $v, 'report' => $report, 'error' => $error, 'local' => $local, 'users' => count(Repo::users()),
            'ready' => Billing::ready(), 'title' => 'Revenus', 'wide' => true], 'plain');
    }
}
