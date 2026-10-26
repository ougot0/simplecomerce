<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Adapter;
use SimpleCommerce\Config;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Support\Text;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Mailer;

/**
 * Demandes de commande passées depuis le catalogue hébergé (panier sur le site du client).
 * Pas de paiement en ligne : le commerçant reçoit la commande, recontacte le client, puis la suit ici
 * (nouvelle → préparée → remise / envoyée, ou annulée). Le stock est retiré à la commande et remis si elle est annulée.
 */
final class Orders
{
    public const STATUSES = ['nouvelle' => 'Nouvelle', 'preparee' => 'Préparée', 'envoyee' => 'Remise ou envoyée', 'annulee' => 'Annulée'];
    public const MAX_LINES = 30;
    public const MAX_PER_DAY = 40;

    public static function enabled(array $site): bool
    {
        return $site['connector'] === 'catalogue' && !empty(Sites::extras($site)['orders']);
    }

    public static function products(array $site): ?array
    {
        $schema = Repo::schema($site['id']) ?? ['sections' => []];
        return ContentSchema::find($schema, 'produits');
    }

    /** @return array<string, array> produits du catalogue par identifiant */
    private static function catalogue(array $site, array $section): array
    {
        $out = [];
        foreach (Sites::with($site, fn (Adapter $a) => $a->listEntries($section)) as $e) {
            $out[(string) $e['id']] = $e['data'];
        }
        return $out;
    }

