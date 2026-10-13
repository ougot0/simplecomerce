<?php
declare(strict_types=1);

namespace SimpleCommerce;

/**
 * Tables du portail. Écrites pour MySQL/MariaDB (hébergement) et SQLite (démonstration).
 * Les identifiants sont des UUID en texte ; les dates en texte ISO 8601 (UTC).
 */
final class Schema
{
    /** 2 : liste d'attente, commandes, avis, statistiques, abonnements (12/10/2026). */
    public const VERSION = 4;

    /** @return string[] */
    public static function statements(string $driver): array
    {
        $long = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        $id = 'VARCHAR(64)';
        $key = 'VARCHAR(191)';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $tables = [
            "CREATE TABLE IF NOT EXISTS sc_meta (name $key PRIMARY KEY, value TEXT NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_users (
                id $id PRIMARY KEY, email $key NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
                password_hash VARCHAR(255) NOT NULL, is_admin INTEGER NOT NULL DEFAULT 0,
                notify INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_sites (
                id $id PRIMARY KEY, slug $key NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
                public_url VARCHAR(500) NOT NULL DEFAULT '', connector VARCHAR(32) NOT NULL,
                config TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_by $id NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
                last_check_at VARCHAR(32) NULL, last_check_ok INTEGER NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_members (
                site_id $id NOT NULL, user_id $id NOT NULL, role VARCHAR(16) NOT NULL, created_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (site_id, user_id))$engine",
            "CREATE TABLE IF NOT EXISTS sc_credentials (
                site_id $id PRIMARY KEY, sealed TEXT NOT NULL, fingerprints TEXT NOT NULL,
                updated_at VARCHAR(32) NOT NULL, updated_by $id NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_schemas (
                id $id PRIMARY KEY, site_id $id NOT NULL, version INTEGER NOT NULL, definition $long NOT NULL,
                created_by $id NULL, created_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_changes (
                id $id PRIMARY KEY, site_id $id NOT NULL, actor_id $id NULL, on_behalf_of $id NULL,
                action VARCHAR(16) NOT NULL, section_key VARCHAR(120) NOT NULL, entry_id VARCHAR(255) NULL,
                entry_label VARCHAR(255) NOT NULL DEFAULT '', before_json $long NULL, after_json $long NULL,
                before_order TEXT NULL, after_order TEXT NULL, remote_ref VARCHAR(255) NULL,
                status VARCHAR(16) NOT NULL, error TEXT NULL, reverts_id $id NULL, reverted_by_id $id NULL,
                created_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_drafts (
                id $id PRIMARY KEY, site_id $id NOT NULL, section_key VARCHAR(120) NOT NULL, entry_id VARCHAR(255) NULL,
                label VARCHAR(255) NOT NULL DEFAULT '', data $long NOT NULL, base_data $long NULL,
                publish_at VARCHAR(32) NULL, created_by $id NULL, updated_by $id NULL,
                created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_media (
                id $id PRIMARY KEY, site_id $id NOT NULL, uploaded_by $id NULL, path VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL, mime VARCHAR(64) NOT NULL, bytes INTEGER NOT NULL,
                width INTEGER NOT NULL, height INTEGER NOT NULL, alt VARCHAR(300) NOT NULL DEFAULT '',
                public_value VARCHAR(1000) NULL, created_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_invitations (
                id $id PRIMARY KEY, site_id $id NOT NULL, email $key NOT NULL, role VARCHAR(16) NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE, invited_by $id NULL, created_at VARCHAR(32) NOT NULL,
                expires_at VARCHAR(32) NOT NULL, accepted_at VARCHAR(32) NULL, accepted_by $id NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_events (
                id $id PRIMARY KEY, at VARCHAR(32) NOT NULL, actor_id $id NULL, on_behalf_of $id NULL,
                site_id $id NULL, kind VARCHAR(64) NOT NULL, details TEXT NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_impersonations (
                id $id PRIMARY KEY, admin_id $id NOT NULL, target_id $id NOT NULL, started_at VARCHAR(32) NOT NULL,
                expires_at VARCHAR(32) NOT NULL, ended_at VARCHAR(32) NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_tokens (
                id $id PRIMARY KEY, user_id $id NOT NULL, kind VARCHAR(16) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at VARCHAR(32) NOT NULL, created_at VARCHAR(32) NOT NULL, extra TEXT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_attempts (id $id PRIMARY KEY, bucket $key NOT NULL, at VARCHAR(32) NOT NULL)$engine",
            // Version 2
            "CREATE TABLE IF NOT EXISTS sc_waitlist (
                id $id PRIMARY KEY, site_id $id NOT NULL, product_id VARCHAR(191) NOT NULL, product_name VARCHAR(255) NOT NULL DEFAULT '',
                email $key NOT NULL, created_at VARCHAR(32) NOT NULL, notified_at VARCHAR(32) NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_orders (
                id $id PRIMARY KEY, site_id $id NOT NULL, number INTEGER NOT NULL, status VARCHAR(16) NOT NULL,
                customer_name VARCHAR(120) NOT NULL, email $key NOT NULL, phone VARCHAR(40) NOT NULL DEFAULT '',
                address TEXT NOT NULL, note TEXT NOT NULL, items $long NOT NULL, total DOUBLE PRECISION NULL,
                created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_reviews (
                id $id PRIMARY KEY, site_id $id NOT NULL, product_id VARCHAR(191) NULL, author VARCHAR(80) NOT NULL,
                rating INTEGER NOT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL, reply TEXT NULL,
                created_at VARCHAR(32) NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS sc_hits (
                site_id $id NOT NULL, day VARCHAR(10) NOT NULL, kind VARCHAR(8) NOT NULL, item VARCHAR(191) NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (site_id, day, kind, item))$engine",
            "CREATE TABLE IF NOT EXISTS sc_billing (
                user_id $id PRIMARY KEY, status VARCHAR(16) NOT NULL, trial_ends_at VARCHAR(32) NULL,
                customer_id VARCHAR(191) NULL, subscription_id VARCHAR(191) NULL, period_end VARCHAR(32) NULL,
                updated_at VARCHAR(32) NOT NULL)$engine",
        ];
        $indexes = [
            'CREATE INDEX sc_members_user ON sc_members (user_id)',
            'CREATE INDEX sc_changes_site ON sc_changes (site_id, created_at)',
            'CREATE INDEX sc_drafts_site ON sc_drafts (site_id)',
            'CREATE INDEX sc_schemas_site ON sc_schemas (site_id, version)',
            'CREATE INDEX sc_events_at ON sc_events (at)',
            'CREATE INDEX sc_attempts_bucket ON sc_attempts (bucket, at)',
            'CREATE INDEX sc_waitlist_site ON sc_waitlist (site_id, product_id)',
            'CREATE INDEX sc_orders_site ON sc_orders (site_id, created_at)',
            'CREATE INDEX sc_reviews_site ON sc_reviews (site_id, status)',
        ];
        return [...$tables, ...$indexes];
    }

    public static function install(): void
    {
        $driver = Db::driver();
        foreach (self::statements($driver) as $sql) {
            try {
                Db::pdo()->exec($sql);
            } catch (\PDOException $e) {
                // Index déjà présent lors d'une réinstallation : sans gravité.
                if (!str_starts_with(trim($sql), 'CREATE INDEX')) {
                    throw $e;
                }
            }
        }
        Db::run($driver === 'mysql' ? 'REPLACE INTO sc_meta (name, value) VALUES (?, ?)' : 'INSERT OR REPLACE INTO sc_meta (name, value) VALUES (?, ?)', ['schema_version', (string) self::VERSION]);
    }

    /** Portail déjà en service avec une ancienne version : on ajoute les nouvelles tables (rien n'est supprimé). */
    public static function migrate(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if ((int) (Db::value("SELECT value FROM sc_meta WHERE name = 'schema_version'") ?? 0) < self::VERSION) {
            self::install();
            // 13/10/2026 : la première année d'abonnement devient gratuite (avant : 7 jours).
            $billing = json_decode((string) Repo::meta('billing_settings', '{}'), true) ?: [];
            if ($billing && (int) ($billing['trialDays'] ?? 0) < 365) {
                $billing['trialDays'] = 365;
                Repo::setMeta('billing_settings', json_encode($billing));
            }
            // Catalogues hébergés créés avant l'arrivée de nouveaux champs : on les ajoute.
            foreach (Repo::sites() as $site) {
                $schema = $site['connector'] === 'catalogue' ? Repo::schema($site['id']) : null;
                $up = $schema ? \SimpleCommerce\Adapters\Files\DbBackend::upgradeSchema($schema) : null;
                if ($up) {
                    Repo::saveSchema($site['id'], $up, null);
                }
            }
        }
    }

    public static function isInstalled(): bool
    {
        try {
            return Db::value("SELECT value FROM sc_meta WHERE name = 'schema_version'") !== null;
        } catch (\PDOException) {
            return false;
        }
    }
}
