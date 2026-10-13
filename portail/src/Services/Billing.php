<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Http;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Vault;

/**
 * Abonnements payants (Stripe). Désactivés tant que l'administrateur ne les a pas réglés et allumés.
 * Chaque compte a un essai gratuit ; ensuite il faut un abonnement pour publier (la lecture reste possible).
 * Ce sont les propriétaires d'un site qui paient : les personnes invitées en profitent.
 * Le paiement se fait sur la page sécurisée de Stripe (Checkout) ; le portail ne voit jamais la carte.
 *
 * sc_billing.status : essai | actif | impaye | termine | gratuit
 */
final class Billing
{
    private const AD = 'simplecommerce-billing';
    private const API = 'https://api.stripe.com/v1';
    public const LABELS = ['essai' => 'Essai gratuit', 'actif' => 'Abonné', 'impaye' => 'Paiement en retard', 'termine' => 'Abonnement arrêté', 'gratuit' => 'Offert'];

    /** Mis à vrai pendant les actions automatiques (commande d'un client, fermeture programmée) : jamais bloquées. */
    public static bool $system = false;

    // --- Réglages (administrateur) ---

    public static function settings(): array
    {
        $s = json_decode((string) Repo::meta('billing_settings', '{}'), true) ?: [];
        return $s + ['enabled' => false, 'priceId' => '', 'priceLabel' => '', 'trialDays' => 14, 'secretKey' => '', 'webhookSecret' => ''];
    }

    public static function saveSettings(array $patch): void
    {
        $s = self::settings();
        foreach (['secretKey', 'webhookSecret'] as $k) {
            if (array_key_exists($k, $patch)) {
                $patch[$k] = $patch[$k] === '' ? '' : Vault::seal(['v' => $patch[$k]], self::AD);
            }
        }
        Repo::setMeta('billing_settings', json_encode(array_replace($s, $patch)));
    }

