<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;
use SimpleCommerce\Support\Text;

/**
 * Prise de rendez-vous : le commerçant décrit ses prestations et ses créneaux (ou reprend ses horaires d'ouverture),
 * ses visiteurs réservent depuis son site (ligne à coller /rdv/{clé}.js). Une seule personne reçoit à la fois
 * (ou « capacité » personnes). Heure de Paris partout.
 * Réglages dans extras['booking'] : enabled, services [{nom, duree, prix}], week [7 textes], notice (heures), days, capacity.
 */
final class Bookings
{
    public const TZ = 'Europe/Paris';

    public static function settings(array $site): array
    {
        $b = (array) (Sites::extras($site)['booking'] ?? []);
        return $b + ['enabled' => false, 'services' => [], 'week' => array_fill(0, 7, ''), 'notice' => 2, 'days' => 30, 'capacity' => 1, 'message' => '', 'tab' => null];
    }

    /** Créneaux d'un jour : ceux des réglages de rendez-vous, sinon les horaires d'ouverture. @return array<int, array{0: int, 1: int}> minutes depuis minuit */
    public static function ranges(array $site, int $weekday): array
    {
        $text = trim((string) (self::settings($site)['week'][$weekday] ?? ''));
        if ($text === '') {
            $text = trim(Hours::week($site)[$weekday] ?? '');
        }
        return self::parseRanges($text);
    }

    /** « 9h-12h, 14h-18h30 », « 9:00 – 12:00 », « 8 h – 19 h » → [[540, 720], [840, 1110]] */
    public static function parseRanges(string $text): array
    {
        $out = [];
        if (preg_match_all('/(\d{1,2})\s*(?:[h:]\s*(\d{2})?)?\s*(?:-|–|—|à|a)\s*(\d{1,2})\s*(?:[h:]\s*(\d{2})?)?/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $a = (int) $r[1] * 60 + (int) ($r[2] ?? 0);
                $b = (int) $r[3] * 60 + (int) ($r[4] ?? 0);
                if ($a < $b && $b <= 24 * 60) {
                    $out[] = [$a, $b];
                }
            }
        }
        return $out;
    }

    /** Le site est-il fermé ce jour-là (congés en cours ou prévus) ? */
    private static function closedOn(array $site, string $day): bool
    {
        $x = Sites::extras($site);
        $plan = $x['plannedClosure'] ?? null;
        if (is_array($plan) && $day >= $plan['from'] && (empty($plan['until']) || $day < $plan['until'])) {
            return true;
        }
        $reopen = (string) ($x['autoReopen'] ?? '');
        return $reopen !== '' && $day < $reopen;
    }

