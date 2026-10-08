<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;
use SimpleCommerce\Support\Session;

/**
 * Comptes : inscription, connexion par mot de passe ou lien envoyé par e-mail, « Rester connecté ».
 * « Rester connecté » dépose un jeton de 30 jours (haché en base) ; sinon la session s'arrête à la fermeture du navigateur.
 */
final class Auth
{
    private const REMEMBER_COOKIE = 'sc_remember';
    private const REMEMBER_DAYS = 30;

    public static function userId(): ?string
    {
        $id = Session::get('uid');
        if ($id) {
            return $id;
        }
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($token) && $token !== '') {
            $t = Repo::useToken($token, 'remember', false);
            if ($t && Repo::user($t['user_id'])) {
                Session::regenerate();
                Session::set('uid', $t['user_id']);
                return $t['user_id'];
            }
            self::forgetCookie();
        }
        return null;
    }

    public static function passwordProblem(string $pw): ?string
    {
        if (mb_strlen($pw) < 10) {
            return '10 caractères minimum.';
        }
        if (!preg_match('/[a-zA-Z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Utilisez des lettres et au moins un chiffre.';
        }
        return null;
    }

    public static function login(array $user, bool $remember): void
    {
        Session::regenerate();
        Session::set('uid', $user['id']);
        Session::forget('assist');
        if ($remember) {
            $token = Repo::createToken($user['id'], 'remember', self::REMEMBER_DAYS * 86400);
            setcookie(self::REMEMBER_COOKIE, $token, ['expires' => time() + self::REMEMBER_DAYS * 86400, 'path' => '/', 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        Repo::log($user['id'], null, null, 'login');
    }

    private static function secure(): bool
    {
        return str_starts_with(Config::url(), 'https://');
    }

    private static function forgetCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function logout(): void
    {
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($token) && $token !== '') {
            Repo::deleteToken($token);
        }
        self::forgetCookie();
        Session::destroy();
    }

    /** @return array{ok: bool, error?: string} */
    public static function attempt(string $email, string $password, bool $remember): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $bucket = 'login:' . mb_strtolower($email);
        if (Repo::tooManyAttempts($bucket, 8, 900) || Repo::tooManyAttempts("ip:$ip", 30, 900)) {
            return ['ok' => false, 'error' => 'Trop de tentatives. Patientez un quart d\'heure, ou demandez un lien de connexion par e-mail.'];
        }
        $user = Repo::userByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Repo::recordAttempt($bucket);
            Repo::recordAttempt("ip:$ip");
            return ['ok' => false, 'error' => 'E-mail ou mot de passe incorrect.'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Repo::updateUser($user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        self::login($user, $remember);
        return ['ok' => true];
    }

    /**
     * Envoie un lien de connexion valable 15 minutes. Même réponse que le compte existe ou non.
     * @return ?string le lien, en démonstration uniquement (pour l'afficher à l'écran)
     */
    public static function sendMagicLink(string $email, bool $remember, string $next): ?string
    {
        $user = Repo::userByEmail($email);
        if (!$user || Repo::tooManyAttempts('magic:' . $user['id'], 5, 900)) {
            return null;
        }
        Repo::recordAttempt('magic:' . $user['id']);
        $token = Repo::createToken($user['id'], 'magic', 900, ['remember' => $remember, 'next' => $next]);
        $link = Config::url('/lien/' . $token);
        Mailer::send($user['email'], 'Votre lien de connexion à Simple Commerce',
            "Bonjour {$user['name']},\n\nPour vous connecter, ouvrez ce lien (valable 15 minutes, une seule fois) :\n$link\n\nSi vous n'avez rien demandé, ignorez ce message : personne ne pourra se connecter sans ce lien.");
        return Config::demo() ? $link : null;
    }

    public static function consumeMagicLink(string $token): ?string
    {
        $t = Repo::useToken($token, 'magic', true);
        $user = $t ? Repo::user($t['user_id']) : null;
        if (!$user) {
            return null;
        }
        self::login($user, (bool) ($t['extra']['remember'] ?? false));
        return (string) ($t['extra']['next'] ?? '/sites');
    }
}
