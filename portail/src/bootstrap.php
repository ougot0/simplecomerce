<?php
declare(strict_types=1);

/**
 * Démarrage commun : chargement des classes, configuration, erreurs, en-têtes de sécurité.
 */

define('SC_ROOT', dirname(__DIR__));
define('SC_STORAGE', SC_ROOT . '/storage');

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
        . 'Simple Commerce n\'a pas pu démarrer. Le détail a été noté dans le fichier <b>storage/erreurs.txt</b> du portail : '
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
