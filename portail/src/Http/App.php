<?php
declare(strict_types=1);

namespace SimpleCommerce\Http;

use SimpleCommerce\Config;
use SimpleCommerce\Controllers\AdminController;
use SimpleCommerce\Controllers\AuthController;
use SimpleCommerce\Controllers\BillingController;
use SimpleCommerce\Controllers\BookingController;
use SimpleCommerce\Controllers\CatalogueController;
use SimpleCommerce\Controllers\EditorController;
use SimpleCommerce\Controllers\InstallController;
use SimpleCommerce\Controllers\LegalController;
use SimpleCommerce\Controllers\MiscController;
use SimpleCommerce\Controllers\PublicController;
use SimpleCommerce\Controllers\SettingsController;
use SimpleCommerce\Controllers\ShopController;
use SimpleCommerce\Controllers\SiteController;
use SimpleCommerce\Controllers\SitesController;
use SimpleCommerce\Schema;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Demo;
use SimpleCommerce\Services\Scheduler;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Session;

/** Point d'entrée : sécurité commune, puis aiguillage vers le bon contrôleur. */
final class App
{
    private const SLUG = '(?<slug>[a-z0-9-]{1,80})';
    private const KEY = '(?<section>[A-Za-z0-9_\-.]{1,120})';

    /** @return array<int, array{0: string, 1: string, 2: callable}> */
    private static function routes(): array
    {
        $s = '/s/' . self::SLUG;
        return [
            ['GET', '/', [AuthController::class, 'home']],
            ['GET', '/robots.txt', [AuthController::class, 'robots']],
            ['GET', '/sitemap.xml', [AuthController::class, 'sitemap']],
            ['GET', '/(?<page>mentions-legales|conditions-generales-utilisation|conditions-generales-vente|confidentialite)', [LegalController::class, 'page']],
            ['GET', '/rdv/annuler/(?<token>[\w-]{20,64})', [BookingController::class, 'cancelPage']],
            ['POST', '/rdv/annuler/(?<token>[\w-]{20,64})', [BookingController::class, 'cancelByCustomer']],
            ['GET', '/connexion', [AuthController::class, 'loginForm']],
            ['POST', '/connexion', [AuthController::class, 'login']],
            ['GET', '/inscription', [AuthController::class, 'signupForm']],
            ['POST', '/inscription', [AuthController::class, 'signup']],
            ['GET', '/mot-de-passe-oublie', [AuthController::class, 'forgotForm']],
            ['POST', '/mot-de-passe-oublie', [AuthController::class, 'forgot']],
            ['GET', '/lien/(?<token>[\w-]{20,100})', [AuthController::class, 'magic']],
            ['GET', '/verifier/(?<token>[\w-]{20,100})', [AuthController::class, 'verify']],
            ['POST', '/verifier/renvoyer', [AuthController::class, 'resendVerification']],
            ['POST', '/deconnexion', [AuthController::class, 'logout']],
            ['GET', '/compte', [AuthController::class, 'account']],
            ['GET', '/compte/abonnement', [BillingController::class, 'show']],
            ['POST', '/compte/abonnement/payer', [BillingController::class, 'pay']],
            ['POST', '/compte/abonnement/gerer', [BillingController::class, 'manage']],
            ['POST', '/compte', [AuthController::class, 'saveAccount']],
            ['GET', '/invitation/(?<token>[\w-]{20,100})', [AuthController::class, 'invitation']],
            ['POST', '/invitation/(?<token>[\w-]{20,100})', [AuthController::class, 'acceptInvitation']],

            ['GET', '/sites', [SitesController::class, 'index']],
            ['GET', '/sites/nouveau', [SitesController::class, 'newForm']],
            ['POST', '/sites/nouveau', [SitesController::class, 'create']],

            ['GET', $s, [SiteController::class, 'home']],
            ['GET', "$s/r/" . self::KEY, [SiteController::class, 'section']],
            ['GET', "$s/r/" . self::KEY . '/nouveau', [EditorController::class, 'newEntry']],
            ['GET', "$s/r/" . self::KEY . '/e/(?<id>[^/]{1,200})', [EditorController::class, 'editEntry']],
            ['POST', "$s/enregistrer", [EditorController::class, 'save']],
            ['POST', "$s/supprimer", [EditorController::class, 'delete']],
            ['POST', "$s/ordre", [EditorController::class, 'reorder']],
            ['POST', "$s/visibilite", [EditorController::class, 'toggleVisibility']],
            ['POST', "$s/dupliquer", [EditorController::class, 'duplicate']],
            ['POST', "$s/groupe", [EditorController::class, 'bulk']],
            ['POST', "$s/photo", [EditorController::class, 'upload']],
            ['GET', "$s/photos", [SiteController::class, 'photos']],
            ['GET', "$s/brouillons", [SiteController::class, 'drafts']],
            ['POST', "$s/brouillons/publier", [SiteController::class, 'publishDraft']],
            ['POST', "$s/brouillons/jeter", [SiteController::class, 'discardDraft']],
            ['GET', "$s/historique", [SiteController::class, 'history']],
            ['POST', "$s/historique/annuler", [SiteController::class, 'revert']],
            ['GET', "$s/sauvegarde", [SiteController::class, 'backup']],
            ['GET', "$s/sauvegardes/(?<name>\d{8}-\d{6}-[a-z]{4,5}\.zip)", [SettingsController::class, 'downloadBackup']],
            ['GET', "$s/telephone", [SiteController::class, 'phone']],
            ['GET', "$s/rendez-vous", [BookingController::class, 'page']],
            ['POST', "$s/rendez-vous/action", [BookingController::class, 'action']],
            ['GET', "$s/commandes", [ShopController::class, 'orders']],
            ['GET', "$s/commandes\.csv", [ShopController::class, 'ordersCsv']],
            ['POST', "$s/commandes/etat", [ShopController::class, 'orderStatus']],
            ['GET', "$s/commandes/(?<id>[a-f0-9-]{36})/(?<doc>bon|facture)", [ShopController::class, 'orderDocument']],
            ['GET', "$s/avis", [ShopController::class, 'reviews']],
            ['POST', "$s/avis/action", [ShopController::class, 'reviewAction']],
            ['GET', "$s/statistiques", [ShopController::class, 'stats']],
            ['POST', "$s/statistiques/action", [ShopController::class, 'statsAction']],
            ['GET', "$s/reglages", [SettingsController::class, 'show']],
            ['POST', "$s/reglages/(?<action>[a-z-]{2,30})", [SettingsController::class, 'action']],

            ['GET', '/aide', [MiscController::class, 'help']],
            ['GET', '/aide/contact', [MiscController::class, 'helpForm']],
            ['POST', '/aide/contact', [MiscController::class, 'helpSend']],
            ['GET', '/media/(?<id>[0-9a-f-]{36})', [MiscController::class, 'media']],
            ['GET', '/cron', [MiscController::class, 'cron']],
            ['GET', '/demo-sites/(?<site>[a-z-]+)(?<rest>/.*)?', [MiscController::class, 'demoSite']],

            ['GET', '/admin', [AdminController::class, 'index']],
            ['GET', '/admin/journal', [AdminController::class, 'journal']],
            ['POST', '/admin/assister', [AdminController::class, 'startAssist']],
            ['POST', '/admin/fin-assistance', [AdminController::class, 'stopAssist']],
            ['POST', '/admin/suspendre', [AdminController::class, 'setStatus']],
            ['POST', '/admin/inscriptions', [AdminController::class, 'setSignup']],
            ['GET', '/admin/sauvegarde', [AdminController::class, 'portalBackup']],
            ['GET', '/admin/informations-legales', [LegalController::class, 'admin']],
            ['POST', '/admin/informations-legales', [LegalController::class, 'save']],
            ['POST', '/admin/confirmer-email', [AdminController::class, 'confirmEmail']],
            ['GET', '/admin/emails', [AdminController::class, 'emails']],
            ['POST', '/admin/emails', [AdminController::class, 'saveEmails']],
            ['GET', '/admin/revenus', [BillingController::class, 'revenue']],
            ['GET', '/admin/abonnements', [BillingController::class, 'admin']],
            ['POST', '/admin/abonnements', [BillingController::class, 'saveAdmin']],
        ];
    }

