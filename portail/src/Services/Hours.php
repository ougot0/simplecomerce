<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;

/**
 * Horaires d'ouverture (affichés sur le site par une ligne à coller) et fermetures programmées :
 * le site se ferme tout seul le jour prévu et se rouvre tout seul le jour du retour (tâche planifiée).
 * Rangé dans les réglages du site (extras) : hours[7], plannedClosure {from, until, message}, autoReopen (date).
 */
final class Hours
{
    public const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    /** @return string[] 7 lignes, du lundi au dimanche ('' = fermé) */
    public static function week(array $site): array
    {
        $h = (array) (Sites::extras($site)['hours'] ?? []);
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = is_string($h[$i] ?? null) ? $h[$i] : '';
        }
        return $out;
    }

    public static function hasHours(array $site): bool
    {
        return (bool) array_filter(self::week($site), fn ($l) => trim($l) !== '');
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    /** Données publiques : horaires, fermeture prévue. */
    public static function publicData(array $site): array
    {
        $plan = Sites::extras($site)['plannedClosure'] ?? null;
        return [
            'jours' => self::DAYS,
            'horaires' => self::week($site),
            'fermeturePrevue' => is_array($plan) ? ['du' => $plan['from'], 'au' => $plan['until'] ?? null, 'message' => $plan['message'] ?? ''] : null,
        ];
    }

    /** Ferme ou rouvre les sites dont c'est le jour. @return int nombre de sites changés */
    public static function tick(): int
    {
        $today = self::today();
        Billing::$system = true; // ce que le commerçant a programmé se fait, même après son essai
        try {
            return self::tickSites($today);
        } finally {
            Billing::$system = false;
        }
    }

    private static function tickSites(string $today): int
    {
        $done = 0;
        foreach (Repo::sites() as $site) {
            $x = Sites::extras($site);
            $plan = $x['plannedClosure'] ?? null;
            $reopen = (string) ($x['autoReopen'] ?? '');
            if ($site['status'] !== 'active' || (!is_array($plan) && $reopen === '')) {
                continue;
            }
            $viewer = Sites::ownerViewer($site);
            if (!$viewer) {
                continue;
            }
            try {
                if (is_array($plan) && $plan['from'] <= $today) {
                    if (!empty($plan['until']) && $plan['until'] <= $today) {
                        Sites::setExtras($site, ['plannedClosure' => null]); // période déjà finie : rien à faire
                        continue;
                    }
                    $r = Changes::setClosure($viewer, $site, ['closed' => true, 'message' => (string) $plan['message'], 'reopenOn' => $plan['until'] ?: null]);
                    if ($r['ok']) {
                        Sites::setExtras($site, ['plannedClosure' => null, 'autoReopen' => $plan['until'] ?: null]);
                        Repo::log(null, null, $site['id'], 'site_closed_auto', ['until' => $plan['until']]);
                        $done++;
                    }
                } elseif ($reopen !== '' && $reopen <= $today) {
                    $closed = Sites::with($site, fn (Adapter $a) => $a->getStatus());
                    if (is_array($closed) && $closed['closed']) {
                        $r = Changes::setClosure($viewer, $site, ['closed' => false, 'message' => '', 'reopenOn' => null]);
                        if (!$r['ok']) {
                            continue;
                        }
                        Repo::log(null, null, $site['id'], 'site_reopened_auto', []);
                        $done++;
                    }
                    Sites::setExtras($site, ['autoReopen' => null]);
                }
            } catch (\Throwable $e) {
                Log::warn('fermeture programmée impossible', ['site' => $site['slug'], 'err' => $e]);
            }
        }
        return $done;
    }

    /** Petit script public qui affiche les horaires sur le site du client. */
    public static function script(array $site): string
    {
        $data = self::publicData($site);
        $data['today'] = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('N') - 1;
        return '(function(){var D=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
            . file_get_contents(SC_ROOT . '/public/assets/horaires-widget.js') . '})();';
    }
}
