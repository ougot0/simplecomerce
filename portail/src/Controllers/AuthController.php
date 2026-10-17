<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Config;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Auth;
use SimpleCommerce\Services\Referral;
use SimpleCommerce\Support\Session;

/** Comptes : connexion, inscription, lien par e-mail, mon compte, invitations. */
final class AuthController
{
    public static function home(): Response
    {
        if (Access::viewer()) {
            return Response::redirect('/sites');
        }
        if (!Config::demo() && !Repo::users()) {
            return Response::redirect('/inscription'); // tout premier passage : création du compte administrateur
        }
        if (Req::inApp()) {
            return Response::redirect('/connexion'); // l'application ouvre directement la connexion (la page de présentation parle des prix)
        }
        // Page de présentation publique, la seule que les moteurs de recherche peuvent afficher.
        // C'est une vraie page (public/accueil.html), créée une fois à partir du modèle : on peut donc la relier
        // au portail comme n'importe quel site et la modifier (textes, étapes, logo). Les mises à jour ne l'écrasent pas.
        $page = SC_ROOT . '/public/accueil.html';
        if (!is_file($page)) {
            $model = (string) file_get_contents(SC_ROOT . '/templates/public/accueil.html');
            $html = str_replace('{{ORIGINE}}', Req::origin(), $model);
            if (Config::demo() || @file_put_contents($page, $html) === false) {
                return Response::html($html); // dossier non modifiable (ou démonstration) : on affiche le modèle
            }
        }
        return Response::html((string) file_get_contents($page));
    }

