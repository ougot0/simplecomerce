<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;
use SimpleCommerce\Support\Session;

/**
 * Comptes : inscription, connexion par mot de passe ou lien envoyé par e-mail, « Rester connecté ».
 * « Rester connecté » dépose un jeton (haché en base) renouvelé à chaque visite : on reste connecté sans limite,
 * jusqu'à « Se déconnecter ». Sinon la session s'arrête à la fermeture du navigateur.
 */
final class Auth
{
    private const REMEMBER_COOKIE = 'sc_remember';
    /** 400 jours : la plus longue durée acceptée par les navigateurs ; elle repart de zéro à chaque visite. */
    private const REMEMBER_DAYS = 400;

    public static function userId(): ?string
    {
        $id = Session::get('uid');
        if ($id) {
            // Mot de passe changé depuis l'ouverture de cette session (sur un autre appareil) : la session n'est plus valable.
            $u = Repo::user($id);
            $pwv = $u ? self::passwordVersion($u) : null;
            if ($u && Session::get('pwv') === null) {
                Session::set('pwv', $pwv);
            } elseif (!$u || Session::get('pwv') !== $pwv) {
                Session::destroy();
                return null;
            }
            return $id;
        }
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($token) && $token !== '') {
            $t = Repo::useToken($token, 'remember', false);
            if ($t && Repo::user($t['user_id'])) {
                Session::regenerate();
                Session::set('uid', $t['user_id']);
                Session::set('pwv', self::passwordVersion(Repo::user($t['user_id'])));
                Repo::extendToken($t['id'], self::REMEMBER_DAYS * 86400);
                self::setCookie($token);
                return $t['user_id'];
            }
            self::forgetCookie();
        }
        return null;
    }

    /** Empreinte courte du mot de passe enregistré : change quand le mot de passe change. */
    public static function passwordVersion(array $user): string
    {
        return substr(hash('sha256', (string) $user['password_hash']), 0, 16);
    }

    /**
     * Nouveau mot de passe : les autres appareils sont déconnectés (sessions et « Rester connecté »),
     * celui-ci reste connecté.
     */
    public static function changePassword(string $userId, string $password): void
    {
        Repo::updateUser($userId, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        Repo::deleteUserTokens($userId, 'remember');
        $user = Repo::user($userId);
        if ($user && Session::get('uid') === $userId) {
            Session::regenerate();
            Session::set('pwv', self::passwordVersion($user));
            if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
                self::setCookie(Repo::createToken($userId, 'remember', self::REMEMBER_DAYS * 86400));
            }
        }
    }

    public static function isVerified(array $user): bool
    {
        return Repo::meta('unverified:' . $user['id']) === null;
    }

    public static function markVerified(string $userId): void
    {
        \SimpleCommerce\Db::run('DELETE FROM sc_meta WHERE name = ?', ['unverified:' . $userId]);
    }

    /**
     * Envoie le lien « Confirmez votre adresse » (valable 7 jours). Au plus 3 envois par heure.
     * @return ?string le lien en démonstration (pour l'afficher), sinon null
     */
    public static function sendVerification(array $user): ?string
    {
        if (Repo::tooManyAttempts('verify:' . $user['id'], 3, 3600)) {
            return null;
        }
        Repo::recordAttempt('verify:' . $user['id']);
        $link = Config::url('/verifier/' . Repo::createToken($user['id'], 'verify', 7 * 86400));
        Mailer::send($user['email'], 'Confirmez votre adresse e-mail — Simple Commerce',
            "Bonjour,\n\nBienvenue sur Simple Commerce ! Pour confirmer votre adresse e-mail, ouvrez ce lien :\n$link\n\n"
            . "Si vous n'avez pas créé de compte, ignorez ce message.");
        return Config::demo() ? $link : null;
    }

    /**
     * Lien de confirmation ouvert. L'adresse est confirmée, sans rien changer d'autre : le mot de passe reste,
     * et les appareils restent connectés (le lien s'ouvre souvent dans Safari, pas dans l'application qui a créé le compte).
     * Ouvert ailleurs que dans le navigateur de l'inscription : la page propose « Ce n'est pas moi qui ai créé ce compte »,
     * pour le cas où quelqu'un aurait utilisé l'adresse d'un autre (voir claimAccount).
     * @return ?array{next: string, claim: ?string} null si le lien n'est plus valable
     */
    public static function consumeVerification(string $token): ?array
    {
        $t = Repo::useToken($token, 'verify', true);
        $user = $t ? Repo::user($t['user_id']) : null;
        if (!$user) {
            return null;
        }
        self::markVerified($user['id']);
        if (Session::get('uid') === $user['id']) {
            return ['next' => '/sites/nouveau?verifie=1', 'claim' => null];
        }
        return ['next' => '', 'claim' => Repo::createToken($user['id'], 'claim', 7 * 86400)];
    }

    /**
     * « Ce n'est pas moi qui ai créé ce compte » : la personne qui reçoit les e-mails reprend le compte.
     * Ancien mot de passe annulé, tous les appareils déconnectés, puis elle choisit le sien.
     */
    public static function claimAccount(string $token): bool
    {
        $t = Repo::useToken($token, 'claim', true);
        $user = $t ? Repo::user($t['user_id']) : null;
        if (!$user) {
            return false;
        }
        Repo::updateUser($user['id'], ['password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
        Repo::deleteUserTokens($user['id'], 'remember');
        self::login(Repo::user($user['id']), false);
        Session::set('magic_at', time());
        return true;
    }

    /** Compte créé sans que l'adresse e-mail ait été prouvée (inscription libre). */
    public static function markUnverified(string $userId): void
    {
        Repo::setMeta('unverified:' . $userId, '1');
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
        Session::set('pwv', self::passwordVersion($user));
        Session::forget('assist');
        if ($remember) {
            $token = Repo::createToken($user['id'], 'remember', self::REMEMBER_DAYS * 86400);
            self::setCookie($token);
        }
        Repo::log($user['id'], null, null, 'login');
    }

    private static function secure(): bool
    {
        return str_starts_with(Config::url(), 'https://');
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::REMEMBER_COOKIE, $token, ['expires' => time() + self::REMEMBER_DAYS * 86400, 'path' => '/', 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax']);
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
        $ip = \SimpleCommerce\Http\Req::ipBucket();
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
        // Le lien prouve que la personne reçoit bien les e-mails de cette adresse. Si le compte avait été créé
        // sans cette preuve, quelqu'un d'autre a pu le créer avec son adresse : on retire alors l'ancien mot de passe
        // et on déconnecte tous les autres appareils, puis on lui fait choisir le sien.
        if (Repo::meta('unverified:' . $user['id']) !== null) {
            Repo::updateUser($user['id'], ['password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            Repo::deleteUserTokens($user['id'], 'remember');
            self::markVerified($user['id']);
            $user = Repo::user($user['id']);
            self::login($user, (bool) ($t['extra']['remember'] ?? false));
            Session::set('magic_at', time());
            return '/compte?nouveau-mot-de-passe=1';
        }
        self::login($user, (bool) ($t['extra']['remember'] ?? false));
        Session::set('magic_at', time());
        return (string) ($t['extra']['next'] ?? '/sites');
    }
}
