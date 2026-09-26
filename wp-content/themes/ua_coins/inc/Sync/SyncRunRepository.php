<?php

namespace Coins\Sync;

/**
 * One row per `wp nbu parse-souvenir` run in `{prefix}coin_sync_runs` — what the "Синхронізація
 * НБУ" admin page reports from. Created on demand (install() is a single option read once current),
 * so no separate deploy step. A run that dies mid-way (WP_CLI::error, fatal, killed cron) stays
 * `running` with no finished_at — which is exactly what the page should show for it.
 */
final class SyncRunRepository
{
    /** Bump on any DDL change below. */
    public const VERSION = 1;

    private const OPTION = 'coins_sync_runs_schema_version';
    private const TABLE  = 'coin_sync_runs';

    /** Counter columns, in display order. */
    public const COUNTERS = [
        'pages', 'seen', 'created', 'updated', 'skipped',
        'images_downloaded', 'images_failed', 'errors', 'pending_after', 'total_after',
    ];

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        if ((int) get_option(self::OPTION) === self::VERSION) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        // dbDelta is format-sensitive: one column per line, two spaces after "PRIMARY KEY".
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            started_at datetime NOT NULL,
            finished_at datetime NULL DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'running',
            forced tinyint(1) NOT NULL DEFAULT 0,
            pages int(10) unsigned NOT NULL DEFAULT 0,
            seen int(10) unsigned NOT NULL DEFAULT 0,
            created int(10) unsigned NOT NULL DEFAULT 0,
            updated int(10) unsigned NOT NULL DEFAULT 0,
            skipped int(10) unsigned NOT NULL DEFAULT 0,
            images_downloaded int(10) unsigned NOT NULL DEFAULT 0,
            images_failed int(10) unsigned NOT NULL DEFAULT 0,
            errors int(10) unsigned NOT NULL DEFAULT 0,
            pending_after int(10) unsigned NOT NULL DEFAULT 0,
            total_after int(10) unsigned NOT NULL DEFAULT 0,
            message text NULL,
            PRIMARY KEY  (id),
            KEY idx_started (started_at)
        ) {$collate};");

        update_option(self::OPTION, self::VERSION, false);
    }

    public static function exists(): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table())) === self::table();
    }

    public static function start(bool $forced): int
    {
        global $wpdb;

        self::install();
        $wpdb->insert(self::table(), [
            'started_at' => current_time('mysql'),
            'status'     => 'running',
            'forced'     => $forced ? 1 : 0,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,int> $counters keys from COUNTERS
     */
    public static function finish(int $id, string $status, array $counters, string $message = ''): void
    {
        global $wpdb;

        $row = array_intersect_key($counters, array_flip(self::COUNTERS));
        $wpdb->update(
            self::table(),
            $row + ['finished_at' => current_time('mysql'), 'status' => $status, 'message' => $message],
            ['id' => $id]
        );
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function recent(int $limit): array
    {
        global $wpdb;

        if (!self::exists()) {
            return [];
        }
        $table = self::table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed internal table name.
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: [];
    }

    /** @return array<int,array<string,mixed>> newest month first */
    public static function monthly(int $months): array
    {
        global $wpdb;

        if (!self::exists()) {
            return [];
        }
        $table = self::table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed internal table name.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(started_at, '%%Y-%%m') AS month,
                    COUNT(*) AS runs,
                    SUM(status = 'ok') AS ok_runs,
                    SUM(created) AS created,
                    SUM(updated) AS updated,
                    SUM(images_downloaded) AS images_downloaded,
                    SUM(images_failed) AS images_failed,
                    SUM(errors) AS errors
               FROM {$table}
              GROUP BY month
              ORDER BY month DESC
              LIMIT %d",
            $months
        ), ARRAY_A) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
