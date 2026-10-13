<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Mailer;
use SimpleCommerce\Support\Text;

/**
 * E-mails automatiques envoyés par la tâche planifiée :
 * - rappel 7 jours avant le premier prélèvement (fin de la période gratuite) ;
 * - résumé du lundi matin pour chaque site (modifications, commandes, visites, avis).
 */
final class Digest
{
    private const MAX_PER_RUN = 25;

    public static function run(): void
    {
        try {
            self::trialReminders();
        } catch (\Throwable $e) {
            Log::error('rappels de fin de gratuité', ['err' => $e]);
        }
        try {
            self::weekly();
        } catch (\Throwable $e) {
            Log::error('résumés du lundi', ['err' => $e]);
        }
    }

    /** Un e-mail 7 jours avant le premier prélèvement, une seule fois par période gratuite. */
    public static function trialReminders(): int
    {
        if (!Billing::enabled()) {
            return 0;
        }
        $now = Db::now();
        $soon = gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400);
        $sent = 0;
        foreach (Db::all("SELECT * FROM sc_billing WHERE status = 'actif' AND subscription_id IS NOT NULL AND trial_ends_at > ? AND trial_ends_at <= ?", [$now, $soon]) as $row) {
            if ($sent >= self::MAX_PER_RUN || Repo::meta('trialReminder:' . $row['user_id']) === $row['trial_ends_at']) {
                continue;
            }
            $user = Repo::user($row['user_id']);
            if (!$user) {
                continue;
            }
            Repo::setMeta('trialReminder:' . $row['user_id'], (string) $row['trial_ends_at']);
            $plan = Billing::plans()[(string) (Repo::meta('plan:' . $user['id']) ?? '')] ?? null;
            Mailer::send($user['email'], 'Votre période gratuite se termine bientôt — Simple Commerce',
                "Bonjour,\n\nVotre période gratuite Simple Commerce se termine le " . Text::date($row['trial_ends_at'], true, true) . '.'
                . "\nCe jour-là, le premier paiement " . ($plan ? '(' . $plan['label'] . ') ' : '') . "sera prélevé sur la carte que vous avez donnée."
                . "\n\nVous voulez continuer ? Vous n'avez rien à faire."
                . "\nVous préférez arrêter ? Avant cette date, ouvrez « Mon compte → Abonnement » :\n" . Config::url('/compte/abonnement')
                . "\n\nMerci de votre confiance,\nSimple Commerce");
            Repo::log($user['id'], null, null, 'trial_reminder_sent');
            $sent++;
        }
        return $sent;
    }

    /** Résumé de la semaine, le lundi à partir de 8 h (heure de Paris), une fois par site. */
    public static function weekly(bool $force = false): int
    {
        $paris = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        if (!$force && ($paris->format('N') !== '1' || (int) $paris->format('G') < 8)) {
            return 0;
        }
        $week = $paris->format('o-\WW');
        $sent = 0;
        foreach (Repo::sites() as $site) {
            if ($sent >= self::MAX_PER_RUN || $site['status'] !== 'active' || $site['connector'] === 'demo' && !Config::demo()) {
                continue;
            }
            if (Repo::meta('weekly:' . $site['id']) === $week || (Sites::extras($site)['weeklyDigest'] ?? true) === false) {
                continue;
            }
            Repo::setMeta('weekly:' . $site['id'], $week);
            $text = self::summary($site);
            if ($text === null) {
                continue; // semaine sans rien : pas d'e-mail
            }
            foreach (Shop::recipients($site) as $email) {
                Mailer::send($email, "Votre semaine sur {$site['name']}", $text);
            }
            $sent++;
        }
        return $sent;
    }

    /** Texte du résumé des 7 derniers jours, ou null s'il ne s'est rien passé. */
    public static function summary(array $site): ?string
    {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
        $sinceDay = (new \DateTimeImmutable('-7 days', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $changes = (int) Db::value("SELECT COUNT(*) FROM sc_changes WHERE site_id = ? AND status = 'applied' AND created_at >= ?", [$site['id'], $since]);
        $orders = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS t FROM sc_orders WHERE site_id = ? AND status <> 'annulee' AND created_at >= ?", [$site['id'], $since]);
        $toDo = (int) Db::value("SELECT COUNT(*) FROM sc_orders WHERE site_id = ? AND status IN ('nouvelle', 'preparee')", [$site['id']]);
        $reviews = (int) Db::value('SELECT COUNT(*) FROM sc_reviews WHERE site_id = ? AND created_at >= ?', [$site['id'], $since]);
        $toCheck = (int) Db::value("SELECT COUNT(*) FROM sc_reviews WHERE site_id = ? AND status = 'attente'", [$site['id']]);
        $visits = (int) Db::value("SELECT COALESCE(SUM(hits), 0) FROM sc_hits WHERE site_id = ? AND kind = 'visit' AND day >= ?", [$site['id'], $sinceDay]);
        $waiting = (int) Db::value('SELECT COUNT(*) FROM sc_waitlist WHERE site_id = ? AND notified_at IS NULL', [$site['id']]);
        $bookings = 0;
        try {
            $bookings = (int) Db::value("SELECT COUNT(*) FROM sc_bookings WHERE site_id = ? AND status = 'confirme' AND starts_at >= ? AND starts_at < ?", [$site['id'], Db::now(), gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400)]);
        } catch (\Throwable) {
        }
        $lines = [];
        if (!empty(Sites::extras($site)['stats'])) {
            $lines[] = '- ' . Text::plural($visits, 'visite') . ' sur votre site';
        }
        if ((int) $orders['n'] > 0 || $toDo > 0) {
            $lines[] = '- ' . Text::plural((int) $orders['n'], 'commande') . ' (' . Text::price((float) $orders['t']) . ')' . ($toDo ? ', dont ' . $toDo . ' à préparer' : '');
        }
        if ($bookings > 0) {
            $lines[] = '- ' . Text::plural($bookings, 'rendez-vous') . ' prévus cette semaine';
        }
        if ($reviews > 0 || $toCheck > 0) {
            $lines[] = '- ' . Text::plural($reviews, 'nouvel avis', 'nouveaux avis') . ($toCheck ? ", $toCheck à relire" : '');
        }
        if ($waiting > 0) {
            $lines[] = '- ' . Text::plural($waiting, 'personne') . ($waiting > 1 ? ' attendent' : ' attend') . ' le retour d\'un produit épuisé';
        }
        $lines[] = '- ' . ($changes ? Text::plural($changes, 'modification publiée', 'modifications publiées') : 'Aucune modification publiée : pensez à mettre votre site à jour (nouveautés, horaires, photos).');
        if (!$changes && count($lines) === 1 && !$visits) {
            return null;
        }
        return "Bonjour,\n\nVoici votre semaine sur « {$site['name']} » :\n\n" . implode("\n", $lines)
            . "\n\nPour tout voir : " . Config::url('/s/' . $site['slug'])
            . "\n\n(Vous pouvez arrêter ce résumé dans Réglages → Boutique.)\n\nBonne semaine !\nSimple Commerce";
    }
}
