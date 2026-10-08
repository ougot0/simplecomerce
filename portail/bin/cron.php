<?php
declare(strict_types=1);

/*
 * Tâche planifiée : publie les brouillons programmés.
 * OVH : espace client → Hébergements → onglet « Plus » → « Tâches planifiées - Cron » → Ajouter :
 *   commande : simplecommerce/bin/cron.php (chemin depuis votre dossier principal), langage PHP 8.3, toutes les heures… ou plus souvent.
 * Ce dossier n'est pas accessible depuis Internet.
 * Ailleurs : php /chemin/vers/simplecommerce/bin/cron.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleCommerce\Config;
use SimpleCommerce\Services\Scheduler;

if (!Config::installed()) {
    fwrite(STDERR, "Simple Commerce n'est pas encore installé.\n");
    exit(1);
}
echo date('Y-m-d H:i') . ' — ' . Scheduler::run() . " brouillon(s) publié(s)\n";
