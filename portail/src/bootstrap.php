<?php
declare(strict_types=1);

/**
 * Démarrage commun : chargement des classes, configuration, erreurs, en-têtes de sécurité.
 */

define('SC_ROOT', dirname(__DIR__));
define('SC_STORAGE', SC_ROOT . '/storage');

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
