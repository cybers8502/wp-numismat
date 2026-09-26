<?php

namespace Coins\Console;

use Coins\Sync\SyncStatus;
use WP_CLI;

/**
 * `wp coins backfill-sync-status` — computes the "Оновлювати з НБУ" flag (SyncStatus) for every
 * coin from its current data, without importing anything. Run once after deploying SyncStatus:
 * until then every coin counts as pending and the next nightly import would re-write all of them.
 *
 * Re-running recomputes every flag, so it also resets checkboxes an admin set by hand.
 */
class BackfillSyncStatusCommand
{
    public static function register(): void
    {
        WP_CLI::add_command('coins backfill-sync-status', self::class, [
            'shortdesc' => 'Compute the NBU sync flag for every coin from its current data',
        ]);
    }

    /**
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        $ids = get_posts([
            'post_type'      => 'coins',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($ids as $id) {
            SyncStatus::evaluate((int) $id);
        }

        $counts = SyncStatus::counts();
        WP_CLI::log(sprintf('Монет: %d, очікують оновлення: %d', $counts['total'], $counts['pending']));
        foreach (SyncStatus::reasonLabels() as $key => $label) {
            WP_CLI::log(sprintf('  бракує «%s»: %d', $label, $counts['reasons'][$key]));
        }
        WP_CLI::success('Прапорці синхронізації пораховано.');
    }
}
