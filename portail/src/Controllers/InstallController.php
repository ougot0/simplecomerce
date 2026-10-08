<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Config;
use SimpleCommerce\Db;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Schema;
use SimpleCommerce\Services\Auth;
use SimpleCommerce\Support\Session;

/**
 * Installation en une page, juste après l'envoi des fichiers par FTP :
 * base MySQL de l'hébergement, compte administrateur, clés de chiffrement générées ici.
 * Accessible uniquement tant que config.php n'existe pas.
 */
final class InstallController
{
    /** @return array<int, array{0: string, 1: bool, 2: string}> */
    public static function checks(): array
    {
        $writable = is_writable(SC_ROOT) || (is_file(Config::path()) && is_writable(Config::path()));
        return [
            ['PHP 8.1 ou plus récent', PHP_VERSION_ID >= 80100, 'Version actuelle : ' . PHP_VERSION . '. Chez OVH : fichier .ovhconfig, ligne app.engine.version=8.3.'],
            ['Chiffrement (sodium)', function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'), 'Extension sodium manquante : choisissez une version de PHP plus récente.'],
            ['Base MySQL (pdo_mysql)', extension_loaded('pdo_mysql'), 'Extension pdo_mysql manquante.'],
            ['Connexions sécurisées (curl)', extension_loaded('curl'), 'Extension curl manquante.'],
            ['Photos (GD avec WebP)', function_exists('imagewebp'), 'Extension GD sans WebP : les photos ne pourront pas être envoyées.'],
            ['Dossier storage/ inscriptible', is_dir(SC_STORAGE) && is_writable(SC_STORAGE), 'Donnez les droits d\'écriture (705 ou 755) au dossier storage/.'],
            ['Dossier du portail inscriptible (pour config.php)', $writable, 'Donnez les droits d\'écriture au dossier, le temps de l\'installation.'],
        ];
    }

    private static function guessUrl(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . preg_replace('/[^\w.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    public static function form(array $state = []): Response
    {
        Session::start();
        return Response::page('install', $state + ['checks' => self::checks(), 'values' => ['url' => self::guessUrl(), 'db_host' => '', 'db_name' => '', 'db_user' => '', 'name' => '', 'email' => '', 'mail_from' => ''], 'errors' => []], 'auth', $state ? 422 : 200);
    }

    public static function install(): Response
    {
        Session::start();
        if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
            return self::form(['error' => 'La page a expiré. Recommencez.']);
        }
        $v = [
            'url' => rtrim(Req::str('url', 200), '/'),
            'db_host' => Req::str('db_host', 200),
            'db_name' => Req::str('db_name', 100),
            'db_user' => Req::str('db_user', 100),
            'name' => Req::str('name', 120),
            'email' => mb_strtolower(Req::str('email', 200)),
            'mail_from' => mb_strtolower(Req::str('mail_from', 200)),
        ];
        $password = Req::raw('password');
        $errors = [];
        if (!preg_match('#^https?://[\w.\-]+(:\d+)?$#', $v['url'])) {
            $errors['url'] = 'Adresse du portail invalide, par exemple https://portail.mon-domaine.fr';
        }
        foreach (['db_host' => 'Serveur', 'db_name' => 'Nom de la base', 'db_user' => 'Utilisateur'] as $k => $l) {
            if ($v[$k] === '') {
                $errors[$k] = "$l : obligatoire.";
            }
        }
        if (mb_strlen($v['name']) < 2) {
            $errors['name'] = 'Indiquez votre nom.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if ($v['mail_from'] !== '' && !filter_var($v['mail_from'], FILTER_VALIDATE_EMAIL)) {
            $errors['mail_from'] = 'Adresse e-mail invalide.';
        }
        if ($p = Auth::passwordProblem($password)) {
            $errors['password'] = $p;
        }
        if (array_filter(self::checks(), fn ($c) => !$c[1] && $c[0] !== 'Photos (GD avec WebP)')) {
            return self::form(['error' => "L'hébergement ne remplit pas toutes les conditions (voir la liste).", 'values' => $v, 'errors' => $errors]);
        }
        if ($errors) {
            return self::form(['values' => $v, 'errors' => $errors]);
        }
        $db = ['driver' => 'mysql', 'host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'password' => Req::raw('db_password')];
        try {
            Db::use($db);
            Schema::install();
        } catch (\Throwable $e) {
            return self::form(['error' => 'Connexion à la base impossible : vérifiez le serveur, le nom de la base, l\'utilisateur et le mot de passe (espace client OVH → Hébergements → Bases de données). Détail : ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160), 'values' => $v]);
        }
        if (Repo::userByEmail($v['email'])) {
            return self::form(['error' => 'Cette base contient déjà une installation de Simple Commerce. Utilisez une base vide, ou restaurez le fichier config.php d\'origine.', 'values' => $v]);
        }
        Config::write([
            'url' => $v['url'],
            'db' => $db,
            'key' => base64_encode(random_bytes(32)),
            'secret' => bin2hex(random_bytes(32)),
            'cron_key' => bin2hex(random_bytes(16)),
            'mail_from' => $v['mail_from'] ?: 'ne-pas-repondre@' . (parse_url($v['url'], PHP_URL_HOST) ?: 'localhost'),
            'debug' => false,
        ]);
        $admin = Repo::createUser($v['email'], $v['name'], $password, true);
        Auth::login($admin, true);
        Session::flash('Simple Commerce est installé. Gardez une copie du fichier config.php : sans lui, les accès enregistrés ne peuvent pas être relus.');
        return Response::redirect('/admin');
    }
}
