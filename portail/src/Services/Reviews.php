<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;

/**
 * Avis des clients : laissés sur le site (ligne à coller), relus par le commerçant avant d'être affichés.
 * États : attente → publie ou refuse. Le commerçant peut répondre publiquement.
 */
final class Reviews
{
    public const STATUSES = ['attente' => 'À relire', 'publie' => 'Affiché', 'refuse' => 'Refusé'];

    public static function add(array $site, string $author, int $rating, string $body): void
    {
        Db::insert('sc_reviews', ['id' => Db::uuid(), 'site_id' => $site['id'], 'product_id' => null, 'author' => mb_substr($author, 0, 80),
            'rating' => max(1, min(5, $rating)), 'body' => mb_substr($body, 0, 1500), 'status' => 'attente', 'reply' => null, 'created_at' => Db::now()]);
        Repo::log(null, null, $site['id'], 'review_received', ['rating' => $rating]);
        foreach (Shop::recipients($site) as $email) {
            $bucket = 'review-mail:' . $site['id'] . ':' . md5($email);
            if (Repo::tooManyAttempts($bucket, 1, 1800)) {
                continue; // au plus un e-mail par demi-heure, même si plusieurs avis arrivent
            }
            Repo::recordAttempt($bucket);
            Mailer::send($email, "Nouvel avis sur {$site['name']} (" . str_repeat('★', max(1, min(5, $rating))) . ')',
                "Bonjour,\n\n$author a laissé un avis :\n\n« " . mb_substr($body, 0, 600) . " »\n\nIl n'est pas encore affiché sur votre site. Pour le lire, l'afficher ou y répondre :\n"
                . Config::url('/s/' . $site['slug'] . '/avis'));
        }
    }

    public static function list(string $siteId, ?string $status = null): array
    {
        return $status
            ? Db::all('SELECT * FROM sc_reviews WHERE site_id = ? AND status = ? ORDER BY created_at DESC LIMIT 300', [$siteId, $status])
            : Db::all('SELECT * FROM sc_reviews WHERE site_id = ? ORDER BY created_at DESC LIMIT 300', [$siteId]);
    }

    public static function counts(string $siteId): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM sc_reviews WHERE site_id = ? GROUP BY status', [$siteId]) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function update(string $siteId, string $id, array $patch): bool
    {
        $r = Db::one('SELECT id FROM sc_reviews WHERE id = ? AND site_id = ?', [$id, $siteId]);
        if (!$r) {
            return false;
        }
        if (isset($patch['status']) && isset(self::STATUSES[$patch['status']])) {
            Db::run('UPDATE sc_reviews SET status = ? WHERE id = ?', [$patch['status'], $id]);
        }
        if (array_key_exists('reply', $patch)) {
            $reply = trim((string) $patch['reply']);
            Db::run('UPDATE sc_reviews SET reply = ? WHERE id = ?', [$reply === '' ? null : mb_substr($reply, 0, 1000), $id]);
        }
        return true;
    }

    public static function delete(string $siteId, string $id): void
    {
        Db::run('DELETE FROM sc_reviews WHERE id = ? AND site_id = ?', [$id, $siteId]);
    }

    /** Ce que voit le public : avis affichés, moyenne. */
    public static function publicData(array $site): array
    {
        $rows = Db::all("SELECT author, rating, body, reply, created_at FROM sc_reviews WHERE site_id = ? AND status = 'publie' ORDER BY created_at DESC LIMIT 60", [$site['id']]);
        $avg = $rows ? round(array_sum(array_column($rows, 'rating')) / count($rows), 1) : null;
        return [
            'nom' => $site['name'],
            'moyenne' => $avg,
            'nombre' => count($rows),
            'avis' => array_map(fn ($r) => ['auteur' => $r['author'], 'note' => (int) $r['rating'], 'texte' => $r['body'],
                'reponse' => (string) ($r['reply'] ?? ''), 'date' => substr($r['created_at'], 0, 10)], $rows),
        ];
    }
}
