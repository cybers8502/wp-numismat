<?php

namespace Coins\Console;

use Coins\Prices\PriceSchema;
use WP_CLI;

/**
 * `wp coins install-schema` — create/upgrade the custom {prefix}coin_prices
 * table. Run once per deploy that bumps PriceSchema::VERSION; safe to re-run.
 */
class InstallSchemaCommand
{
    public static function register(): void
    {
        WP_CLI::add_command('coins install-schema', self::class, [
            'shortdesc' => 'Create or upgrade the custom coin_prices table',
            'synopsis'  => [
                [
                    'type'        => 'flag',
                    'name'        => 'force',
                    'optional'    => true,
                    'description' => 'Run dbDelta even if the stored schema version is already current',
                ],
            ],
        ]);
    }

    /**
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        global $wpdb;

        PriceSchema::install(isset($assoc_args['force']));

        $table = PriceSchema::table();

        if (!PriceSchema::exists()) {
            WP_CLI::error("Таблицю {$table} не створено — перевір DDL або права БД.");
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        WP_CLI::success(sprintf(
            'Таблиця %s готова (schema v%d, рядків: %d).',
            $table,
            PriceSchema::VERSION,
            $count
        ));
    }
}
