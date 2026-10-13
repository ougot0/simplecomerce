<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Mailer;

/**
 * Parrainage : chaque compte a un lien personnel. Quand une personne inscrite par ce lien paie son abonnement
 * pour la première fois (après sa période gratuite), le parrain reçoit un mois offert : un avoir du prix d'un mois
 * chez Stripe, déduit automatiquement de son prochain paiement.
 */
final class Referral
{
    public static function code(array $user): string
    {
        $code = Repo::meta('refcode:' . $user['id']);
        if ($code === null) {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            Repo::setMeta('refcode:' . $user['id'], $code);
            Repo::setMeta('ref:' . $code, $user['id']);
        }
        return $code;
    }

    public static function link(array $user): string
    {
        return Config::url('/inscription?parrain=' . self::code($user));
    }

    /** À l'inscription : retient qui a parrainé (jamais soi-même). */
    public static function attach(string $newUserId, string $code): void
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $referrer = $code !== '' ? Repo::meta('ref:' . $code) : null;
        if ($referrer && $referrer !== $newUserId && Repo::user($referrer)) {
            Repo::setMeta('referredBy:' . $newUserId, $referrer);
        }
    }

    /** @return array{count: int, paid: int, months: int} filleuls inscrits, filleuls qui paient, mois gagnés */
    public static function stats(array $user): array
    {
        $count = (int) \SimpleCommerce\Db::value("SELECT COUNT(*) FROM sc_meta WHERE name LIKE 'referredBy:%' AND value = ?", [$user['id']]);
        $months = (int) (Repo::meta('refMonths:' . $user['id']) ?? 0);
        return ['count' => $count, 'paid' => $months, 'months' => $months];
    }

    /** Premier vrai paiement d'un compte : récompense son parrain (une seule fois par filleul). */
    public static function onFirstPayment(string $userId): void
    {
        $referrer = Repo::meta('referredBy:' . $userId);
        if (!$referrer || Repo::meta('refRewarded:' . $userId) !== null) {
            return;
        }
        Repo::setMeta('refRewarded:' . $userId, date('c'));
        Repo::setMeta('refMonths:' . $referrer, (string) ((int) (Repo::meta('refMonths:' . $referrer) ?? 0) + 1));
        Repo::setMeta('refPending:' . $referrer, (string) ((int) (Repo::meta('refPending:' . $referrer) ?? 0) + 1));
        self::applyPending($referrer);
        $u = Repo::user($referrer);
        if ($u) {
            Mailer::send($u['email'], 'Un mois offert grâce à votre parrainage — Simple Commerce',
                "Bonjour,\n\nUne personne que vous avez invitée vient de s'abonner à Simple Commerce : merci !\n"
                . "Un mois d'abonnement vous est offert ; il sera déduit de votre prochain paiement.\n\n"
                . "Votre lien de parrainage, à partager encore : " . self::link($u) . "\n\nSimple Commerce");
        }
        Repo::log($referrer, null, null, 'referral_reward', ['filleul' => $userId]);
    }

    /** Transforme les mois gagnés en avoir chez Stripe dès que le parrain a un compte client Stripe. */
    public static function applyPending(string $userId): void
    {
        $n = (int) (Repo::meta('refPending:' . $userId) ?? 0);
        $row = \SimpleCommerce\Db::one('SELECT customer_id FROM sc_billing WHERE user_id = ?', [$userId]);
        if ($n <= 0 || empty($row['customer_id'])) {
            return;
        }
        $cents = (int) round(((float) Billing::settings()['monthly'] ?: 9) * 100) * $n;
        try {
            Billing::addCredit((string) $row['customer_id'], $cents, "Parrainage Simple Commerce ($n mois offert" . ($n > 1 ? 's' : '') . ')');
            Repo::setMeta('refPending:' . $userId, '0');
        } catch (\Throwable $e) {
            Log::warn('avoir de parrainage non appliqué', ['user' => $userId, 'err' => $e->getMessage()]);
        }
    }
}