    /** Créneaux libres pour une prestation un jour donné. @return string[] heures « 09:30 » */
    public static function slots(array $site, int $serviceIndex, string $day): array
    {
        $s = self::settings($site);
        $service = $s['services'][$serviceIndex] ?? null;
        if (!$s['enabled'] || !$service || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || self::closedOn($site, $day)) {
            return [];
        }
        $tz = new \DateTimeZone(self::TZ);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $tz);
        $today = new \DateTimeImmutable('today', $tz);
        if (!$date || $date < $today || $date > $today->modify('+' . (int) $s['days'] . ' days')) {
            return [];
        }
        $duration = max(5, (int) $service['duree']);
        $step = $duration % 15 === 0 ? 15 : ($duration % 10 === 0 ? 10 : 5);
        $earliest = time() + (int) $s['notice'] * 3600;
        $taken = Db::all("SELECT starts_at, ends_at FROM sc_bookings WHERE site_id = ? AND status = 'confirme' AND starts_at < ? AND ends_at > ?",
            [$site['id'], gmdate('Y-m-d\TH:i:s\Z', $date->modify('+1 day')->getTimestamp()), gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp())]);
        $out = [];
        foreach (self::ranges($site, (int) $date->format('N') - 1) as [$a, $b]) {
            for ($t = $a; $t + $duration <= $b; $t += $step) {
                $start = $date->setTime(intdiv($t, 60), $t % 60)->getTimestamp();
                $end = $start + $duration * 60;
                if ($start < $earliest) {
                    continue;
                }
                $overlap = 0;
                foreach ($taken as $k) {
                    if (strtotime($k['starts_at']) < $end && strtotime($k['ends_at']) > $start) {
                        $overlap++;
                    }
                }
                if ($overlap < max(1, (int) $s['capacity'])) {
                    $out[] = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                }
            }
        }
        return $out;
    }

    /** Jours des prochaines semaines où il reste au moins un créneau. @return string[] */
    public static function openDays(array $site, int $serviceIndex, int $max = 21): array
    {
        $s = self::settings($site);
        $tz = new \DateTimeZone(self::TZ);
        $out = [];
        for ($i = 0, $d = new \DateTimeImmutable('today', $tz); $i <= (int) $s['days'] && count($out) < $max; $i++, $d = $d->modify('+1 day')) {
            if (self::slots($site, $serviceIndex, $d->format('Y-m-d'))) {
                $out[] = $d->format('Y-m-d');
            }
        }
        return $out;
    }

    /**
     * Réserve un créneau (revérifié sous verrou : deux personnes ne peuvent pas prendre le même).
     * @return array{ok: bool, error?: string, when?: string}
     */
    public static function book(array $site, int $serviceIndex, string $day, string $time, array $c): array
    {
        $service = self::settings($site)['services'][$serviceIndex] ?? null;
        if (!$service) {
            return ['ok' => false, 'error' => 'Prestation introuvable.'];
        }
        $lock = @fopen(SC_STORAGE . '/rdv-' . preg_replace('/[^a-f0-9-]/', '', $site['id']) . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            if (!in_array($time, self::slots($site, $serviceIndex, $day), true)) {
                return ['ok' => false, 'error' => 'Ce créneau vient d\'être pris. Choisissez-en un autre.'];
            }
            if ((int) Db::value("SELECT COUNT(*) FROM sc_bookings WHERE site_id = ? AND email = ? AND status = 'confirme' AND starts_at > ?", [$site['id'], $c['email'], Db::now()]) >= 5) {
                return ['ok' => false, 'error' => 'Vous avez déjà plusieurs rendez-vous à venir. Contactez directement l\'établissement.'];
            }
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$day $time", new \DateTimeZone(self::TZ));
            $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $b = ['id' => Db::uuid(), 'site_id' => $site['id'], 'service' => mb_substr((string) $service['nom'], 0, 160),
                'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $start->getTimestamp()), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $start->getTimestamp() + (int) $service['duree'] * 60),
                'customer_name' => $c['nom'], 'email' => $c['email'], 'phone' => $c['telephone'], 'note' => $c['note'],
                'status' => 'confirme', 'token_hash' => hash('sha256', $token), 'created_at' => Db::now()];
            Db::insert('sc_bookings', $b);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $when = self::when($b['starts_at']);
        $cancel = Config::url('/rdv/annuler/' . $token);
        Mailer::send($c['email'], "Votre rendez-vous chez {$site['name']} : $when",
            "Bonjour,\n\nVotre rendez-vous est confirmé :\n\n{$b['service']}\n$when\n" . (self::settings($site)['message'] ? "\n" . self::settings($site)['message'] . "\n" : '')
            . "\nUn empêchement ? Annulez ici :\n$cancel\n\nÀ bientôt,\n{$site['name']}");
        foreach (Shop::recipients($site) as $email) {
            Mailer::send($email, "Nouveau rendez-vous : {$b['service']}, $when",
                "Bonjour,\n\nNouveau rendez-vous sur {$site['name']} :\n\n{$b['service']}\n$when\n\n{$c['nom']}\n{$c['email']}" . ($c['telephone'] ? "\n{$c['telephone']}" : '')
                . ($c['note'] ? "\n\nMessage : {$c['note']}" : '') . "\n\nTous vos rendez-vous : " . Config::url('/s/' . $site['slug'] . '/rendez-vous'), $c['email']);
        }
        Repo::log(null, null, $site['id'], 'booking_created', ['when' => $b['starts_at']]);
        return ['ok' => true, 'when' => $when];
    }

    public static function when(string $iso): string
    {
        $d = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(self::TZ));
        return Text::date($d->format('Y-m-d'), true, true) . ' à ' . $d->format('H') . ' h ' . $d->format('i');
    }

    public static function byToken(string $token): ?array
    {
        return Db::one('SELECT * FROM sc_bookings WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    /** Annule un rendez-vous ; prévient l'autre partie. */
    public static function cancel(array $b, bool $byCustomer): void
    {
        if ($b['status'] !== 'confirme') {
            return;
        }
        Db::run("UPDATE sc_bookings SET status = 'annule' WHERE id = ?", [$b['id']]);
        $site = Repo::site($b['site_id']);
        if (!$site) {
            return;
        }
        $when = self::when($b['starts_at']);
        if ($byCustomer) {
            foreach (Shop::recipients($site) as $email) {
                Mailer::send($email, "Rendez-vous annulé : {$b['service']}, $when", "Bonjour,\n\n{$b['customer_name']} a annulé son rendez-vous du $when ({$b['service']}). Le créneau est de nouveau libre.\n\nSimple Commerce");
            }
        } else {
            Mailer::send($b['email'], "Rendez-vous annulé chez {$site['name']}", "Bonjour,\n\nVotre rendez-vous du $when ({$b['service']}) a été annulé par {$site['name']}. Contactez-les pour en fixer un autre.\n\n{$site['name']}");
        }
        Repo::log(null, null, $site['id'], 'booking_canceled', ['by' => $byCustomer ? 'client' : 'commerce']);
    }

    /** @return array{upcoming: array, past: array} */
    public static function list(string $siteId): array
    {
        $now = Db::now();
        return [
            'upcoming' => Db::all("SELECT * FROM sc_bookings WHERE site_id = ? AND starts_at >= ? AND status = 'confirme' ORDER BY starts_at LIMIT 300", [$siteId, $now]),
            'past' => Db::all("SELECT * FROM sc_bookings WHERE site_id = ? AND (starts_at < ? OR status <> 'confirme') ORDER BY starts_at DESC LIMIT 50", [$siteId, $now]),
        ];
    }

    /** Données publiques pour le petit script du site. */
    public static function publicData(array $site): array
    {
        $s = self::settings($site);
        return ['nom' => $site['name'], 'services' => array_map(fn ($sv) => ['nom' => (string) $sv['nom'], 'duree' => (int) $sv['duree'], 'prix' => (string) ($sv['prix'] ?? '')], $s['services'])];
    }
}