    /** Moteurs de recherche : seule la page de présentation est à visiter. */
    public static function robots(): Response
    {
        // Seules la page d'accueil, ses images et le plan du site sont ouverts aux moteurs de recherche
        // (sans « Allow: /sitemap.xml », Google refuse de lire le plan du site : « Impossible de lire le sitemap »).
        return new Response("User-agent: *\nAllow: /\$\nAllow: /assets/\nAllow: /sitemap.xml\nAllow: /mentions-legales\nAllow: /conditions-generales-utilisation\n"
            . "Allow: /conditions-generales-vente\nAllow: /confidentialite\nDisallow: /\n\nSitemap: " . Req::origin() . '/sitemap.xml' . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . htmlspecialchars(Req::origin() . '/') . '</loc></url></urlset>' . "\n";
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function loginForm(array $p, array $state = []): Response
    {
        if (Access::viewer() && !$state) {
            return Response::redirect('/sites');
        }
        $next = Req::safeNext(Req::str('suite', 500) ?: Req::str('next', 500));
        return Response::page('auth/login', $state + ['next' => $next, 'demo' => Config::demo(), 'signupOpen' => self::signupOpen(), 'values' => [], 'errors' => []], 'auth', $state ? 422 : 200);
    }

    public static function login(array $p): Response
    {
        $email = mb_strtolower(Req::str('email', 200));
        $values = ['email' => $email];
        $remember = Req::bool('remember');
        $next = Req::safeNext(Req::str('next', 500));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::loginForm($p, ['errors' => ['email' => 'Adresse e-mail invalide.'], 'values' => $values]);
        }
        if (Req::str('intent') === 'magic') {
            $link = Auth::sendMagicLink($email, $remember, $next);
            return self::loginForm($p, ['info' => 'Si un compte existe avec cette adresse, un lien de connexion vient de lui être envoyé. Il est valable 15 minutes.', 'demoLink' => $link, 'values' => $values]);
        }
        $password = Req::raw('password');
        if ($password === '') {
            return self::loginForm($p, ['errors' => ['password' => 'Indiquez votre mot de passe.'], 'values' => $values]);
        }
        $r = Auth::attempt($email, $password, $remember);
        if (!$r['ok']) {
            return self::loginForm($p, ['error' => $r['error'], 'values' => $values]);
        }
        return Response::redirect($next);
    }

    /** Inscriptions ouvertes à tous ? (réglage de l'administrateur ; fermées par défaut). */
    public static function signupOpen(): bool
    {
        // Tant qu'aucun compte n'existe, l'inscription est ouverte : le premier compte devient l'administrateur.
        return Config::demo() || Repo::meta('signup_open', '0') === '1' || !Repo::users();
    }

    /** Invitation en attente correspondant à la page de retour (/invitation/…), pour les inscriptions fermées. */
    private static function invitationFor(string $next): ?array
    {
        return preg_match('#^/invitation/([\w-]{20,100})$#', $next, $m) ? self::findInvitation($m[1]) : null;
    }

    public static function signupForm(array $p, array $state = []): Response
    {
        $next = Req::safeNext(Req::str('suite', 500) ?: Req::str('next', 500));
        if (!self::signupOpen() && !self::invitationFor($next)) {
            return Response::page('message', ['title' => 'Inscriptions sur invitation', 'text' => "Pour l'instant, on ne peut créer un compte qu'avec une invitation. Demandez-la à la personne qui gère Simple Commerce, ou connectez-vous si vous avez déjà un compte.", 'link' => ['/connexion', 'Se connecter']], 'auth', 403);
        }
        return Response::page('auth/signup', $state + ['next' => $next, 'parrain' => preg_replace('/[^A-Za-z0-9]/', '', Req::query('parrain', 20) ?: Req::str('parrain', 20)), 'values' => ['email' => Req::query('email', 200), 'name' => ''], 'errors' => [], 'firstAccount' => !Config::demo() && !Repo::users()], 'auth', $state ? 422 : 200);
    }

    public static function signup(array $p): Response
    {
        $email = mb_strtolower(Req::str('email', 200));
        $password = Req::raw('password');
        // Seuls l'e-mail et le mot de passe sont demandés ; le nom se change ensuite dans « Mon compte ».
        $name = Req::str('name', 120) ?: mb_substr(ucfirst((string) strtok($email, '@')), 0, 80);
        $values = compact('email', 'name');
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if ($problem = Auth::passwordProblem($password)) {
            $errors['password'] = $problem;
        }
        $values['terms'] = Req::str('terms') === '1';
        if (!$values['terms']) {
            $errors['terms'] = 'Cochez la case pour accepter les conditions.';
        }
        if (Repo::tooManyAttempts('signup:' . Req::ipBucket(), 10, 3600)) {
            return self::signupForm($p, ['error' => 'Trop de comptes créés depuis cette connexion. Réessayez dans une heure.', 'values' => $values]);
        }
        if (!self::signupOpen()) {
            $inv = self::invitationFor(Req::safeNext(Req::str('next', 500)));
            if (!$inv) {
                return self::signupForm($p);
            }
            if (mb_strtolower($inv['email']) !== $email) {
                $errors['email'] = "Utilisez l'adresse qui a reçu l'invitation : " . $inv['email'];
            }
        }
        if ($errors) {
            return self::signupForm($p, ['errors' => $errors, 'values' => $values]);
        }
        if (Repo::userByEmail($email)) {
            return self::signupForm($p, ['error' => 'Un compte existe déjà avec cette adresse. Connectez-vous, ou demandez un lien de connexion par e-mail.', 'values' => $values]);
        }
        Repo::recordAttempt('signup:' . Req::ipBucket());
        $first = !Repo::users();
        $user = Repo::createUser($email, $name, $password, $first && !Config::demo());
        $demoLink = null;
        if (!$first) {
            Auth::markUnverified($user['id']);
            $demoLink = Auth::sendVerification($user);
        }
        Referral::attach($user['id'], Req::str('parrain', 20));
        Repo::log($user['id'], null, null, 'signup', ['conditions_acceptees' => \SimpleCommerce\Services\Legal::UPDATED]);
        if ($first && !Config::demo()) {
            // Le propriétaire du portail vient de créer son compte : la suite des inscriptions reste à son choix.
            Session::flash('Bienvenue ! Vous êtes l\'administrateur de Simple Commerce. Vous pouvez maintenant relier vos sites.');
            Auth::login($user, Req::bool('remember'));
            return Response::redirect('/admin');
        }
        Auth::login($user, Req::bool('remember'));
        Session::set('signup_uid', $user['id']);
        Session::flash("Votre compte est créé. Un e-mail de confirmation vient d'être envoyé à $email : ouvrez le lien qu'il contient quand vous voulez. Vous pouvez déjà relier votre site."
            . ($demoLink ? " (Démonstration : $demoLink)" : ''), 'warn');
        return Response::redirect(Req::safeNext(Req::str('next', 500)));
    }

    public static function verify(array $p): Response
    {
        $r = Auth::consumeVerification($p['token']);
        if ($r === null) {
            return Response::page('message', ['title' => 'Lien expiré', 'text' => "Ce lien de confirmation n'est plus valable (il a déjà servi, ou il a plus de 7 jours). Connectez-vous : vous pourrez en demander un nouveau.", 'link' => ['/connexion', 'Aller à la connexion']], 'auth', 410);
        }
        if ($r['claim'] === null) {
            Session::flash('Merci, votre adresse est confirmée ! Vous pouvez relier votre site.');
            return Response::redirect($r['next']);
        }
        return Response::page('auth/verified', ['claim' => $r['claim'], 'title' => 'Adresse confirmée'], 'auth');
    }

    public static function claim(array $p): Response
    {
        if (!Auth::claimAccount($p['token'])) {
            return Response::page('message', ['title' => 'Lien expiré', 'text' => "Ce lien n'est plus valable. Utilisez « Mot de passe oublié » sur la page de connexion.", 'link' => ['/connexion', 'Aller à la connexion']], 'auth', 410);
        }
        Session::flash('Le compte est à vous : les autres appareils ont été déconnectés. Choisissez maintenant votre mot de passe.');
        return Response::redirect('/compte?nouveau-mot-de-passe=1');
    }

    public static function resendVerification(): Response
    {
        $v = Access::requireViewer();
        if (Auth::isVerified($v['user'])) {
            return Response::redirect('/sites');
        }
        $link = Auth::sendVerification($v['user']);
        Session::flash('E-mail de confirmation renvoyé à ' . $v['user']['email'] . '. Regardez aussi dans les indésirables.' . ($link ? " (Démonstration : $link)" : ''));
        return Response::redirect(Req::safeNext(Req::str('next', 500)) ?: '/sites');
    }

    public static function forgotForm(array $p, array $state = []): Response
    {
        return Response::page('auth/forgot', $state + ['errors' => []], 'auth');
    }

    public static function forgot(array $p): Response
    {
        $email = mb_strtolower(Req::str('email', 200));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::forgotForm($p, ['errors' => ['email' => 'Adresse e-mail invalide.']]);
        }
        $link = Auth::sendMagicLink($email, false, '/compte?nouveau-mot-de-passe=1');
        return self::forgotForm($p, ['info' => 'Si un compte existe avec cette adresse, vous allez recevoir un lien pour vous connecter. Vous pourrez ensuite choisir un nouveau mot de passe dans « Mon compte ».', 'demoLink' => $link]);
    }

