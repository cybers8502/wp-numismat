<?php

namespace Coins\Console;

use Coins\Prices\PriceRepository;
use Coins\Prices\PriceSchema;
use WP_CLI;

/**
 * `wp coins migrate-prices` — one-time backfill of the coin_price CPT into the
 * custom {prefix}coin_prices table (migration phase 2). Idempotent: the table's
 * UNIQUE (coin_id, source, price_date) key means re-runs update in place rather
 * than duplicating. Never touches the CPT.
 */
class MigratePricesCommand
{
    private const DEFAULT_BATCH = 1000;

    public static function register(): void
    {
        WP_CLI::add_command('coins migrate-prices', self::class, [
            'shortdesc' => 'Backfill the coin_price CPT into the custom coin_prices table',
            'synopsis'  => [
                [
                    'type'        => 'assoc',
                    'name'        => 'batch',
                    'optional'    => true,
                    'description' => 'Posts to read per pass (default ' . self::DEFAULT_BATCH . ')',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'verify',
                    'optional'    => true,
                    'description' => 'After migrating, compare CPT vs table per source. With --dry-run: verify only, no writes.',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'deep',
                    'optional'    => true,
                    'description' => 'With --verify: also scan every table row for a matching CPT post (slow on large data)',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'optional'    => true,
                    'description' => 'Read and validate only; do not write to the table',
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

        if (!PriceSchema::exists()) {
            WP_CLI::error('Таблиця відсутня — спершу `wp coins install-schema`.');
        }

        $dry_run = isset($assoc_args['dry-run']);
        $verify  = isset($assoc_args['verify']);
        $deep    = isset($assoc_args['deep']);
        $batch   = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : self::DEFAULT_BATCH;

        if ($dry_run && $verify) {
            WP_CLI::log('Лише перевірка (--dry-run --verify), без міграції.');
            $this->verify($deep);
            return;
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'coin_price' AND post_status = 'publish'"
        );

        WP_CLI::log(sprintf('coin_price постів: %d%s', $total, $dry_run ? ' [DRY RUN]' : ''));

        $repo     = new PriceRepository();
        $offset   = 0;
        $scanned  = 0;
        $written  = 0;
        $skipped  = 0;
        $bySource = [];

        while ($offset < $total) {
            // One-shot migration on a quiet table, so LIMIT/OFFSET over a stable
            // ORDER BY p.ID is fine — no rows are being added underneath us.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID,
                        MAX(CASE WHEN pm.meta_key = 'coin_id'          THEN pm.meta_value END) AS coin_id,
                        MAX(CASE WHEN pm.meta_key = 'price_date'       THEN pm.meta_value END) AS price_date,
                        MAX(CASE WHEN pm.meta_key = 'price'            THEN pm.meta_value END) AS price,
                        MAX(CASE WHEN pm.meta_key = 'source'           THEN pm.meta_value END) AS source,
                        MAX(CASE WHEN pm.meta_key = '_nbu_archive_sku' THEN pm.meta_value END) AS sku
                   FROM {$wpdb->posts} p
                   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                  WHERE p.post_type = 'coin_price' AND p.post_status = 'publish'
                  GROUP BY p.ID
                  ORDER BY p.ID
                  LIMIT %d OFFSET %d",
                $batch,
                $offset
            ));

            if (!$rows) {
                break;
            }

            $payload = [];
            foreach ($rows as $r) {
                $scanned++;
                $row = $this->normalise($r);
                if ($row === null) {
                    $skipped++;
                    WP_CLI::warning(sprintf(
                        '  пропуск post #%d — некоректні дані (coin_id=%s date=%s price=%s source=%s)',
                        $r->ID,
                        $r->coin_id,
                        $r->price_date,
                        $r->price,
                        $r->source
                    ));
                    continue;
                }
                $payload[]                  = $row;
                $bySource[$row['source']]   = ($bySource[$row['source']] ?? 0) + 1;
            }

            if ($payload && !$dry_run) {
                $repo->upsertBatch($payload);
            }
            $written += count($payload);

            $offset += $batch;
            WP_CLI::log(sprintf('  ...%d/%d', min($offset, $total), $total));
        }

        if ($bySource) {
            ksort($bySource);
            WP_CLI::log('За джерелом: ' . implode(', ', array_map(
                static fn ($src, $n) => "{$src}={$n}",
                array_keys($bySource),
                $bySource
            )));
        }

        WP_CLI::success(sprintf(
            'Оброблено: %d, %s: %d, пропущено (некоректні): %d%s',
            $scanned,
            $dry_run ? 'придатних' : 'записано',
            $written,
            $skipped,
            $dry_run ? ' [DRY RUN]' : ''
        ));

        if ($verify) {
            WP_CLI::log('');
            $this->verify($deep);
        }
    }

    /**
     * @return array{coin_id:int,source:string,price_date:string,price:float,sku:string}|null
     */
    private function normalise(object $r): ?array
    {
        $coinId = (int) $r->coin_id;
        $source = trim((string) ($r->source ?? ''));
        $date   = trim((string) ($r->price_date ?? ''));
        $price  = $r->price;

        if ($coinId <= 0 || $source === '' || $date === '' || !is_numeric($price)) {
            return null;
        }

        // ACF date_picker stores Ymd; the importers also accept Y-m-d.
        if (preg_match('~^\d{8}$~', $date)) {
            $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
            return null;
        }

        return [
            'coin_id'    => $coinId,
            'source'     => $source,
            'price_date' => $date,
            'price'      => (float) $price,
            'sku'        => $r->sku !== null ? (string) $r->sku : '',
        ];
    }

    /**
     * Compare the CPT and the table per source.
     *
     * The canonical CPT figure is the number of distinct
     * (coin_id, price_date) pairs among *migratable* posts — those with a
     * coin_id, a price_date and a non-empty price. That is exactly what the
     * table can hold for a source given its UNIQUE key. Posts that are
     * duplicates of a pair, or missing a price, are excluded here just as the
     * migration skips or collapses them — so a clean run reports no mismatch
     * even when the raw post count is higher.
     *
     * With $deep, every table row is additionally traced back to a CPT post
     * with the same coin/source/date and an equal price (a full scan).
     */
    private function verify(bool $deep): void
    {
        global $wpdb;
        $table = PriceSchema::table();

        $cpt = $wpdb->get_results(
            "SELECT s.meta_value AS source,
                    COUNT(DISTINCT p.ID) AS posts,
                    COUNT(DISTINCT CASE WHEN c.meta_value IS NOT NULL
                                        AND d.meta_value IS NOT NULL
                                        AND pr.meta_value IS NOT NULL
                                        AND pr.meta_value <> ''
                          THEN p.ID END) AS priced_posts,
                    COUNT(DISTINCT CASE WHEN c.meta_value IS NOT NULL
                                        AND d.meta_value IS NOT NULL
                                        AND pr.meta_value IS NOT NULL
                                        AND pr.meta_value <> ''
                          THEN CONCAT_WS('|', c.meta_value, d.meta_value) END) AS pairs,
                    COUNT(DISTINCT CASE WHEN c.meta_value IS NOT NULL
                                        AND d.meta_value IS NOT NULL
                                        AND pr.meta_value IS NOT NULL
                                        AND pr.meta_value <> ''
                          THEN c.meta_value END) AS coins
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} s  ON s.post_id  = p.ID AND s.meta_key  = 'source'
               LEFT JOIN {$wpdb->postmeta} c  ON c.post_id  = p.ID AND c.meta_key  = 'coin_id'
               LEFT JOIN {$wpdb->postmeta} d  ON d.post_id  = p.ID AND d.meta_key  = 'price_date'
               LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = 'price'
              WHERE p.post_type = 'coin_price' AND p.post_status = 'publish'
              GROUP BY s.meta_value",
            OBJECT_K
        );

        $tbl = $wpdb->get_results(
            "SELECT source,
                    COUNT(*) AS rows_n,
                    COUNT(DISTINCT coin_id) AS coins
               FROM {$table}
              GROUP BY source",
            OBJECT_K
        );

        $sources = array_values(array_unique(array_merge(array_keys($cpt), array_keys($tbl))));
        sort($sources);

        WP_CLI::log('Перевірка (CPT → таблиця):');
        WP_CLI::log(sprintf('  %-22s %10s %10s %8s   %s', 'source', 'cpt pairs', 'table', 'dCoins', 'status'));

        $all_ok = true;
        foreach ($sources as $src) {
            $cPairs = isset($cpt[$src]) ? (int) $cpt[$src]->pairs : 0;
            $cPosts = isset($cpt[$src]) ? (int) $cpt[$src]->posts : 0;
            $cPrPo  = isset($cpt[$src]) ? (int) $cpt[$src]->priced_posts : 0;
            $cCoins = isset($cpt[$src]) ? (int) $cpt[$src]->coins : 0;
            $tRows  = isset($tbl[$src]) ? (int) $tbl[$src]->rows_n : 0;
            $tCoins = isset($tbl[$src]) ? (int) $tbl[$src]->coins : 0;

            $coins_delta = $tCoins - $cCoins;
            $row_ok      = $cPairs === $tRows && $coins_delta === 0;
            $all_ok      = $all_ok && $row_ok;

            WP_CLI::log(sprintf(
                '  %-22s %10d %10d %8d   %s',
                $src,
                $cPairs,
                $tRows,
                $coins_delta,
                $row_ok ? 'OK' : 'MISMATCH'
            ));

            if ($cPosts !== $cPairs) {
                WP_CLI::log(sprintf(
                    '    (i) %s: %d постів → %d придатних трійок (дублі/без ціни виключено)',
                    $src,
                    $cPosts,
                    $cPairs
                ));
            }
        }

        if ($deep) {
            $orphans = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                   FROM {$table} tp
                  WHERE NOT EXISTS (
                        SELECT 1
                          FROM {$wpdb->posts} p
                          JOIN {$wpdb->postmeta} c  ON c.post_id  = p.ID AND c.meta_key = 'coin_id'    AND c.meta_value = tp.coin_id
                          JOIN {$wpdb->postmeta} s  ON s.post_id  = p.ID AND s.meta_key = 'source'     AND s.meta_value = tp.source
                          JOIN {$wpdb->postmeta} d  ON d.post_id  = p.ID AND d.meta_key = 'price_date'
                          JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = 'price'
                         WHERE p.post_type = 'coin_price' AND p.post_status = 'publish'
                           AND (d.meta_value = tp.price_date OR d.meta_value = REPLACE(tp.price_date, '-', ''))
                           AND ABS(pr.meta_value + 0 - tp.price) < 0.005
                  )"
            );

            WP_CLI::log(sprintf('  deep: рядків таблиці без відповідного CPT-поста: %d', $orphans));
            $all_ok = $all_ok && $orphans === 0;
        }

        if ($all_ok) {
            WP_CLI::success('Перевірка пройдена — таблиця відповідає CPT.');
        } else {
            WP_CLI::warning('Є розбіжності (рядки MISMATCH вище).');
        }
    }
}
