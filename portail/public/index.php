<?php
declare(strict_types=1);

/*
 * Simple Commerce — toutes les pages passent par ce fichier.
 * Le dossier public/ est le seul visible depuis Internet : le code, la configuration et les données restent à côté.
 */

// Serveur de développement intégré à PHP : les fichiers existants (styles, scripts) sont servis tels quels.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

\SimpleCommerce\Http\App::run();