    /**
     * Enregistre une commande venue du site. Les prix et le stock sont relus ici (jamais pris du navigateur).
     * @param array<int, array{id: string, qte: int}> $lines
     * @return array{ok: bool, error?: string, number?: int}
     */
    public static function create(array $site, array $customer, array $lines): array
    {
        $section = self::products($site);
        if (!$section || !self::enabled($site)) {
            return ['ok' => false, 'error' => 'Les commandes ne sont pas ouvertes sur ce site.'];
        }
        // Au plus MAX_PER_DAY commandes par boutique et par jour (fausses commandes qui videraient le stock).
        if ((int) Db::value('SELECT COUNT(*) FROM sc_orders WHERE site_id = ? AND created_at >= ?', [$site['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]) >= self::MAX_PER_DAY) {
            return ['ok' => false, 'error' => 'La boutique reçoit beaucoup de commandes en ce moment. Contactez-la directement.'];
        }
        // Un même produit avec les mêmes choix en plusieurs lignes compte pour une seule ligne ; le stock, lui, est
        // contrôlé par produit, toutes lignes confondues (sinon il serait contourné).
        $wanted = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $l) {
            $id = (string) ($l['id'] ?? '');
            $q = (int) ($l['qte'] ?? 0);
            $picked = is_array($l['choix'] ?? null) ? array_slice($l['choix'], 0, ProductOptions::MAX_GROUPS, true) : [];
            if ($id !== '' && $q > 0) {
                $k = $id . '|' . json_encode($picked);
                $wanted[$k] = ['id' => $id, 'choix' => $picked, 'qte' => min(99, ($wanted[$k]['qte'] ?? 0) + $q)];
            }
        }
        // Les commandes d'une même boutique passent l'une après l'autre : le stock relu ici est le vrai,
        // deux clients ne peuvent pas prendre le dernier exemplaire en même temps, et chaque numéro est unique.
        $lock = @fopen(SC_STORAGE . '/commande-' . preg_replace('/[^a-f0-9-]/', '', $site['id']) . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            return self::createLocked($site, $section, $customer, $wanted);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param array<string, array{id: string, choix: array, qte: int}> $wanted */
    private static function createLocked(array $site, array $section, array $customer, array $wanted): array
    {
        $products = self::catalogue($site, $section);
        $items = [];
        $total = 0.0;
        $stock = [];
        $perProduct = [];
        foreach ($wanted as $w) {
            $id = $w['id'];
            $qty = $w['qte'];
            $p = $products[$id] ?? null;
            if (!$p || $qty < 1 || $qty > 99 || (array_key_exists('disponible', $p) && $p['disponible'] === false)) {
                continue;
            }
            $name = (string) ($p['nom'] ?? 'Produit');
            // Prix et choix recalculés depuis le catalogue : rien n'est repris tel quel du navigateur du client.
            $r = ProductOptions::resolve($p, $w['choix']);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => $r['error']];
            }
            $perProduct[$id] = ($perProduct[$id] ?? 0) + $qty;
            if (isset($p['stock']) && is_numeric($p['stock'])) {
                if ((int) $p['stock'] < $perProduct[$id]) {
                    return ['ok' => false, 'error' => (int) $p['stock'] <= 0 ? "« $name » vient d'être épuisé." : "Il ne reste que {$p['stock']} « $name »."];
                }
                $stock[$id] = ['stock' => (int) $p['stock'] - $perProduct[$id]];
            }
            $price = $r['prix'];
            $items[] = ['id' => $id, 'nom' => $r['detail'] !== '' ? "$name ({$r['detail']})" : $name, 'qte' => $qty, 'prix' => $price]
                + ($r['detail'] !== '' ? ['choix' => $r['choix']] : []);
            $total += ($price ?? 0) * $qty;
        }
        if (!$items) {
            return ['ok' => false, 'error' => 'Votre panier est vide.'];
        }
        $number = (int) Db::value('SELECT COALESCE(MAX(number), 0) FROM sc_orders WHERE site_id = ?', [$site['id']]) + 1;
        $viewer = Sites::ownerViewer($site);
        if ($stock && $viewer) {
            Billing::$system = true; // la commande d'un client n'est jamais bloquée
            try {
                $r = Changes::publishBatch($viewer, $site, $section, $stock, "Commande n° $number", false);
            } finally {
                Billing::$system = false;
            }
            if (!$r['ok']) {
                return ['ok' => false, 'error' => "La commande n'a pas pu être enregistrée. Réessayez dans un instant."];
            }
        }
        $order = ['id' => Db::uuid(), 'site_id' => $site['id'], 'number' => $number, 'status' => 'nouvelle',
            'customer_name' => $customer['nom'], 'email' => $customer['email'], 'phone' => $customer['telephone'],
            'address' => $customer['adresse'], 'note' => $customer['note'], 'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'total' => $total, 'created_at' => Db::now(), 'updated_at' => Db::now()];
        Db::insert('sc_orders', $order);
        Repo::log(null, null, $site['id'], 'order_received', ['number' => $number]);
        self::mailShop($site, $order, $items);
        self::mailCustomer($site, $order, $items);
        return ['ok' => true, 'number' => $number];
    }

    public static function lines(array $items): string
    {
        return implode("\n", array_map(fn ($i) => "- {$i['qte']} × {$i['nom']}" . ($i['prix'] !== null ? ' — ' . Text::price($i['prix'] * $i['qte']) : ''), $items));
    }

    private static function mailShop(array $site, array $o, array $items): void
    {
        $text = "Bonjour,\n\nNouvelle commande n° {$o['number']} sur {$site['name']} :\n\n" . self::lines($items)
            . "\n\nTotal : " . Text::price((float) $o['total']) . "\n\nClient : {$o['customer_name']}\nE-mail : {$o['email']}"
            . ($o['phone'] ? "\nTéléphone : {$o['phone']}" : '') . ($o['address'] ? "\nAdresse : {$o['address']}" : '') . ($o['note'] ? "\nMessage : {$o['note']}" : '')
            . "\n\nPour la suivre : " . Config::url('/s/' . $site['slug'] . '/commandes');
        foreach (Shop::recipients($site) as $email) {
            Mailer::send($email, "Nouvelle commande n° {$o['number']} — {$o['customer_name']}", $text, $o['email']);
        }
    }

    private static function mailCustomer(array $site, array $o, array $items): void
    {
        $note = trim((string) (Sites::extras($site)['orderNote'] ?? ''));
        Mailer::send($o['email'], "Votre commande n° {$o['number']} chez {$site['name']}",
            "Bonjour,\n\nNous avons bien reçu votre commande :\n\n" . self::lines($items)
            . "\n\nTotal : " . Text::price((float) $o['total']) . ($note !== '' ? "\n\n$note" : '')
            . "\n\nNous revenons vers vous très vite.\n\n{$site['name']}");
    }

    /** @return array<int, array> */
    public static function list(string $siteId, ?string $status = null, int $limit = 200): array
    {
        $rows = $status
            ? Db::all('SELECT * FROM sc_orders WHERE site_id = ? AND status = ? ORDER BY number DESC LIMIT ' . $limit, [$siteId, $status])
            : Db::all('SELECT * FROM sc_orders WHERE site_id = ? ORDER BY number DESC LIMIT ' . $limit, [$siteId]);
        foreach ($rows as &$r) {
            $r['items'] = json_decode((string) $r['items'], true) ?: [];
        }
        return $rows;
    }

    public static function counts(string $siteId): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM sc_orders WHERE site_id = ? GROUP BY status', [$siteId]) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** Change l'état d'une commande. Annuler remet le stock ; la rouvrir le retire de nouveau. */
    public static function setStatus(array $viewer, array $site, string $id, string $status): array
    {
        $o = Db::one('SELECT * FROM sc_orders WHERE id = ? AND site_id = ?', [$id, $site['id']]);
        if (!$o || !isset(self::STATUSES[$status])) {
            return ['ok' => false, 'error' => 'Commande introuvable.'];
        }
        if ($o['status'] === $status) {
            return ['ok' => true];
        }
        $wasCancelled = $o['status'] === 'annulee';
        $isCancelled = $status === 'annulee';
        if ($wasCancelled !== $isCancelled && ($section = self::products($site))) {
            try {
                $products = self::catalogue($site, $section);
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Le catalogue est illisible pour le moment.'];
            }
            $patch = [];
            foreach (json_decode((string) $o['items'], true) ?: [] as $i) {
                $p = $products[$i['id']] ?? null;
                if ($p && isset($p['stock']) && is_numeric($p['stock'])) {
                    $cur = $patch[$i['id']]['stock'] ?? (int) $p['stock'];
                    $patch[$i['id']] = ['stock' => max(0, $cur + ($isCancelled ? $i['qte'] : -$i['qte']))];
                }
            }
            if ($patch) {
                $r = Changes::publishBatch($viewer, $site, $section, $patch, ($isCancelled ? 'Commande annulée' : 'Commande reprise') . " n° {$o['number']}", false);
                if (!$r['ok']) {
                    return $r;
                }
            }
        }
        Db::run('UPDATE sc_orders SET status = ?, updated_at = ? WHERE id = ?', [$status, Db::now(), $id]);
        Access::audit($viewer, 'order_status', ['number' => $o['number'], 'status' => $status], $site['id']);
        return ['ok' => true];
    }

    /**
     * Numéro de facture : attribué la première fois que la facture est affichée, puis toujours le même,
     * à la suite pour chaque boutique (AAAA-0001, AAAA-0002…), comme l'exige la loi (numérotation continue).
     */
    public static function invoiceNumber(array $site, array $order): string
    {
        $key = 'invoice:' . $order['id'];
        $existing = Repo::meta($key);
        if ($existing !== null) {
            return $existing;
        }
        $lock = @fopen(SC_STORAGE . '/facture-' . preg_replace('/[^a-f0-9-]/', '', $site['id']) . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $existing = Repo::meta($key);
            if ($existing !== null) {
                return $existing;
            }
            $year = date('Y');
            $seq = (int) (Repo::meta('invoiceSeq:' . $site['id'] . ':' . $year) ?? 0) + 1;
            Repo::setMeta('invoiceSeq:' . $site['id'] . ':' . $year, (string) $seq);
            $number = $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            Repo::setMeta($key, $number);
            Repo::setMeta('invoiceDate:' . $order['id'], date('Y-m-d'));
            return $number;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Commandes en CSV (pour un tableur). */
    public static function csv(string $siteId): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Numéro', 'Date', 'État', 'Client', 'E-mail', 'Téléphone', 'Adresse', 'Message', 'Produits', 'Total'], ';');
        // Une cellule qui commence par = + - @ serait lue comme une formule par le tableur : on la neutralise.
        $cell = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        foreach (self::list($siteId, null, 5000) as $o) {
            fputcsv($out, array_map($cell, [$o['number'], substr($o['created_at'], 0, 10), self::STATUSES[$o['status']] ?? $o['status'], $o['customer_name'], $o['email'], $o['phone'],
                $o['address'], $o['note'], implode(', ', array_map(fn ($i) => "{$i['qte']} × {$i['nom']}", $o['items'])), number_format((float) $o['total'], 2, ',', '')]), ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
