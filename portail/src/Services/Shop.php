<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;

/**
 * Aides pour la boutique : stock bas, retour en stock (liste d'attente).
 * Appelé après chaque modification publiée et après chaque commande.
 */
final class Shop
{
    public const DEFAULT_THRESHOLD = 3;
    private const STOCK_KEY = '/^(stock|stocks|quantite|quantites|quantity|qty|inventory|inventaire|enstock|instock|nbstock)$/i';

    /** Champ « stock » d'une rubrique (nombre), ou null. */
    public static function stockField(array $section): ?array
    {
        foreach ($section['fields'] ?? [] as $f) {
            if (in_array($f['type'], ['number', 'text'], true) && empty($f['hidden'])
                && (preg_match(self::STOCK_KEY, str_replace(['_', '-'], '', (string) $f['key'])) || preg_match('/^stock$/i', (string) $f['label']))) {
                return $f;
            }
        }
        return null;
    }

    public static function stockOf(array $section, ?array $data): ?int
    {
        $f = self::stockField($section);
        if (!$f || !$data || !isset($data[$f['key']]) || $data[$f['key']] === '' || !is_numeric($data[$f['key']])) {
            return null;
        }
        return (int) $data[$f['key']];
    }

    /** Seuil d'alerte du site (0 = pas d'alerte). */
    public static function threshold(array $site): int
    {
        $t = Sites::extras($site)['stockAlert'] ?? self::DEFAULT_THRESHOLD;
        return max(0, (int) $t);
    }

    /** Pastille de stock pour les listes du portail, ou ''. */
    public static function stockTag(array $site, array $section, array $data): string
    {
        $n = self::stockOf($section, $data);
        if ($n === null) {
            return '';
        }
        if ($n <= 0) {
            return tag('error', 'Épuisé');
        }
        return $n <= self::threshold($site) ? tag('draft', 'Plus que ' . $n) : '';
    }

    /** Après une modification publiée : alerte stock bas, et « de retour » pour la liste d'attente. */
    public static function afterChange(array $site, array $section, ?array $before, ?array $after, ?string $entryId): void
    {
        if (($section['kind'] ?? '') !== 'collection' || !$after) {
            return;
        }
        $old = self::stockOf($section, $before);
        $new = self::stockOf($section, $after);
        if ($new === null) {
            return;
        }
        $name = ContentSchema::title($section, $after);
        $limit = self::threshold($site);
        if ($limit > 0 && $new <= $limit && ($old === null || $old > $limit || ($new <= 0 && $old > 0))) {
            self::alertLowStock($site, $section, $name, $new);
        }
        if ($entryId !== null && $new > 0 && $old !== null && $old <= 0) {
            Waitlist::backInStock($site, $entryId, $name);
        }
    }

    private static function alertLowStock(array $site, array $section, string $name, int $left): void
    {
        $subject = $left <= 0 ? "{$site['name']} : « $name » est épuisé" : "{$site['name']} : plus que $left « $name »";
        $text = ($left <= 0 ? "Le produit « $name » n'a plus de stock." : "Il ne reste que $left « $name » en stock.")
            . "\n\nPour mettre à jour le stock :\n" . Config::url('/s/' . $site['slug'] . '/r/' . rawurlencode($section['key']))
            . "\n\nVous pouvez changer le seuil d'alerte (ou couper ces messages) dans Réglages → Boutique.";
        foreach (self::recipients($site) as $email) {
            $bucket = 'stock:' . $site['id'] . ':' . md5($name . $email . ($left <= 0 ? ':epuise' : ':bas'));
            if (Repo::tooManyAttempts($bucket, 1, 6 * 3600)) {
                continue;
            }
            Repo::recordAttempt($bucket);
            Mailer::send($email, $subject, "Bonjour,\n\n$text");
        }
        Repo::log(null, null, $site['id'], 'stock_low', ['name' => $name, 'left' => $left]);
    }

    /** Adresses des personnes du site qui reçoivent les avis (propriétaires d'abord). @return string[] */
    public static function recipients(array $site): array
    {
        $out = [];
        foreach (Repo::members($site['id']) as $m) {
            $u = $m['user'] ?? null;
            // Les propriétaires reçoivent toujours ces alertes ; les autres membres seulement s'ils acceptent les avis.
            if ($u && ($m['role'] === 'owner' || $u['notify'])) {
                $out[$u['email']] = true;
            }
        }
        return array_keys($out);
    }

    /** Petit résumé « boutique » pour la page d'accueil du site. */
    public static function summary(array $site): array
    {
        return [
            'ordersToDo' => (int) Db::value("SELECT COUNT(*) FROM sc_orders WHERE site_id = ? AND status IN ('nouvelle', 'preparee')", [$site['id']]),
            'reviewsToCheck' => (int) Db::value("SELECT COUNT(*) FROM sc_reviews WHERE site_id = ? AND status = 'attente'", [$site['id']]),
            'waiting' => (int) Db::value('SELECT COUNT(*) FROM sc_waitlist WHERE site_id = ? AND notified_at IS NULL', [$site['id']]),
        ];
    }
}