    public static function run(): void
    {
        // Portail installé en https mais page ouverte en http : on bascule, sinon la connexion échouerait (cookie sécurisé).
        // Le petit cookie évite une boucle si l'hébergeur ne signale pas le https (la page s'affiche alors normalement).
        if (Config::installed() && str_starts_with(Config::url(), 'https://') && !Req::isHttps() && PHP_SAPI !== 'cli-server' && !isset($_COOKIE['sc_https_try'])) {
            setcookie('sc_https_try', '1', ['expires' => time() + 120, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
            $host = preg_replace('/[^\w.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?: (string) parse_url(Config::url(), PHP_URL_HOST);
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            return;
        }
        self::securityHeaders();
        try {
            $response = self::handle();
        } catch (Halt $h) {
            $response = $h->status === 404 ? self::notFound() : Response::redirect($h->location ?? '/');
        } catch (\Throwable $e) {
            Log::error('erreur', ['err' => $e, 'path' => Req::path()]);
            $response = Response::html(View::render('error', ['message' => Config::get('debug') ? $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine() : null], 'bare'), 500);
        }
        $response->send();
        // La publication programmée passe après l'envoi de la page, pour ne pas la ralentir.
        if (Config::installed() && Schema::isInstalled()) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                Scheduler::maybeRun();
            } catch (\Throwable $e) {
                Log::error('planificateur', ['err' => $e]);
            }
        }
    }

    private static function handle(): Response
    {
        $method = Req::method();
        $path = Req::path();

        if (!Config::installed() && InstallController::autoInstall()) {
            return Response::redirect('/');
        }
        if (Config::installed()) {
            Schema::migrate();
        }
        if (!Config::installed()) {
            return $path === '/installation' ? ($method === 'POST' ? InstallController::install() : InstallController::form()) : Response::redirect('/installation');
        }
        if ($path === '/installation') {
            return Response::redirect('/');
        }
        // Catalogues hébergés : pages publiques lues par les sites des clients (sans session ni cookie).
        if ($method === 'GET' || $method === 'HEAD') {
            if (preg_match('#^/catalogue/(?<key>[a-f0-9]{24})\.js$#', $path, $m)) {
                return CatalogueController::script($m);
            }
            if (preg_match('#^/catalogue/(?<key>[a-f0-9]{24})\.json$#', $path, $m)) {
                return CatalogueController::json($m);
            }
            if (preg_match('#^/catalogue/(?<key>[a-f0-9]{24})/photos/(?<file>[\w.-]{1,120}\.(?:webp|png|jpe?g))$#', $path, $m)) {
                return CatalogueController::photo($m);
            }
            if (preg_match('#^/horaires/(?<key>[a-f0-9]{24})\.js$#', $path, $m)) {
                return CatalogueController::hours($m);
            }
        }
        // Avis de Stripe (abonnements) : signature vérifiée, sans session.
        if ($path === '/stripe/webhook' && $method === 'POST') {
            return BillingController::webhook();
        }
        // Envois des sites des clients : « Prévenez-moi », commandes, avis, statistiques (sans session ni cookie).
        if (preg_match('#^/rdv/(?<key>[a-f0-9]{24})$#', $path, $m) && in_array($method, ['POST', 'OPTIONS'], true)) {
            return $method === 'OPTIONS' ? PublicController::preflight() : PublicController::book($m);
        }
        if (($method === 'GET' || $method === 'HEAD') && preg_match('#^/rdv/(?<key>[a-f0-9]{24})(?<js>\.js|/creneaux)$#', $path, $m)) {
            return $m['js'] === '.js' ? PublicController::bookingScript($m) : PublicController::bookingSlots($m);
        }
        if (preg_match('#^/(?:catalogue/(?<ckey>[a-f0-9]{24})/(?<what>alerte|commande)|avis/(?<akey>[a-f0-9]{24})|p/(?<pkey>[a-f0-9]{24}))$#', $path, $m)) {
            if ($method === 'OPTIONS') {
                return PublicController::preflight();
            }
            if ($method === 'POST') {
                return match (true) {
                    ($m['what'] ?? '') === 'alerte' => PublicController::waitlist(['key' => $m['ckey']]),
                    ($m['what'] ?? '') === 'commande' => PublicController::order(['key' => $m['ckey']]),
                    ($m['akey'] ?? '') !== '' => PublicController::review(['key' => $m['akey']]),
                    default => PublicController::statsHit(['key' => $m['pkey']]),
                };
            }
        }
        if ($method === 'GET' || $method === 'HEAD') {
            if (preg_match('#^/avis/(?<key>[a-f0-9]{24})\.js$#', $path, $m)) {
                return PublicController::reviewsScript($m);
            }
            if (preg_match('#^/p/(?<key>[a-f0-9]{24})\.js$#', $path, $m)) {
                return PublicController::statsScript($m);
            }
        }
        Demo::ensure();
        Schema::migrate();
        Session::start();

        if ($method === 'POST' && !Session::checkCsrf($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            return Req::wantsJson()
                ? Response::json(['error' => 'La page a expiré. Rechargez-la.'], 419)
                : Response::page('message', ['title' => 'Page expirée', 'text' => 'Cette page était ouverte depuis trop longtemps. Revenez en arrière et rechargez-la, puis recommencez.'], 'auth', 419);
        }

        foreach (self::routes() as [$m, $pattern, $handler]) {
            if ($m !== $method && !($m === 'GET' && $method === 'HEAD')) {
                continue;
            }
            if (preg_match('#^' . $pattern . '$#u', $path, $match)) {
                $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
                return $handler($params);
            }
        }
        return self::notFound();
    }

    private static function notFound(): Response
    {
        $viewer = null;
        try {
            $viewer = Access::viewer();
        } catch (\Throwable) {
        }
        return Response::page('message', ['title' => 'Page introuvable', 'text' => "Cette page n'existe pas, ou vous n'y avez pas accès.", 'link' => $viewer ? ['/sites', 'Retour à mes sites'] : ['/connexion', 'Aller à la connexion']], 'auth', 404);
    }

    private static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data: blob:; style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self' https://checkout.stripe.com https://billing.stripe.com; manifest-src 'self'; worker-src 'self'; base-uri 'none'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');
        if (str_starts_with(Config::url(), 'https://')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