    private static function secret(string $k): string
    {
        $sealed = (string) (self::settings()[$k] ?? '');
        if ($sealed === '') {
            return '';
        }
        try {
            return (string) (Vault::unseal($sealed, self::AD)['v'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function fingerprint(string $k): string
    {
        $v = self::secret($k);
        return $v === '' ? '' : Vault::fingerprint($v);
    }

    public static function ready(): bool
    {
        $s = self::settings();
        return $s['priceId'] !== '' && self::secret('secretKey') !== '';
    }

    public static function enabled(): bool
    {
        return !empty(self::settings()['enabled']) && self::ready();
    }

    // --- État d'un compte ---

    public static function row(array $user): array
    {
        $r = Db::one('SELECT * FROM sc_billing WHERE user_id = ?', [$user['id']]);
        if (!$r) {
            // Premier passage depuis l'ouverture des abonnements : l'essai commence maintenant.
            $r = ['user_id' => $user['id'], 'status' => 'essai', 'trial_ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + max(0, (int) self::settings()['trialDays']) * 86400),
                'customer_id' => null, 'subscription_id' => null, 'period_end' => null, 'updated_at' => Db::now()];
            try {
                Db::insert('sc_billing', $r);
            } catch (\PDOException) {
                return Db::one('SELECT * FROM sc_billing WHERE user_id = ?', [$user['id']]) ?? $r;
            }
        }
        return $r;
    }

    public static function update(string $userId, array $patch): void
    {
        $patch['updated_at'] = Db::now();
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($patch)));
        Db::run("UPDATE sc_billing SET $sets WHERE user_id = ?", [...array_values($patch), $userId]);
    }

    /** Ce compte peut-il publier ? */
    public static function active(array $user): bool
    {
        if (!self::enabled() || !empty($user['is_admin'])) {
            return true;
        }
        $r = self::row($user);
        return match ($r['status']) {
            'gratuit', 'actif', 'impaye' => true, // en retard : Stripe réessaie le paiement pendant quelques jours
            'essai' => $r['trial_ends_at'] > Db::now(),
            'termine' => $r['period_end'] !== null && $r['period_end'] > Db::now(),
            default => false,
        };
    }

    /** Libellé de la situation (« Essai terminé » plutôt que « Essai gratuit » une fois la date passée). */
    public static function label(array $row): string
    {
        return $row['status'] === 'essai' && $row['trial_ends_at'] <= Db::now() ? 'Essai terminé' : (self::LABELS[$row['status']] ?? $row['status']);
    }

    public static function daysLeft(array $row): int
    {
        return max(0, (int) ceil((strtotime((string) $row['trial_ends_at']) - time()) / 86400));
    }

    /** Peut-on publier sur ce site ? Oui si l'un de ses propriétaires est à jour. */
    public static function siteAllowed(array $site, ?array $viewer = null): bool
    {
        if (self::$system || !self::enabled()) {
            return true;
        }
        if ($viewer && !empty($viewer['user']['is_admin'])) {
            return true;
        }
        foreach (Repo::members($site['id']) as $m) {
            if ($m['role'] === 'owner' && ($u = Repo::user($m['user_id'])) && self::active($u)) {
                return true;
            }
        }
        return false;
    }

    public static function blockedMessage(): string
    {
        return 'La période d\'essai est terminée. Pour publier de nouveau, le propriétaire du site doit choisir l\'abonnement dans « Mon compte → Abonnement ». Rien n\'a été effacé.';
    }

    // --- Stripe ---

    private static function stripe(string $method, string $path, array $form = []): array
    {
        try {
            $res = self::send($method, $path, $form);
        } catch (AdapterError $e) {
            Log::warn('Stripe injoignable', ['err' => $e->detail ?? $e->getMessage()]);
            throw new AdapterError('network', 'Le paiement est indisponible pour le moment. Réessayez dans quelques minutes.', 'Stripe injoignable');
        }
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 400 || !is_array($data)) {
            Log::warn('Stripe', ['status' => $res['status'], 'error' => is_array($data) ? ($data['error']['message'] ?? '') : substr($res['body'], 0, 200)]);
            throw new AdapterError('remote', 'Le paiement est indisponible pour le moment. Réessayez dans quelques minutes.', 'Stripe HTTP ' . $res['status']);
        }
        return $data;
    }

    private static function send(string $method, string $path, array $form): array
    {
        return Http::request($method, self::API . $path, [
            'Authorization' => 'Bearer ' . self::secret('secretKey'),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Stripe-Version' => '2024-06-20',
        ], $form ? http_build_query($form, '', '&') : null);
    }

    /** Adresse de la page de paiement Stripe pour s'abonner. */
    public static function checkoutUrl(array $user): string
    {
        $r = self::row($user);
        $form = [
            'mode' => 'subscription',
            'line_items' => [['price' => self::settings()['priceId'], 'quantity' => 1]],
            'client_reference_id' => $user['id'],
            'success_url' => Config::url('/compte/abonnement?retour={CHECKOUT_SESSION_ID}'),
            'cancel_url' => Config::url('/compte/abonnement'),
            'locale' => 'fr',
            'allow_promotion_codes' => 'true',
            'subscription_data' => ['metadata' => ['user_id' => $user['id']]],
        ];
        if ($r['customer_id']) {
            $form['customer'] = $r['customer_id'];
        } else {
            $form['customer_email'] = $user['email'];
        }
        // Essai pas fini : le premier paiement attend la fin de l'essai.
        if ($r['status'] === 'essai' && ($end = strtotime((string) $r['trial_ends_at'])) > time() + 48 * 3600) {
            $form['subscription_data']['trial_end'] = $end;
        }
        $s = self::stripe('POST', '/checkout/sessions', $form);
        return (string) $s['url'];
    }

    /** Retour de la page de paiement : on relit la session tout de suite (sans attendre l'avis de Stripe). */
    public static function confirmCheckout(array $user, string $sessionId): bool
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $sessionId)) {
            return false;
        }
        $s = self::stripe('GET', '/checkout/sessions/' . $sessionId . '?expand[]=subscription');
        if (($s['client_reference_id'] ?? '') !== $user['id'] || !in_array($s['status'] ?? '', ['complete'], true)) {
            return false;
        }
        self::row($user);
        self::update($user['id'], ['customer_id' => (string) $s['customer'], 'subscription_id' => is_array($s['subscription']) ? $s['subscription']['id'] : (string) $s['subscription']]);
        if (is_array($s['subscription'])) {
            self::applySubscription($s['subscription']);
        } else {
            self::update($user['id'], ['status' => 'actif']);
        }
        return true;
    }

    /** Adresse de l'espace Stripe où le client change sa carte, voit ses factures ou arrête. */
    public static function portalUrl(array $user): string
    {
        $r = self::row($user);
        if (!$r['customer_id']) {
            throw new AdapterError('invalid', 'Aucun abonnement à gérer pour le moment.');
        }
        return (string) self::stripe('POST', '/billing_portal/sessions', ['customer' => $r['customer_id'], 'return_url' => Config::url('/compte/abonnement')])['url'];
    }

    /** Met à jour un compte d'après un abonnement Stripe. */
    public static function applySubscription(array $sub): void
    {
        $row = Db::one('SELECT * FROM sc_billing WHERE subscription_id = ? OR (customer_id = ? AND customer_id IS NOT NULL)', [(string) $sub['id'], (string) ($sub['customer'] ?? '')])
            ?? (($uid = (string) ($sub['metadata']['user_id'] ?? '')) !== '' ? Db::one('SELECT * FROM sc_billing WHERE user_id = ?', [$uid]) : null);
        if (!$row) {
            Log::warn('abonnement Stripe sans compte', ['sub' => $sub['id'] ?? '?']);
            return;
        }
        $status = match ((string) ($sub['status'] ?? '')) {
            'active', 'trialing' => 'actif',
            'past_due', 'unpaid', 'incomplete' => 'impaye',
            default => 'termine',
        };
        $end = $sub['current_period_end'] ?? ($sub['items']['data'][0]['current_period_end'] ?? null);
        if ($row['status'] === 'gratuit') {
            $status = 'gratuit'; // offert par l'administrateur : on garde
        }
        self::update($row['user_id'], ['status' => $status, 'subscription_id' => (string) $sub['id'], 'customer_id' => (string) ($sub['customer'] ?? $row['customer_id']),
            'period_end' => is_numeric($end) ? gmdate('Y-m-d\TH:i:s\Z', (int) $end) : $row['period_end']]);
        Repo::log($row['user_id'], null, null, 'billing_' . $status, ['sub' => substr((string) $sub['id'], -6)]);
    }

    /** Avis envoyé par Stripe (signature vérifiée). @return bool vrai si l'avis est authentique */
    public static function webhook(string $payload, string $signature): bool
    {
        $secret = self::secret('webhookSecret');
        if ($secret === '' || !self::checkSignature($payload, $signature, $secret)) {
            return false;
        }
        $event = json_decode($payload, true);
        $obj = $event['data']['object'] ?? null;
        if (!is_array($obj)) {
            return true;
        }
        switch ($event['type'] ?? '') {
            case 'checkout.session.completed':
                $uid = (string) ($obj['client_reference_id'] ?? '');
                if ($uid !== '' && ($u = Repo::user($uid))) {
                    self::row($u);
                    self::update($uid, ['customer_id' => (string) $obj['customer'], 'subscription_id' => (string) $obj['subscription'], 'status' => 'actif']);
                }
                break;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                self::applySubscription($obj);
                break;
        }
        return true;
    }

    public static function checkSignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if ($t === null || !ctype_digit($t) || abs(time() - (int) $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }
        return false;
    }
}
