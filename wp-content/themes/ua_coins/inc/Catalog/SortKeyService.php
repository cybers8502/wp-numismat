<?php

namespace Coins\Catalog;

use Coins\Taxonomy\TermOrderService;

/**
 * Denormalises every sortable catalog column into post meta, so the apps' coin table can be
 * ordered by the *whole* filtered catalog on the server instead of reordering whatever page
 * happens to be loaded.
 *
 * Why post meta and not an ORDER BY injected via `posts_clauses`: WPGraphQL paginates with
 * cursors, and `PostObjectCursor` can only reproduce an ordering it recognises — `post_date`,
 * and `meta_value`/`meta_value_num` alongside a `meta_key` (see its `compare_with_meta_field()`).
 * A hand-built SQL ORDER BY would leave the cursor comparing bare post IDs, so "load more" on a
 * price-sorted catalog would return neither the next page by price nor even a stable set. One
 * indexed meta row per sortable column keeps pagination correct with no cursor work at all.
 *
 * Year is deliberately absent: `FetchNbuDataCommand` writes `post_date = issue_date`, so the
 * stock `date` orderby already sorts by real issue date across the whole catalog.
 *
 * Every coin always carries every key, even when the underlying value is missing (0 / ''). WP's
 * `meta_key` ordering INNER JOINs postmeta, so a coin *without* the row would drop out of the
 * list entirely rather than sort last. The cost of that guarantee: unknowns cluster at the start
 * in ASC and at the end in DESC, instead of always sorting last the way a client-side comparator
 * can manage.
 */
class SortKeyService
{
    public const META_TITLE        = '_sort_title';
    public const META_DENOMINATION = '_sort_denomination';
    public const META_MATERIAL     = '_sort_material';
    public const META_QUALITY      = '_sort_quality';
    public const META_MINTAGE      = '_sort_mintage';
    public const META_PRICE        = '_sort_price';

    /** Fired by PriceRepository::upsertBatch() with the coin ids it touched. */
    public const ACTION_PRICES_UPSERTED = 'coins_prices_upserted';

    public const POST_TYPE = 'coins';

    /** Source tag of the market prices the table's "Вартість" column shows (see CoinGraphQL). */
    private const SOURCE_MARKET = 'ua-coins.info';

    /** Terms with no admin-set position sort last — same sentinel TermOrderService uses. */
    private const ORDER_UNSET = 999999;

    /** meta key => taxonomy whose terms it mirrors. */
    private const TERM_COLUMNS = [
        self::META_DENOMINATION => 'coin_denomination',
        self::META_MATERIAL     => 'coin_material',
        self::META_QUALITY      => 'coin_quality',
    ];

    public function boot(): void
    {
        // Priority 20: after ACF has written the post's own meta, so mintage reads the new value.
        add_action('save_post_' . self::POST_TYPE, [$this, 'onSavePost'], 20, 2);
        add_action('set_object_terms', [$this, 'onSetObjectTerms'], 20, 6);
        add_action(self::ACTION_PRICES_UPSERTED, [$this, 'onPricesUpserted'], 10, 1);
    }

    /**
     * @param int      $postId
     * @param \WP_Post $post
     */
    public function onSavePost($postId, $post = null): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $this->rebuild((int) $postId);
    }

    /**
     * @param int    $objectId
     * @param array  $terms
     * @param array  $ttIds
     * @param string $taxonomy
     * @param bool   $append
     * @param array  $oldTtIds
     */
    public function onSetObjectTerms($objectId, $terms, $ttIds, $taxonomy, $append, $oldTtIds): void
    {
        if (!in_array($taxonomy, self::TERM_COLUMNS, true)) {
            return;
        }

        if (get_post_type($objectId) !== self::POST_TYPE) {
            return;
        }

        $this->rebuild((int) $objectId);
    }

    /**
     * @param list<int> $coinIds
     */
    public function onPricesUpserted($coinIds): void
    {
        foreach ((array) $coinIds as $coinId) {
            $this->rebuildPrice((int) $coinId);
        }
    }

    /**
     * Recompute and store every sort key for one coin.
     *
     * Idempotent, and `update_post_meta` is a no-op write when the value is unchanged, so this is
     * cheap to call from hooks that fire more often than the data actually changes.
     */
    public function rebuild(int $postId): void
    {
        update_post_meta($postId, self::META_TITLE, $this->titleKey($postId));

        foreach (self::TERM_COLUMNS as $metaKey => $taxonomy) {
            update_post_meta($postId, $metaKey, $this->termKey($postId, $taxonomy));
        }

        update_post_meta($postId, self::META_MINTAGE, $this->mintageKey($postId));
        $this->rebuildPrice($postId);
    }

    public function rebuildPrice(int $postId): void
    {
        update_post_meta($postId, self::META_PRICE, $this->priceKey($postId));
    }

    /**
     * The table shows `shortTitle` when a coin has one and falls back to `post_title`, so the sort
     * key has to follow the same fallback or the column would order by text nobody can see.
     *
     * Leading quotes and guillemets are stripped: almost every NBU title is quoted ("Червона
     * рута"), and sorting on the raw string would file the entire catalog under `"` instead of
     * under its first real letter.
     */
    private function titleKey(int $postId): string
    {
        $short = (string) get_post_meta($postId, 'short_title', true);
        $title = $short !== '' ? $short : (string) get_post_field('post_title', $postId);

        $title = trim($title);
        $title = preg_replace('/^[\p{Pi}\p{Pf}\p{Ps}\p{Pe}"\'`\x{2018}\x{2019}\x{201C}\x{201D}\x{00AB}\x{00BB}\s]+/u', '', $title) ?? $title;

        return mb_strtolower(trim($title));
    }

    /**
     * A coin can carry several terms of one taxonomy, so the column sorts by its *first* term —
     * first in the order wp-admin set (`coin_term_order`, see TermOrderService), then by name.
     *
     * Keeping the admin order as the primary discriminator is what makes the Номінал column sort
     * 2 ₴, 5 ₴, 10 ₴, 20 ₴ rather than the "10, 2, 20, 5" a plain alphabetical sort would give,
     * and it matches the order the same terms appear in as filter chips.
     */
    private function termKey(int $postId, string $taxonomy): string
    {
        $terms = get_the_terms($postId, $taxonomy);

        if (!$terms || is_wp_error($terms)) {
            return '';
        }

        $keys = [];

        foreach ($terms as $term) {
            $stored = get_term_meta($term->term_id, TermOrderService::META_KEY, true);
            $order  = ($stored === '' || $stored === false) ? self::ORDER_UNSET : (int) $stored;

            $keys[] = sprintf('%06d|%s', $order, mb_strtolower($term->name));
        }

        usort($keys, 'strcmp');

        return $keys[0];
    }

    /** Mirrors the table's own "actual, else declared" rule (see formatCell in CoinTable.tsx). */
    private function mintageKey(int $postId): int
    {
        foreach (['mintage_actual', 'mintage_declared'] as $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);

            if ($value !== '' && $value !== false && $value !== null) {
                return (int) $value;
            }
        }

        return 0;
    }

    /** The same "most recent market price" `priceStats.latestPrice` resolves to. */
    private function priceKey(int $postId): float
    {
        global $wpdb;

        $table = \Coins\Prices\PriceSchema::table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed internal identifier.
        $price = $wpdb->get_var($wpdb->prepare(
            "SELECT price
               FROM {$table}
              WHERE coin_id = %d AND source = %s
              ORDER BY price_date DESC, id DESC
              LIMIT 1",
            $postId,
            self::SOURCE_MARKET
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $price === null ? 0.0 : (float) $price;
    }
}
