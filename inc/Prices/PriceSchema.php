<?php

namespace Coins\Prices;

/**
 * Owns the {prefix}coin_prices table — the storage that replaces the coin_price
 * CPT (see inc/Console/README.md and the migration plan). Bump VERSION on any
 * column/index change; install() is idempotent and cheap to call when the
 * stored version already matches (a single option read).
 */
class PriceSchema
{
    /** Bump on any DDL change below. */
    public const VERSION = 1;

    private const OPTION = 'coins_price_schema_version';
    private const TABLE  = 'coin_prices';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Create or upgrade the table when the stored schema version is behind.
     * Pass $force to run dbDelta regardless (`wp coins install-schema --force`).
     */
    public static function install(bool $force = false): void
    {
        if (!$force && (int) get_option(self::OPTION) === self::VERSION) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        // dbDelta is format-sensitive: one column per line, two spaces after
        // "PRIMARY KEY", index columns with no space after the comma.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            coin_id bigint(20) unsigned NOT NULL,
            source varchar(32) NOT NULL,
            price_date date NOT NULL,
            price decimal(12,2) NOT NULL,
            sku varchar(32) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_point (coin_id,source,price_date),
            KEY idx_coin_date (coin_id,price_date)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option(self::OPTION, self::VERSION, false);
    }
}
