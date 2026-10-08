<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Mailer;

/**
 * Avis par e-mail aux autres personnes d'un site quand quelque chose change.
 * Au plus un e-mail par personne et par site toutes les 30 minutes ; chacun peut couper ces avis dans « Mon compte ».
 */
final class Notifier
{
    private const QUIET_SECONDS = 1800;

    public static function changed(array $viewer, array $site, array $change): void
    {
        $skip = [$viewer['user']['id'], $viewer['effective']['id']];
        $who = $viewer['effective']['name'] ?: $viewer['effective']['email'];
        foreach (Repo::members($site['id']) as $m) {
            $u = $m['user'] ?? null;
            if (!$u || in_array($u['id'], $skip, true) || !$u['notify']) {
                continue;
            }
            $bucket = 'notify:' . $site['id'] . ':' . $u['id'];
            if (Repo::tooManyAttempts($bucket, 1, self::QUIET_SECONDS)) {
                continue;
            }
            Repo::recordAttempt($bucket);
            Mailer::send($u['email'], "{$site['name']} : une modification vient d'être publiée",
                "Bonjour {$u['name']},\n\n$who vient de modifier le site {$site['name']} :\n« {$change['entry_label']} »\n\n"
                . "Voir l'historique (et annuler si besoin) :\n" . Config::url('/s/' . $site['slug'] . '/historique')
                . "\n\nVous recevez au plus un message toutes les 30 minutes. Pour ne plus en recevoir : " . Config::url('/compte'));
        }
    }

    public static function failedSchedule(array $site, array $draft, string $error): void
    {
        $u = Repo::user($draft['updated_by']);
        if (!$u) {
            return;
        }
        Mailer::send($u['email'], "{$site['name']} : la publication programmée n'a pas pu se faire",
            "Bonjour {$u['name']},\n\nLa modification « {$draft['label']} » devait être publiée automatiquement, mais cela n'a pas fonctionné :\n$error\n\n"
            . "Elle est toujours dans vos brouillons :\n" . Config::url('/s/' . $site['slug'] . '/brouillons'));
    }
}
