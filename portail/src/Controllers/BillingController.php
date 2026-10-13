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
        if ($v['assist'] || !Billing::enabled()) {
            throw Halt::notFound();
        }
        try {
            return Response::redirect(Billing::checkoutUrl($v['user']));
        } catch (\Throwable $e) {
            Session::flash(AdapterError::userMessageFor($e), 'error');
            return Response::redirect('/compte/abonnement');
        }
    }

    public static function manage(): Response
    {
        $v = Access::requireViewer();
        if ($v['assist'] || !Billing::enabled()) {
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
        $priceId = Req::str('priceId', 120);
        $key = Req::raw('secretKey');
        $hook = Req::raw('webhookSecret');
        if ($priceId !== '' && !preg_match('/^price_[A-Za-z0-9]{6,}$/', $priceId)) {
            return self::admin([], ['error' => 'L\'identifiant du prix commence par « price_ » (Stripe → Catalogue de produits → votre produit → le prix).']);
        }
        if ($key !== '' && !preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', trim($key))) {
            return self::admin([], ['error' => 'La clé secrète commence par « sk_live_ » (ou « rk_live_ » pour une clé limitée). Stripe → Développeurs → Clés API.']);
        }
        if ($hook !== '' && !preg_match('/^whsec_[A-Za-z0-9]{10,}$/', trim($hook))) {
            return self::admin([], ['error' => 'Le secret du webhook commence par « whsec_ ».']);
        }
        $patch = ['priceId' => $priceId, 'priceLabel' => Req::str('priceLabel', 60), 'trialDays' => max(0, min(365, (int) Req::str('trialDays', 4))),
            'enabled' => Req::str('enabled') === '1'];
        if (trim($key) !== '') {
            $patch['secretKey'] = trim($key);
        }
        if (trim($hook) !== '') {
            $patch['webhookSecret'] = trim($hook);
        }
        Billing::saveSettings($patch);
        if ($patch['enabled'] && !Billing::ready()) {
            Billing::saveSettings(['enabled' => false]);
            return self::admin([], ['error' => 'Réglages enregistrés, mais les abonnements restent éteints : il manque la clé secrète ou l\'identifiant du prix.']);
        }
        Repo::log($v['user']['id'], null, null, 'billing_settings', ['enabled' => $patch['enabled']]);
        Session::flash($patch['enabled'] ? 'Abonnements activés. Chaque client a maintenant ' . $patch['trialDays'] . ' jours d\'essai gratuit.' : 'Réglages enregistrés. Les abonnements sont éteints : tout le monde publie librement.');
        return Response::redirect('/admin/abonnements');
    }
}
