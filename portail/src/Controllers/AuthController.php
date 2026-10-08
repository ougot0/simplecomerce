<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Config;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Auth;
use SimpleCommerce\Support\Session;

/** Comptes : connexion, inscription, lien par e-mail, mon compte, invitations. */
final class AuthController
{
    public static function home(): Response
    {
        return Response::redirect(Access::viewer() ? '/sites' : '/connexion');
    }

    public static function loginForm(array $p, array $state = []): Response
    {
        if (Access::viewer() && !$state) {
            return Response::redirect('/sites');
        }
        $next = Req::safeNext(Req::str('suite', 500) ?: Req::str('next', 500));
        return Response::page('auth/login', $state + ['next' => $next, 'demo' => Config::demo(), 'values' => [], 'errors' => []], 'auth', $state ? 422 : 200);
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

    public static function signupForm(array $p, array $state = []): Response
    {
        $next = Req::safeNext(Req::str('suite', 500) ?: Req::str('next', 500));
        return Response::page('auth/signup', $state + ['next' => $next, 'values' => ['email' => Req::query('email', 200), 'name' => ''], 'errors' => []], 'auth', $state ? 422 : 200);
    }

    public static function signup(array $p): Response
    {
        $email = mb_strtolower(Req::str('email', 200));
        $name = Req::str('name', 120);
        $password = Req::raw('password');
        $values = compact('email', 'name');
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'Indiquez votre prénom et votre nom.';
        }
        if ($problem = Auth::passwordProblem($password)) {
            $errors['password'] = $problem;
        }
        if (Repo::tooManyAttempts('signup:' . Req::ip(), 10, 3600)) {
            return self::signupForm($p, ['error' => 'Trop de comptes créés depuis cette connexion. Réessayez dans une heure.', 'values' => $values]);
        }
        if ($errors) {
            return self::signupForm($p, ['errors' => $errors, 'values' => $values]);
        }
        if (Repo::userByEmail($email)) {
            return self::signupForm($p, ['error' => 'Un compte existe déjà avec cette adresse. Connectez-vous, ou demandez un lien de connexion par e-mail.', 'values' => $values]);
        }
        Repo::recordAttempt('signup:' . Req::ip());
        $user = Repo::createUser($email, $name, $password);
        Repo::log($user['id'], null, null, 'signup');
        Auth::login($user, Req::bool('remember'));
        return Response::redirect(Req::safeNext(Req::str('next', 500)));
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
                Repo::updateUser($uid, ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
                Access::audit($v, 'password_changed');
                Session::flash('Mot de passe modifié.');
                break;
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
        Access::audit($v, 'invitation_accepted', ['email' => $inv['email']], $inv['site_id']);
        $site = Repo::site($inv['site_id']);
        Session::flash('Bienvenue ! Vous pouvez maintenant modifier ce site.');
        return Response::redirect($site ? '/s/' . $site['slug'] : '/sites');
    }
}
