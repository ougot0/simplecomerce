<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;

/** Liste d'attente « Prévenez-moi quand il revient » : un e-mail automatique au retour en stock. */
final class Waitlist
{
    public static function add(array $site, string $productId, string $productName, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $productId === '') {
            return false;
        }
        $exists = Db::value('SELECT 1 FROM sc_waitlist WHERE site_id = ? AND product_id = ? AND email = ? AND notified_at IS NULL', [$site['id'], $productId, $email]);
        // Au plus 300 personnes en attente par produit (une liste remplie d'adresses au hasard ne grossit pas sans fin).
        if (!$exists && (int) Db::value('SELECT COUNT(*) FROM sc_waitlist WHERE site_id = ? AND product_id = ? AND notified_at IS NULL', [$site['id'], $productId]) >= 300) {
            return true;
        }
        if (!$exists) {
            Db::insert('sc_waitlist', ['id' => Db::uuid(), 'site_id' => $site['id'], 'product_id' => mb_substr($productId, 0, 191),
                'product_name' => mb_substr($productName, 0, 250), 'email' => $email, 'created_at' => Db::now()]);
        }
        return true;
    }

    /** Personnes en attente, regroupées par produit. @return array<string, array{name: string, emails: string[]}> */
    public static function pending(string $siteId): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM sc_waitlist WHERE site_id = ? AND notified_at IS NULL ORDER BY created_at', [$siteId]) as $r) {
            $out[$r['product_id']] ??= ['name' => $r['product_name'], 'emails' => []];
            $out[$r['product_id']]['emails'][] = $r['email'];
        }
        return $out;
    }

    public static function backInStock(array $site, string $productId, string $name): int
    {
        $rows = Db::all('SELECT * FROM sc_waitlist WHERE site_id = ? AND product_id = ? AND notified_at IS NULL', [$site['id'], $productId]);
        foreach ($rows as $r) {
            Mailer::send($r['email'], "« $name » est de retour chez {$site['name']}",
                "Bonjour,\n\nVous nous aviez demandé de vous prévenir : « $name » est de nouveau disponible.\n\n"
                . ($site['public_url'] ? "Voir la boutique : {$site['public_url']}\n\n" : '') . "À bientôt,\n{$site['name']}");
            Db::run('UPDATE sc_waitlist SET notified_at = ? WHERE id = ?', [Db::now(), $r['id']]);
        }
        if ($rows) {
            Repo::log(null, null, $site['id'], 'waitlist_notified', ['name' => $name, 'count' => count($rows)]);
        }
        return count($rows);
    }
}
