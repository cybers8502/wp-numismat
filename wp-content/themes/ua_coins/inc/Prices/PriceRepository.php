<?php

namespace Coins\Prices;

/**
 * Read/write access to {prefix}coin_prices. The read shape is exactly the array
 * CoinGraphQL::fetchPriceEntries() has always returned, so phase 3 of the
 * migration can swap the resolver's storage without touching its callers.
 */
class PriceRepository
{
    /** Max tuples per INSERT ... ON DUPLICATE KEY statement. */
    private const UPSERT_CHUNK = 400;

    /**
     * All price points for one coin, oldest first.
     *
     * @return list<array{id:int,date:string,price:float,source:?string}>
     */
    public function forCoin(int $coinId): array
    {
        global $wpdb;

        $table = PriceSchema::table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed internal identifier (PriceSchema::table()), not user input; %s/%i placeholders don't support table names anyway.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, price_date, price, source
                   FROM {$table}
                  WHERE coin_id = %d
                  ORDER BY price_date ASC, id ASC",
                $coinId
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map([self::class, 'hydrate'], $rows ?: []);
    }

    /**
     * Same as forCoin() but for many coins at once — every requested id is a key
     * in the result (with an empty list when it has no prices).
     *
     * @param  list<int> $coinIds
     * @return array<int, list<array{id:int,date:string,price:float,source:?string}>>
     */
    public function forCoins(array $coinIds): array
    {
        $coinIds = array_values(array_unique(array_map('intval', $coinIds)));
        $out     = array_fill_keys($coinIds, []);

        if (!$coinIds) {
            return $out;
        }

        global $wpdb;

        $table        = PriceSchema::table();
        $placeholders = implode(',', array_fill(0, count($coinIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a fixed internal identifier; $placeholders is a generated string of literal %d placeholders (one per element of $coinIds), not a value, and is itself passed through prepare() below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, coin_id, price_date, price, source
                   FROM {$table}
                  WHERE coin_id IN ({$placeholders})
                  ORDER BY price_date ASC, id ASC",
                $coinIds
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        foreach ($rows ?: [] as $row) {
            $out[(int) $row['coin_id']][] = self::hydrate($row);
        }

        return $out;
    }

    /**
     * Insert price points, updating price/sku on a
     * (coin_id, source, price_date) collision. Idempotent.
     *
     * @param  list<array{coin_id:int,source:string,price_date:string,price:float,sku?:?string}> $rows
     * @return int number of rows sent to the database (not a created/updated tally)
     */
    public function upsertBatch(array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        global $wpdb;

        $table = PriceSchema::table();
        $sent  = 0;

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $tuples = implode(',', array_fill(0, count($chunk), '(%d,%s,%s,%f,%s)'));

            $values = [];
            foreach ($chunk as $r) {
                $values[] = (int) $r['coin_id'];
                $values[] = (string) $r['source'];
                $values[] = self::toSqlDate((string) $r['price_date']);
                $values[] = (float) $r['price'];
                $values[] = (string) ($r['sku'] ?? '');
            }

            $sql = "INSERT INTO {$table} (coin_id, source, price_date, price, sku)
                    VALUES {$tuples}
                    ON DUPLICATE KEY UPDATE
                        price = VALUES(price),
                        sku   = VALUES(sku)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed-shape INSERT built from a constant table name and a repeated %d,%s,%s,%f,%s tuple string; the actual values are all passed through prepare() below.
            if ($wpdb->query($wpdb->prepare($sql, $values)) !== false) {
                $sent += count($chunk);
            }
        }

        // Lets SortKeyService refresh `_sort_price` for every coin whose latest price may have
        // moved. The catalog table orders on that meta, so without this an import would leave the
        // "Вартість" column sorted on the prices it held before the run.
        if ($sent > 0) {
            do_action(
                \Coins\Catalog\SortKeyService::ACTION_PRICES_UPSERTED,
                array_values(array_unique(array_map(static fn($r) => (int) $r['coin_id'], $rows)))
            );
        }

        return $sent;
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        return [
            'id'     => (int) $row['id'],
            'date'   => (string) $row['price_date'],
            'price'  => (float) $row['price'],
            'source' => ((string) $row['source']) ?: null,
        ];
    }

    /** Accepts 'Y-m-d' or ACF's 'Ymd'; always returns 'Y-m-d'. */
    private static function toSqlDate(string $date): string
    {
        if (preg_match('~^\d{8}$~', $date)) {
            return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }

        return $date;
    }
}
