<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Config;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Orders;
use SimpleCommerce\Services\Reviews;
use SimpleCommerce\Services\Sites;
use SimpleCommerce\Services\Stats;
use SimpleCommerce\Services\Waitlist;

/**
 * Adresses publiques appelées par les sites des clients (sans session ni cookie) :
 * « Prévenez-moi », commandes, avis, statistiques. Les envois arrivent en texte (pas de pré-requête CORS),
 * sont limités par adresse IP et piégés par un champ caché que seuls les robots remplissent.
 */
final class PublicController
{
    private const MAX_BODY = 20000;

    private static function cors(Response $r): Response
    {
        $r->headers['Access-Control-Allow-Origin'] = '*';
        $r->headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
        $r->headers['Access-Control-Allow-Headers'] = 'Content-Type';
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }

    private static function json(array $data, int $status = 200): Response
    {
        return self::cors(Response::json($data, $status));
    }

    public static function preflight(): Response
    {
        return self::cors(new Response('', 204, ['Content-Type' => 'text/plain']));
    }

    /** Corps envoyé par le site (JSON en texte). */
    private static function body(): array
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function str(array $d, string $k, int $max): string
    {
        $v = $d[$k] ?? '';
        return is_string($v) ? mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? ''), 0, $max) : '';
    }

    /** Limite par IP (l'adresse n'est pas gardée en clair). */
    private static function limited(string $what, int $max, int $seconds): bool
    {
        $bucket = "pub:$what:" . hash('sha256', Req::ipBucket() . Config::get('secret', ''));
        if (Repo::tooManyAttempts($bucket, $max, $seconds)) {
            return true;
        }
        Repo::recordAttempt($bucket);
        return false;
    }

    private static function catalogueSite(string $key): ?array
    {
        $s = Sites::byPublicKey($key);
        return $s && $s['connector'] === 'catalogue' ? $s : null;
    }

    // --- « Prévenez-moi quand il revient » ---

    public static function waitlist(array $p): Response
    {
        $site = self::catalogueSite($p['key']);
        $d = self::body();
        if (!$site) {
            return self::json(['error' => 'Boutique introuvable.'], 404);
        }
        if (self::str($d, 'site_web', 10) !== '') {
            return self::json(['ok' => true]);
        }
        if (self::limited('alerte', 20, 3600)) {
            return self::json(['error' => 'Trop de demandes. Réessayez plus tard.'], 429);
        }
        $email = self::str($d, 'email', 200);
        $id = self::str($d, 'produit', 191);
        $name = '';
        foreach (\SimpleCommerce\Controllers\CatalogueController::data($site)['produits'] as $prod) {
            if ($prod['id'] === $id) {
                $name = $prod['nom'];
            }
        }
        if ($name === '' || !Waitlist::add($site, $id, $name, $email)) {
            return self::json(['error' => 'Vérifiez votre adresse e-mail.'], 422);
        }
        return self::json(['ok' => true, 'message' => 'C\'est noté : nous vous écrirons dès son retour.']);
    }

    // --- Commandes ---

    public static function order(array $p): Response
    {
        $site = self::catalogueSite($p['key']);
        $d = self::body();
        if (!$site || !Orders::enabled($site)) {
            return self::json(['error' => 'Les commandes ne sont pas ouvertes sur ce site.'], 404);
        }
        if (self::str($d, 'site_web', 10) !== '') {
            return self::json(['ok' => true, 'number' => 0]);
        }
        if (\SimpleCommerce\Controllers\CatalogueController::data($site)['ferme']) {
            return self::json(['error' => 'La boutique est fermée pour le moment.'], 409);
        }
        $c = ['nom' => self::str($d, 'nom', 120), 'email' => mb_strtolower(self::str($d, 'email', 200)), 'telephone' => self::str($d, 'telephone', 40),
            'adresse' => self::str($d, 'adresse', 500), 'note' => self::str($d, 'note', 1000)];
        if ($c['nom'] === '' || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            return self::json(['error' => 'Indiquez votre nom et une adresse e-mail valide.'], 422);
        }
        if (self::limited('commande', 8, 3600)) {
            return self::json(['error' => 'Trop de commandes envoyées. Réessayez plus tard ou contactez la boutique.'], 429);
        }
        $lines = array_values(array_filter((array) ($d['lignes'] ?? []), 'is_array'));
        $r = Orders::create($site, $c, $lines);
        if (!$r['ok']) {
            return self::json(['error' => $r['error']], 422);
        }
        return self::json(['ok' => true, 'number' => $r['number'], 'message' => "Merci ! Votre commande n° {$r['number']} est bien reçue. Un e-mail de confirmation vous a été envoyé."]);
    }

    // --- Avis ---

    public static function reviewsScript(array $p): Response
    {
        $site = Sites::byPublicKey($p['key']);
        if (!$site || empty(Sites::extras($site)['reviews'])) {
            return new Response('', 404, ['Content-Type' => 'application/javascript; charset=utf-8', 'Access-Control-Allow-Origin' => '*']);
        }
        $data = Reviews::publicData($site) + ['api' => Config::url('/avis/' . $p['key'])];
        $js = '(function(){var D=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
            . file_get_contents(SC_ROOT . '/public/assets/avis-widget.js') . '})();';
        return new Response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=60', 'Access-Control-Allow-Origin' => '*']);
    }

    public static function review(array $p): Response
    {
        $site = Sites::byPublicKey($p['key']);
        $d = self::body();
        if (!$site || empty(Sites::extras($site)['reviews'])) {
            return self::json(['error' => 'Les avis ne sont pas ouverts sur ce site.'], 404);
        }
        if (self::str($d, 'site_web', 10) !== '') {
            return self::json(['ok' => true]);
        }
        $author = self::str($d, 'nom', 80);
        $body = self::str($d, 'texte', 1500);
        $rating = (int) ($d['note'] ?? 0);
        if ($author === '' || mb_strlen($body) < 3 || $rating < 1 || $rating > 5) {
            return self::json(['error' => 'Indiquez votre prénom, une note et quelques mots.'], 422);
        }
        if (self::limited('avis', 3, 86400)) {
            return self::json(['error' => 'Vous avez déjà laissé un avis aujourd\'hui. Merci !'], 429);
        }
        Reviews::add($site, $author, $rating, $body);
        return self::json(['ok' => true, 'message' => 'Merci pour votre avis ! Il apparaîtra après une relecture.']);
    }

    // --- Statistiques ---

    public static function statsScript(array $p): Response
    {
        $site = Sites::byPublicKey($p['key']);
        $js = $site && !empty(Sites::extras($site)['stats']) ? Stats::script(Config::url('/p/' . $p['key'])) : '';
        return new Response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }

    public static function statsHit(array $p): Response
    {
        $site = Sites::byPublicKey($p['key']);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($site && !empty(Sites::extras($site)['stats']) && $ua !== '' && !preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor/i', $ua)
            && !self::limited('vue:' . $site['id'], 300, 3600)) {
            $d = self::body();
            Stats::record($site, self::str($d, 'p', 300), self::str($d, 'r', 300), ($d['n'] ?? false) === true);
        }
        return self::cors(new Response('', 204, ['Content-Type' => 'text/plain']));
    }

    // --- Rendez-vous ---

    private static function bookingSite(string $key): ?array
    {
        $s = Sites::byPublicKey($key);
        return $s && \SimpleCommerce\Services\Bookings::settings($s)['enabled'] ? $s : null;
    }

    public static function bookingScript(array $p): Response
    {
        $site = self::bookingSite($p['key']);
        if (!$site) {
            return new Response('', 404, ['Content-Type' => 'application/javascript; charset=utf-8', 'Access-Control-Allow-Origin' => '*']);
        }
        $data = \SimpleCommerce\Services\Bookings::publicData($site) + ['api' => Config::url('/rdv/' . $p['key'])];
        $js = '(function(){var D=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
            . file_get_contents(SC_ROOT . '/public/assets/rdv-widget.js') . '})();';
        return new Response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=60', 'Access-Control-Allow-Origin' => '*']);
    }

    /** Jours ouverts, ou créneaux d'un jour (?jour=AAAA-MM-JJ), pour une prestation. */
    public static function bookingSlots(array $p): Response
    {
        $site = self::bookingSite($p['key']);
        if (!$site) {
            return self::json(['error' => 'Prise de rendez-vous fermée.'], 404);
        }
        $i = (int) Req::query('prestation', 3);
        $day = Req::query('jour', 10);
        return $day !== ''
            ? self::json(['jour' => $day, 'creneaux' => \SimpleCommerce\Services\Bookings::slots($site, $i, $day)])
            : self::json(['jours' => \SimpleCommerce\Services\Bookings::openDays($site, $i)]);
    }

    public static function book(array $p): Response
    {
        $site = self::bookingSite($p['key']);
        $d = self::body();
        if (!$site) {
            return self::json(['error' => 'Prise de rendez-vous fermée.'], 404);
        }
        if (self::str($d, 'site_web', 10) !== '') {
            return self::json(['ok' => true, 'message' => 'Rendez-vous confirmé.']);
        }
        $c = ['nom' => self::str($d, 'nom', 120), 'email' => mb_strtolower(self::str($d, 'email', 200)), 'telephone' => self::str($d, 'telephone', 40), 'note' => self::str($d, 'note', 600)];
        if ($c['nom'] === '' || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            return self::json(['error' => 'Indiquez votre nom et une adresse e-mail valide.'], 422);
        }
        if (self::limited('rdv', 6, 3600)) {
            return self::json(['error' => 'Trop de demandes. Réessayez plus tard ou contactez l\'établissement.'], 429);
        }
        $r = \SimpleCommerce\Services\Bookings::book($site, (int) ($d['prestation'] ?? -1), self::str($d, 'jour', 10), self::str($d, 'heure', 5), $c);
        return $r['ok'] ? self::json(['ok' => true, 'message' => "C'est noté : rendez-vous le {$r['when']}. Un e-mail de confirmation vous a été envoyé."]) : self::json(['error' => $r['error']], 409);
    }
}
