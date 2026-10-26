<?php
declare(strict_types=1);

/**
 * Démarrage commun : chargement des classes, configuration, erreurs, en-têtes de sécurité.
 */

define('SC_ROOT', dirname(__DIR__));

/**
 * Dossier des données (comptes, base, réglages, config.php, sauvegardes) : rangé À CÔTÉ du dossier du portail,
 * dans « simplecommerce-donnees ». Ainsi, remplacer tout le dossier du portail lors d'une mise à jour ne
 * peut plus effacer les comptes. Au premier passage, les données de l'ancien emplacement y sont déménagées.
 * En démonstration (et si le dossier voisin ne peut pas être créé), on garde l'ancien emplacement.
 */
function sc_data_dir(): string
{
    if (getenv('SC_DATA')) {
        return rtrim((string) getenv('SC_DATA'), '/');
    }
    if (getenv('SC_DEMO') === '1') {
        return SC_ROOT;
    }
    $dir = dirname(SC_ROOT) . '/simplecommerce-donnees';
    if (!is_dir($dir) && !@mkdir($dir, 0750) && !is_dir($dir)) {
        return SC_ROOT;
    }
    if (is_file("$dir/config.php")) {
        // Données déjà déménagées : on ne revient JAMAIS à l'ancien emplacement (sinon le portail se réinstallerait vide).
        if (!is_readable("$dir/config.php")) {
            http_response_code(503);
            exit('Simple Commerce : le dossier simplecommerce-donnees n\'est pas lisible. Remettez-lui ses droits (705) avec WinSCP.');
        }
        return $dir;
    }
    if (!is_writable($dir)) {
        return SC_ROOT;
    }
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
    }
    // Déménagement depuis l'ancien emplacement (dans le dossier du portail), une seule fois.
    if (!is_file("$dir/config.php") && is_file(SC_ROOT . '/config.php')) {
        @rename(SC_ROOT . '/config.php', "$dir/config.php");
    }
    if (!is_dir("$dir/storage")) {
        if (is_file(SC_ROOT . '/storage/simplecommerce.sqlite') && @rename(SC_ROOT . '/storage', "$dir/storage")) {
            @mkdir(SC_ROOT . '/storage', 0750);
            @file_put_contents(SC_ROOT . '/storage/.htaccess', "Require all denied\nDeny from all\n");
        } else {
            @mkdir("$dir/storage", 0750);
        }
    }
    return $dir;
}

define('SC_DATA', sc_data_dir());
define('SC_STORAGE', SC_DATA . '/storage');
/** Version affichée dans l'administration : sert à vérifier qu'une mise à jour est bien en ligne. */
define('SC_VERSION', '26 octobre 2026 — W');

// Erreur grave de PHP (fichier manquant ou abîmé…) : le serveur n'afficherait qu'une page « Erreur interne ».
// On note le détail dans storage/erreurs.txt (à envoyer à la personne qui s'occupe du portail) et on le dit simplement.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    $file = str_replace(SC_ROOT . '/', '', (string) $e['file']);
    @file_put_contents(SC_STORAGE . '/erreurs.txt', date('Y-m-d H:i:s') . ' — ' . $e['message'] . ' — ' . $file . ':' . $e['line'] . "\n", FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Simple Commerce</title><p style="font-family:sans-serif;max-width:40em;margin:3em auto">'
        . 'Simple Commerce n\'a pas pu démarrer. Le détail a été noté dans le fichier <b>erreurs.txt</b> (dossier simplecommerce-donnees/storage) : '
        . 'récupérez-le avec WinSCP et envoyez-le à la personne qui s\'occupe du portail.</p>';
});

require SC_ROOT . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

use SimpleCommerce\Config;
use SimpleCommerce\Support\Log;

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');

Config::load();

ini_set('display_errors', Config::get('debug') ? '1' : '0');
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

if (!is_dir(SC_STORAGE)) {
    @mkdir(SC_STORAGE, 0750, true);
}
