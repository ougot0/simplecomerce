<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;

/**
 * Publication programmée des brouillons.
 * Lancée par une tâche planifiée OVH (/cron?cle=…) et, en secours, au plus une fois par minute pendant les visites.
 * La personne qui a programmé reste l'auteur de la modification.
 */
final class Scheduler
{
    public static function maybeRun(): void
    {
        $last = (string) (Db::value("SELECT value FROM sc_meta WHERE name = 'scheduler_at'") ?? '');
        if ($last !== '' && strtotime($last) > time() - 60) {
            return;
        }
        self::run();
    }

    /** @return int nombre de brouillons publiés */
    public static function run(): int
    {
        $lock = @fopen(SC_STORAGE . '/scheduler.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        $done = 0;
        try {
            Db::run("DELETE FROM sc_meta WHERE name = 'scheduler_at'");
            Db::insert('sc_meta', ['name' => 'scheduler_at', 'value' => Db::now()]);
            foreach (Repo::dueDrafts(Db::now()) as $draft) {
                $site = Repo::site($draft['site_id']);
                $user = Repo::user($draft['updated_by']);
                if (!$site || !$user || $site['status'] !== 'active') {
                    continue;
                }
                $member = $user['is_admin'] || Repo::membership($site['id'], $user['id']);
                if (!$member) {
                    Repo::saveDraft(['publish_at' => null] + $draft);
                    continue;
                }
                $viewer = ['user' => $user, 'effective' => $user, 'assist' => null];
                $r = Changes::publishDraft($viewer, $site, $draft, Repo::schema($site['id']) ?? ['sections' => []]);
                if ($r['ok']) {
                    $done++;
                    Repo::log($user['id'], null, $site['id'], 'scheduled_published', ['label' => $draft['label']]);
                } else {
                    // On ne réessaie pas en boucle : le brouillon reste, sans date, et l'auteur est prévenu.
                    Repo::saveDraft(['publish_at' => null] + $draft);
                    Notifier::failedSchedule($site, $draft, $r['error']);
                }
            }
        } catch (\Throwable $e) {
            Log::error('publication programmée', ['err' => $e]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $done;
    }
}
