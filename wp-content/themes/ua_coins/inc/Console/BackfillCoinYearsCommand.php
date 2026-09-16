<?php

namespace Coins\Console;

use WP_CLI;

/**
 * `wp coins backfill-years` — assigns each published coin its `coin_year` term, derived from the
 * year of the `issue_date` ACF field.
 *
 * FetchNbuDataCommand does this inline for every coin it imports or updates, so this command only
 * exists for the posts that predate the taxonomy. Idempotent: a coin that already carries the
 * right term is left untouched (no write), so re-running is cheap and safe.
 *
 * Coins without a parseable `issue_date` are reported and skipped rather than guessed at — their
 * `post_date` is the import timestamp for manually created posts, not an issue date, and a wrong
 * year here would silently mis-file a coin in the catalog's year filter.
 */
class BackfillCoinYearsCommand
{
    private const TAXONOMY      = 'coin_year';
    private const POST_TYPE     = 'coins';
    private const META_KEY      = 'issue_date';
    private const DEFAULT_BATCH = 500;

    public static function register(): void
    {
        WP_CLI::add_command('coins backfill-years', self::class, [
            'shortdesc' => 'Assign the coin_year term to every published coin from its issue_date',
            'synopsis'  => [
                [
                    'type'        => 'assoc',
                    'name'        => 'batch',
                    'optional'    => true,
                    'description' => 'Posts to read per pass (default ' . self::DEFAULT_BATCH . ')',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'optional'    => true,
                    'description' => 'Report what would change; do not write any terms',
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

        if (!taxonomy_exists(self::TAXONOMY)) {
            WP_CLI::error(sprintf(
                'Таксономія %s не зареєстрована — оновіть тему (CoinPostTypeRegistrar) перед запуском.',
                self::TAXONOMY
            ));
        }

        $dryRun = isset($assoc_args['dry-run']);
        $batch  = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : self::DEFAULT_BATCH;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            self::POST_TYPE
        ));

        WP_CLI::log(sprintf('Монет (published): %d%s', $total, $dryRun ? ' [DRY RUN]' : ''));

        $offset    = 0;
        $scanned   = 0;
        $assigned  = 0;
        $unchanged = 0;
        $noDate    = 0;
        $byYear    = [];

        while ($offset < $total) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, pm.meta_value AS issue_date
                   FROM {$wpdb->posts} p
                   LEFT JOIN {$wpdb->postmeta} pm
                          ON pm.post_id = p.ID AND pm.meta_key = %s
                  WHERE p.post_type = %s AND p.post_status = 'publish'
                  ORDER BY p.ID
                  LIMIT %d OFFSET %d",
                self::META_KEY,
                self::POST_TYPE,
                $batch,
                $offset
            ));

            if (!$rows) {
                break;
            }

            $current = $this->currentYears(array_map(static fn ($r) => (int) $r->ID, $rows));

            foreach ($rows as $row) {
                $scanned++;
                $postId = (int) $row->ID;
                $year   = FetchNbuDataCommand::year_from_date($row->issue_date);

                if ($year === null) {
                    $noDate++;
                    WP_CLI::warning(sprintf(
                        '  пропуск #%d «%s» — немає issue_date (%s)',
                        $postId,
                        get_the_title($postId),
                        $row->issue_date === null ? 'відсутнє' : (string) $row->issue_date
                    ));
                    continue;
                }

                $byYear[$year] = ($byYear[$year] ?? 0) + 1;

                if (($current[$postId] ?? null) === $year) {
                    $unchanged++;
                    continue;
                }

                if (!$dryRun) {
                    $result = wp_set_post_terms($postId, [$year], self::TAXONOMY, false);
                    if (is_wp_error($result)) {
                        WP_CLI::warning(sprintf(
                            '  #%d — не вдалось призначити «%s»: %s',
                            $postId,
                            $year,
                            $result->get_error_message()
                        ));
                        continue;
                    }
                }

                $assigned++;
            }

            $offset += $batch;
            WP_CLI::log(sprintf('  ...%d/%d', min($offset, $total), $total));
        }

        if ($byYear) {
            ksort($byYear);
            WP_CLI::log('За роками: ' . implode(', ', array_map(
                static fn ($year, $n) => "{$year}={$n}",
                array_keys($byYear),
                $byYear
            )));
        }

        WP_CLI::success(sprintf(
            'Оброблено: %d, %s: %d, вже коректні: %d, без issue_date: %d%s',
            $scanned,
            $dryRun ? 'потребують запису' : 'призначено',
            $assigned,
            $unchanged,
            $noDate,
            $dryRun ? ' [DRY RUN]' : ''
        ));
    }

    /**
     * Existing coin_year term name per post, for a batch of post IDs — so an already-correct coin
     * can be skipped without a write. One query per batch rather than one per post.
     *
     * Reads the term relationships directly rather than via wp_get_object_terms(..., 'fields' =>
     * 'all_with_object_id'): that form returns the post ID as a dynamic `object_id` property WP
     * bolts onto WP_Term at runtime, which isn't part of the class and so isn't type-safe to read.
     *
     * @param  array<int,int> $postIds
     * @return array<int,string>
     */
    private function currentYears(array $postIds): array
    {
        global $wpdb;

        if (!$postIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated "%d,%d,…" list, never user data; the IDs themselves are passed through prepare() below. A variable-length IN () has no other expressible form.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, t.name
               FROM {$wpdb->term_relationships} tr
               JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = %s AND tr.object_id IN ({$placeholders})",
            array_merge([self::TAXONOMY], $postIds)
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(int) $row->object_id] = (string) $row->name;
        }

        return $byPost;
    }
}