    public static function magic(array $p): Response
    {
        $next = Auth::consumeMagicLink($p['token']);
        if ($next === null) {
            return Response::page('message', ['title' => 'Lien expiré', 'text' => "Ce lien de connexion n'est plus valable : il a déjà servi, ou il a plus de 15 minutes. Demandez-en un nouveau.", 'link' => ['/connexion', 'Aller à la connexion']], 'auth', 410);
        }
        return Response::redirect(Req::safeNext($next));
    }

    public static function logout(): Response
    {
        Auth::logout();
        return Response::redirect('/connexion');
    }

    public static function account(array $p, array $state = []): Response
    {
        $v = Access::requireViewer();
        $state += ['deletePlan' => \SimpleCommerce\Services\AccountDeletion::plan($v['user']['id'])];
        return Response::page('account', $state + ['viewer' => $v, 'resetHint' => Req::query('nouveau-mot-de-passe') === '1'], 'plain');
    }

    public static function saveAccount(array $p): Response
    {
        $v = Access::requireViewer();
        if ($v['assist']) {
            return self::account($p, ['error' => 'Impossible en mode assistance.']);
        }
        $uid = $v['user']['id'];
        switch (Req::str('intent')) {
            case 'name':
                $name = Req::str('name', 120);
                if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                    return self::account($p, ['error' => 'Nom invalide.']);
                }
                Repo::updateUser($uid, ['name' => $name]);
                Session::flash('Nom enregistré.');
                break;
            case 'notify':
                Repo::updateUser($uid, ['notify' => Req::bool('notify') ? 1 : 0]);
                Session::flash(Req::bool('notify') ? 'Vous serez prévenu par e-mail des modifications faites par les autres.' : 'Vous ne recevrez plus ces e-mails.');
                break;
            case 'password':
                $pw = Req::raw('password');
                if ($problem = Auth::passwordProblem($pw)) {
                    return self::account($p, ['error' => $problem]);
                }
                if ($pw !== Req::raw('confirm')) {
                    return self::account($p, ['error' => 'Les deux mots de passe ne sont pas identiques.']);
                }
                // Mot de passe actuel demandé, sauf juste après un lien de connexion reçu par e-mail (mot de passe oublié).
                $viaLink = (int) Session::get('magic_at') > time() - 1800;
                if (!$viaLink && !password_verify(Req::raw('current'), (string) $v['user']['password_hash'])) {
                    return self::account($p, ['error' => 'Mot de passe actuel incorrect.']);
                }
                Auth::changePassword($uid, $pw);
                Session::forget('magic_at');
                Access::audit($v, 'password_changed');
                Session::flash('Mot de passe modifié.');
                break;
            case 'delete':
                if ($problem = \SimpleCommerce\Services\AccountDeletion::problem($v['user'])) {
                    return self::account($p, ['error' => $problem]);
                }
                if (mb_strtoupper(trim(Req::str('confirm', 40))) !== 'SUPPRIMER') {
                    return self::account($p, ['deleteError' => 'Pour confirmer, écrivez SUPPRIMER dans la case.']);
                }
                $viaLink = (int) Session::get('magic_at') > time() - 1800;
                if (!$viaLink && !password_verify(Req::raw('current'), (string) $v['user']['password_hash'])) {
                    return self::account($p, ['deleteError' => 'Mot de passe incorrect.']);
                }
                try {
                    \SimpleCommerce\Services\AccountDeletion::delete($v['user']);
                } catch (\Throwable $e) {
                    \SimpleCommerce\Support\Log::warn('Suppression de compte impossible', ['err' => $e->getMessage()]);
                    return self::account($p, ['deleteError' => "La suppression n'a pas pu se faire (l'arrêt de votre abonnement n'a pas répondu). Rien n'a été effacé. Réessayez dans quelques minutes."]);
                }
                Repo::log(null, null, null, 'account_deleted');
                Auth::logout();
                return Response::page('message', ['title' => 'Compte supprimé', 'text' => "Votre compte Simple Commerce a été supprimé. Vos sites, eux, n'ont pas été touchés.", 'link' => ['/connexion', 'Retour à la connexion']], 'auth');
        }
        return Response::redirect('/compte');
    }

    private static function findInvitation(string $token): ?array
    {
        $inv = Repo::invitationByHash(hash('sha256', $token));
        return $inv && !$inv['accepted_at'] && $inv['expires_at'] > gmdate('Y-m-d\TH:i:s\Z') ? $inv : null;
    }

    public static function invitation(array $p, ?string $error = null): Response
    {
        $inv = self::findInvitation($p['token']);
        $site = $inv ? Repo::site($inv['site_id']) : null;
        if (!$inv || !$site) {
            return Response::page('message', ['title' => 'Invitation expirée', 'text' => "Ce lien n'est plus valable (il a déjà servi, ou il a plus de 14 jours). Demandez une nouvelle invitation à la personne qui vous l'a envoyé.", 'link' => ['/connexion', 'Aller à la connexion']], 'auth', 410);
        }
        return Response::page('auth/invitation', ['inv' => $inv, 'site' => $site, 'inviter' => Repo::user($inv['invited_by']), 'viewer' => Access::viewer(), 'token' => $p['token'], 'error' => $error], 'auth');
    }

    public static function acceptInvitation(array $p): Response
    {
        $v = Access::requireViewer();
        $inv = self::findInvitation($p['token']);
        if (!$inv) {
            return self::invitation($p);
        }
        // L'invitation est liée à une adresse : impossible de l'utiliser depuis un autre compte.
        if (mb_strtolower($v['effective']['email']) !== mb_strtolower($inv['email'])) {
            return self::invitation($p, 'Cette invitation est destinée à une autre adresse.');
        }
        Repo::acceptInvitation($inv, $v['effective']['id']);
        Auth::markVerified($v['effective']['id']); // l'invitation est arrivée à cette adresse : elle est prouvée
        Access::audit($v, 'invitation_accepted', ['email' => $inv['email']], $inv['site_id']);
        $site = Repo::site($inv['site_id']);
        Session::flash('Bienvenue ! Vous pouvez maintenant modifier ce site.');
        return Response::redirect($site ? '/s/' . $site['slug'] : '/sites');
    }
}
