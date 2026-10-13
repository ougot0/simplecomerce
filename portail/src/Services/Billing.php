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
 * Pour publier, il faut s'abonner (carte demandée) ; l'abonnement commence par N jours gratuits chez Stripe,
 * puis le premier prélèvement. Sans abonnement : lecture seule. L'administrateur peut offrir un accès ou des jours d'essai.
 * Ce sont les propriétaires d'un site qui paient : les personnes invitées en profitent.
 * Le paiement se fait sur la page sécurisée de Stripe (Checkout) ; le portail ne voit jamais la carte.
 *
 * sc_billing.status : essai | actif | impaye | termine | gratuit
 */
final class Billing
{
    private const AD = 'simplecommerce-billing';
    public const EVENTS = ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'invoice.paid'];
    public const PLANS = ['mois' => 'priceId', 'an' => 'priceIdYear'];
    public const LABELS = ['essai' => 'Pas encore abonné', 'actif' => 'Abonné', 'impaye' => 'Paiement en retard', 'termine' => 'Abonnement arrêté', 'gratuit' => 'Offert'];

    /** Mis à vrai pendant les actions automatiques (commande d'un client, fermeture programmée) : jamais bloquées. */
    public static bool $system = false;

    // --- Réglages (administrateur) ---

    public static function settings(): array
    {
        $s = json_decode((string) Repo::meta('billing_settings', '{}'), true) ?: [];
        return $s + ['enabled' => false, 'priceId' => '', 'priceIdYear' => '', 'monthly' => 9, 'yearly' => 90, 'productId' => '', 'webhookId' => '',
            'priceLabel' => '', 'trialDays' => 365, 'promoCode' => '', 'promoId' => '', 'secretKey' => '', 'webhookSecret' => ''];
    }

    private static function api(): string
    {
        return rtrim((string) (getenv('SC_STRIPE_API') ?: 'https://api.stripe.com'), '/') . '/v1';
    }

    /** « la première année », « le premier mois », « les 7 premiers jours »… (0 = '') */
    public static function freeLabel(?int $days = null): string
    {
        $d = $days ?? (int) self::settings()['trialDays'];
        return match (true) {
            $d <= 0 => '',
            $d >= 365 && $d <= 366 => 'la première année',
            $d === 730 => 'les deux premières années',
            $d >= 28 && $d <= 31 => 'le premier mois',
            $d === 1 => 'le premier jour',
            default => "les $d premiers jours",
        };
    }

    /** « La première année est gratuite », « Les 7 premiers jours sont gratuits »… ('' sans gratuité) */
    public static function freeSentence(): string
    {
        $l = self::freeLabel();
        if ($l === '') {
            return '';
        }
        $verb = str_starts_with($l, 'les') ? ' sont gratuits' : (str_starts_with($l, 'la') ? ' est gratuite' : ' est gratuit');
        return mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1) . $verb;
    }

    public static function euros(float $n): string
    {
        return \SimpleCommerce\Support\Text::price($n);
    }

    /** Formules proposées aux clients. @return array<string, array{label: string, amount: float, priceId: string}> */
    public static function plans(): array
    {
        $s = self::settings();
        $out = [];
        if ($s['priceId'] !== '') {
            $out['mois'] = ['label' => self::euros((float) $s['monthly']) . ' par mois', 'amount' => (float) $s['monthly'], 'priceId' => $s['priceId']];
        }
        if ($s['priceIdYear'] !== '') {
            $out['an'] = ['label' => self::euros((float) $s['yearly']) . ' par an', 'amount' => (float) $s['yearly'], 'priceId' => $s['priceIdYear']];
        }
        return $out;
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
        return ($s['priceId'] !== '' || $s['priceIdYear'] !== '') && self::secret('secretKey') !== '';
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
            // Pas d'essai sans abonnement : les jours gratuits commencent quand la personne s'abonne avec sa carte.
            $r = ['user_id' => $user['id'], 'status' => 'essai', 'trial_ends_at' => Db::now(),
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
        if ($row['status'] === 'essai' && $row['trial_ends_at'] > Db::now()) {
            return 'Essai offert';
        }
        if ($row['status'] === 'actif' && self::inTrial($row)) {
            return 'Abonné — jours gratuits';
        }
        return self::LABELS[$row['status']] ?? $row['status'];
    }

    /** Abonné, mais encore dans ses jours gratuits (rien n'a encore été prélevé). */
    public static function inTrial(array $row): bool
    {
        return $row['status'] === 'actif' && (string) $row['trial_ends_at'] > Db::now() && (string) $row['subscription_id'] !== '';
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
        return 'Pour publier, le propriétaire du site doit avoir un abonnement (« Mon compte → Abonnement »).' . (self::freeSentence() !== '' ? ' ' . self::freeSentence() . '.' : '') . ' Rien n\'a été effacé.';
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
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : substr($res['body'], 0, 200);
            Log::warn('Stripe', ['status' => $res['status'], 'error' => $msg]);
            throw new AdapterError('remote', 'Le paiement est indisponible pour le moment. Réessayez dans quelques minutes.', ($res['status'] === 401 ? 'clé refusée — ' : '') . $msg);
        }
        return $data;
    }

    private static function send(string $method, string $path, array $form): array
    {
        return Http::request($method, self::api() . $path, [
            'Authorization' => 'Bearer ' . self::secret('secretKey'),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Stripe-Version' => '2024-06-20',
        ], $form ? http_build_query($form, '', '&') : null);
    }

    /** Adresse de la page de paiement Stripe pour s'abonner. */
    public static function checkoutUrl(array $user, string $plan = 'mois'): string
    {
        $r = self::row($user);
        $plans = self::plans();
        $chosen = $plans[$plan] ?? reset($plans);
        if (!$chosen) {
            throw new AdapterError('invalid', 'Aucune formule d\'abonnement n\'est prête.');
        }
        $form = [
            'mode' => 'subscription',
            'line_items' => [['price' => $chosen['priceId'], 'quantity' => 1]],
            'client_reference_id' => $user['id'],
            'success_url' => Config::url('/compte/abonnement?retour={CHECKOUT_SESSION_ID}'),
            'cancel_url' => Config::url('/compte/abonnement'),
            'locale' => 'fr',
            'allow_promotion_codes' => 'true',
            'payment_method_collection' => 'always', // la carte est toujours demandée, même pendant les jours gratuits
            'subscription_data' => ['metadata' => ['user_id' => $user['id']]],
        ];
        if ($r['customer_id']) {
            $form['customer'] = $r['customer_id'];
        } else {
            $form['customer_email'] = $user['email'];
        }
        // Jours gratuits : seulement pour un premier abonnement (pas après un arrêt, pour éviter les essais à répétition).
        $days = (int) self::settings()['trialDays'];
        if ($days > 0 && !$r['subscription_id']) {
            $form['subscription_data']['trial_period_days'] = $days;
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

    /**
     * Prépare tout chez Stripe à partir de la seule clé secrète : le produit « Simple Commerce », un prix mensuel,
     * un prix annuel et l'adresse d'avis (webhook). Un prix Stripe ne se modifie pas : si le montant change, un
     * nouveau prix est créé (les abonnés existants gardent l'ancien). @return string[] ce qui a été fait
     */
    public static function setupStripe(float $monthly, float $yearly): array
    {
        $s = self::settings();
        $done = [];
        $productId = $s['productId'];
        if ($productId === '') {
            $productId = (string) self::stripe('POST', '/products', ['name' => 'Simple Commerce', 'description' => 'Abonnement au portail Simple Commerce'])['id'];
            $done[] = 'produit « Simple Commerce » créé';
        }
        $patch = ['productId' => $productId, 'monthly' => $monthly, 'yearly' => $yearly];
        foreach ([['priceId', $monthly, (float) $s['monthly'], 'month', 'Mensuel'], ['priceIdYear', $yearly, (float) $s['yearly'], 'year', 'Annuel']] as [$k, $amount, $old, $interval, $nick]) {
            if ($amount <= 0) {
                $patch[$k] = '';
                continue;
            }
            if ($s[$k] !== '' && abs($amount - $old) < 0.001 && $s['productId'] === $productId) {
                continue;
            }
            $patch[$k] = (string) self::stripe('POST', '/prices', ['product' => $productId, 'currency' => 'eur', 'unit_amount' => (int) round($amount * 100),
                'recurring' => ['interval' => $interval], 'nickname' => $nick])['id'];
            $done[] = 'prix ' . self::euros($amount) . ($interval === 'month' ? ' par mois' : ' par an') . ' créé';
        }
        $url = Config::url('/stripe/webhook');
        if (str_starts_with($url, 'https://') && ($s['webhookId'] === '' || self::secret('webhookSecret') === '')) {
            // L'ancien avis de la même adresse (s'il existe) est remplacé : son secret ne peut plus être relu.
            foreach ((array) (self::stripe('GET', '/webhook_endpoints?limit=100')['data'] ?? []) as $w) {
                if (($w['url'] ?? '') === $url) {
                    self::stripe('DELETE', '/webhook_endpoints/' . $w['id']);
                }
            }
            $w = self::stripe('POST', '/webhook_endpoints', ['url' => $url, 'enabled_events' => self::EVENTS, 'description' => 'Simple Commerce']);
            $patch['webhookId'] = (string) $w['id'];
            $patch['webhookSecret'] = (string) $w['secret'];
            $done[] = 'avis de paiement branchés';
        } elseif ($s['webhookId'] !== '') {
            // Avis déjà branchés : on s'assure qu'ils comprennent les nouveaux évènements (paiements pour le parrainage).
            try {
                self::stripe('POST', '/webhook_endpoints/' . rawurlencode($s['webhookId']), ['enabled_events' => self::EVENTS]);
            } catch (\Throwable) {
            }
        }
        self::saveSettings($patch);
        return $done;
    }

    /**
     * Code promo « 100 % de réduction, pour toujours » (tests, amis) : un coupon Stripe + un code que l'on tape
     * sur la page de paiement (« Ajouter un code promotionnel »). Avec ce code, l'abonnement coûte 0 €.
     */
    public static function setupPromo(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) < 8) {
            throw new AdapterError('invalid', 'Le code promo doit faire au moins 8 lettres ou chiffres, pour ne pas être deviné.');
        }
        $s = self::settings();
        if ($s['promoCode'] === $code && $s['promoId'] !== '') {
            return $code;
        }
        // Un seul code actif à la fois : l'ancien est désactivé (un code connu de trop de monde ne sert plus).
        if ($s['promoId'] !== '') {
            try {
                self::stripe('POST', '/promotion_codes/' . rawurlencode($s['promoId']), ['active' => 'false']);
            } catch (\Throwable) {
            }
        }
        $existing = (array) (self::stripe('GET', '/promotion_codes?code=' . rawurlencode($code) . '&active=true&limit=1')['data'] ?? []);
        if ($existing) {
            $id = (string) $existing[0]['id'];
        } else {
            $coupon = self::stripe('POST', '/coupons', ['percent_off' => 100, 'duration' => 'forever', 'name' => 'Gratuit (100 %)']);
            // Au plus 10 utilisations : même si le code circule, il ne peut pas servir à tout le monde.
            $id = (string) self::stripe('POST', '/promotion_codes', ['coupon' => $coupon['id'], 'code' => $code, 'max_redemptions' => 10])['id'];
        }
        self::saveSettings(['promoCode' => $code, 'promoId' => $id]);
        return $code;
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

    /** Avoir (montant négatif sur le solde du client Stripe) : déduit de ses prochaines factures. */
    public static function addCredit(string $customerId, int $cents, string $why): void
    {
        self::stripe('POST', '/customers/' . rawurlencode($customerId) . '/balance_transactions', ['amount' => -abs($cents), 'currency' => 'eur', 'description' => $why]);
    }

    /** Liste complète d'une ressource Stripe (pages de 100, au plus 1 000 éléments). */
    private static function stripeAll(string $path): array
    {
        $out = [];
        $after = null;
        for ($i = 0; $i < 10; $i++) {
            $page = self::stripe('GET', $path . (str_contains($path, '?') ? '&' : '?') . 'limit=100' . ($after ? '&starting_after=' . rawurlencode($after) : ''));
            $data = (array) ($page['data'] ?? []);
            $out = array_merge($out, $data);
            if (empty($page['has_more']) || !$data) {
                break;
            }
            $after = (string) end($data)['id'];
        }
        return $out;
    }

    /**
     * Tableau des revenus pour l'administrateur, lu chez Stripe : abonnés, revenu mensuel régulier,
     * ce qui arrivera après les périodes gratuites, encaissé sur 30 jours et depuis le 1er janvier, arrêts.
     */
    public static function revenue(): array
    {
        $subs = self::stripeAll('/subscriptions?status=all');
        $monthly = fn (array $s) => array_sum(array_map(function ($it) {
            $p = $it['price'] ?? [];
            $amount = ((int) ($p['unit_amount'] ?? 0)) * ((int) ($it['quantity'] ?? 1)) / 100;
            return ($p['recurring']['interval'] ?? 'month') === 'year' ? $amount / 12 : $amount;
        }, (array) ($s['items']['data'] ?? [])));
        $r = ['active' => 0, 'trialing' => 0, 'late' => 0, 'canceled30' => 0, 'mrr' => 0.0, 'futureMrr' => 0.0, 'monthlyPlans' => 0, 'yearlyPlans' => 0, 'trialEnds' => []];
        $since = time() - 30 * 86400;
        foreach ($subs as $s) {
            $status = (string) ($s['status'] ?? '');
            $yearly = ($s['items']['data'][0]['price']['recurring']['interval'] ?? '') === 'year';
            if ($status === 'active') {
                $r['active']++;
                $r['mrr'] += $monthly($s);
                $r[$yearly ? 'yearlyPlans' : 'monthlyPlans']++;
            } elseif ($status === 'trialing') {
                $r['trialing']++;
                $r['futureMrr'] += $monthly($s);
                $r[$yearly ? 'yearlyPlans' : 'monthlyPlans']++;
                if (is_numeric($s['trial_end'] ?? null)) {
                    $m = gmdate('Y-m', (int) $s['trial_end']);
                    $r['trialEnds'][$m] = ($r['trialEnds'][$m] ?? 0) + 1;
                }
            } elseif (in_array($status, ['past_due', 'unpaid'], true)) {
                $r['late']++;
            } elseif ($status === 'canceled' && (int) ($s['canceled_at'] ?? 0) >= $since) {
                $r['canceled30']++;
            }
        }
        ksort($r['trialEnds']);
        $paid = fn (int $from) => array_sum(array_map(fn ($inv) => (int) ($inv['amount_paid'] ?? 0), self::stripeAll('/invoices?status=paid&created[gte]=' . $from))) / 100;
        $r['paid30'] = $paid($since);
        $r['paidYear'] = $paid((int) (new \DateTimeImmutable(date('Y') . '-01-01 00:00:00', new \DateTimeZone('Europe/Paris')))->format('U'));
        $r['mrr'] = round($r['mrr'], 2);
        $r['futureMrr'] = round($r['futureMrr'], 2);
        return $r;
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
        // Formule choisie (mois ou an), pour les rappels et le tableau des revenus.
        $price = (string) ($sub['items']['data'][0]['price']['id'] ?? '');
        foreach (self::PLANS as $plan => $k) {
            if ($price !== '' && $price === self::settings()[$k]) {
                Repo::setMeta('plan:' . $row['user_id'], $plan);
            }
        }
        $trialEnd = ($sub['status'] ?? '') === 'trialing' && is_numeric($sub['trial_end'] ?? null) ? gmdate('Y-m-d\TH:i:s\Z', (int) $sub['trial_end']) : null;
        if ($row['status'] === 'gratuit') {
            $status = 'gratuit'; // offert par l'administrateur : on garde
        }
        $applyCredits = empty($row['customer_id']) && !empty($sub['customer']);
        self::update($row['user_id'], ['status' => $status, 'subscription_id' => (string) $sub['id'], 'customer_id' => (string) ($sub['customer'] ?? $row['customer_id']),
            'period_end' => is_numeric($end) ? gmdate('Y-m-d\TH:i:s\Z', (int) $end) : $row['period_end'],
            'trial_ends_at' => $trialEnd ?? ($status === 'actif' ? Db::now() : $row['trial_ends_at'])]);
        Repo::log($row['user_id'], null, null, 'billing_' . $status, ['sub' => substr((string) $sub['id'], -6)]);
        if ($applyCredits || (int) (Repo::meta('refPending:' . $row['user_id']) ?? 0) > 0) {
            Referral::applyPending((string) $row['user_id']);
        }
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
            case 'invoice.paid':
                // Premier vrai paiement (montant > 0) : le parrain éventuel gagne un mois.
                if ((int) ($obj['amount_paid'] ?? 0) > 0 && ($row = Db::one('SELECT user_id FROM sc_billing WHERE customer_id = ?', [(string) ($obj['customer'] ?? '')]))) {
                    Referral::onFirstPayment((string) $row['user_id']);
                }
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
