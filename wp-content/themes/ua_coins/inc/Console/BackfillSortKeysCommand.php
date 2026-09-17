<?php

namespace Coins\Console;

use Coins\Catalog\SortKeyService;
use WP_CLI;

/**
 * `wp coins backfill-sort-keys` — writes the denormalised catalog sort keys (`_sort_title`,
 * `_sort_denomination`, `_sort_material`, `_sort_quality`, `_sort_mintage`, `_sort_price`) for
 * every published coin.
 *
 * SortKeyService keeps these current from then on (post save, term change, price import), so this
 * command exists for the ~1.1k coins that predate it. **Until it has run, the catalog table's
 * server-side sort is a no-op for every column but Рік**: WP orders by `meta_key`, which INNER
 * JOINs postmeta, so coins with no key row simply would not come back. Run it right after
 * deploying the theme.
 *
 * Idempotent — `update_post_meta` skips the write when the value is unchanged, so re-running is
 * cheap and safe.
 */
class BackfillSortKeysCommand
{
    private const DEFAULT_BATCH = 500;

    public static function register(): void
    {
        WP_CLI::add_command('coins backfill-sort-keys', self::class, [
            'shortdesc' => 'Write the catalog sort-key meta for every published coin',
            'synopsis'  => [
                [
                    'type'        => 'assoc',
                    'name'        => 'batch',
                    'optional'    => true,
                    'description' => 'Posts to process per pass (default ' . self::DEFAULT_BATCH . ')',
                ],
            ],
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
        global $wpdb;

        $batch   = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : self::DEFAULT_BATCH;
        $service = new SortKeyService();

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            SortKeyService::POST_TYPE
        ));

        if ($total === 0) {
            WP_CLI::warning('Опублікованих монет не знайдено — нічого робити.');

            return;
        }

        WP_CLI::log(sprintf('Монет (published): %d', $total));

        $done   = 0;
        $offset = 0;

        while (true) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type = %s AND post_status = 'publish'
                  ORDER BY ID ASC
                  LIMIT %d OFFSET %d",
                SortKeyService::POST_TYPE,
                $batch,
                $offset
            ));

            if (!$ids) {
                break;
            }

            foreach ($ids as $id) {
                $service->rebuild((int) $id);
                $done++;
            }

            $offset += $batch;

            WP_CLI::log(sprintf('  ...%d/%d', min($done, $total), $total));

            // The term/meta caches grow unboundedly across a full-catalog pass otherwise.
            wp_cache_flush();
        }

        WP_CLI::success(sprintf('Ключі сортування оновлено для %d монет.', $done));
    }
}
