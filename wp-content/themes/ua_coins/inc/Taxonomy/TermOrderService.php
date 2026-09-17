<?php

namespace Coins\Taxonomy;

use Coins\Admin\PostTypes\CoinPostTypeRegistrar;
use WP_Screen;
use WP_Term;

/**
 * Manual, admin-controlled ordering for the coin taxonomies.
 *
 * WordPress has no built-in term order (unlike menu_order on posts), and WPGraphQL's term
 * connections only expose the stock `TermObjectsConnectionOrderbyEnum` (name/slug/count/…) — so
 * the catalog's facet chips came out alphabetically, in an order nobody could change. This stores
 * a per-term integer in term meta (`coin_term_order`) and makes it the *default* sort for every
 * term query against a coin taxonomy, which is what carries the order through to the app: the
 * clients (expo-numismat's FilterModal, r-numismat) ask for `coinMaterials`/`coinYears`/… with no
 * `orderby` at all, WPGraphQL fills in its own default of `name`/ASC, and this service rewrites
 * that into "by coin_term_order, then name".
 *
 * Because it hooks `terms_clauses` (i.e. WP_Term_Query itself, not GraphQL), the same order
 * applies everywhere a coin term list is read: the GraphQL root listings, the per-coin term
 * connections (`coin.coinDenominations.nodes`, via wp_get_object_terms), and wp-admin's own term
 * list table — no client-side sorting needed anywhere.
 *
 * Admins reorder by dragging rows on the taxonomy screen (Coins → Denomination → drag a row by
 * the handle in the "Порядок" column), or by typing an exact number into the term's edit form
 * when a list is too long to drag across pages. Terms that have never been
 * ordered sort last, among themselves by name, so a fresh install/import looks exactly like it
 * did before anyone touched the order.
 */
class TermOrderService
{
    /** Term meta holding the sort position. Lower sorts first; may be negative (see coin_year). */
    public const META_KEY = 'coin_term_order';

    /** Gap between consecutive positions, so a term can be slotted between two by hand. */
    public const ORDER_STEP = 10;

    /**
     * Sort value substituted for terms with no stored order, keeping them after every ordered
     * term. Deliberately far above anything drag-and-drop can produce (a taxonomy would need
     * ~100k terms to reach it).
     */
    private const ORDER_UNSET = 999999;

    private const AJAX_ACTION = 'coins_reorder_terms';
    private const NONCE       = 'coins_reorder_terms';
    private const COLUMN      = 'coin_term_order';
    private const FIELD       = 'coin_term_order';

    public function boot(): void
    {
        add_filter('terms_clauses', [$this, 'applyManualOrder'], 10, 3);
        add_action('created_term', [$this, 'assignDefaultOrder'], 10, 3);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxReorder']);

        foreach (self::orderedTaxonomies() as $taxonomy) {
            add_filter("manage_edit-{$taxonomy}_columns", [$this, 'addOrderColumn']);
            add_filter("manage_{$taxonomy}_custom_column", [$this, 'renderOrderColumn'], 10, 3);
            add_action("{$taxonomy}_edit_form_fields", [$this, 'renderEditFormField']);
            add_action("edited_{$taxonomy}", [$this, 'saveEditFormField']);
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Taxonomy slugs this service orders — every taxonomy on the `coins` post type.
     *
     * @return array<int,string>
     */
    public static function orderedTaxonomies(): array
    {
        return array_keys(CoinPostTypeRegistrar::taxonomies());
    }

    public static function getOrder(int $termId): ?int
    {
        $stored = get_term_meta($termId, self::META_KEY, true);

        return '' === $stored || null === $stored ? null : (int) $stored;
    }

    public static function setOrder(int $termId, int $order): void
    {
        update_term_meta($termId, self::META_KEY, $order);
    }

    /**
     * Write an order only if the term has none yet — never overwrites an admin's own ordering.
     *
     * @return bool Whether a value was written.
     */
    public static function seedDefaultOrder(int $termId, int $order): bool
    {
        if (null !== self::getOrder($termId)) {
            return false;
        }

        self::setOrder($termId, $order);

        return true;
    }

    /**
     * The position a term would get if nobody ever reordered its taxonomy by hand.
     *
     * Years run newest-first (nobody scrolls to 1995 to filter by it, and the app used to sort
     * them client-side for exactly this reason); the other numeric-valued taxonomies run
     * smallest-first, which alphabetical ordering gets wrong in the obvious way ("10 грн" before
     * "2 грн"); the fixed-term taxonomies keep the order they're declared in
     * (`CoinPostTypeRegistrar::fixedTerms()` — Монета first, not Банкнота). Everything else keeps
     * plain name order. Used by the CLI backfill, and by `created_term` for years, so a year term
     * added after a deploy still lands at the front.
     */
    public static function defaultSortKey(string $taxonomy, string $termName): float
    {
        switch ($taxonomy) {
            case 'coin_year':
                return -1 * self::leadingNumber($termName);
            case 'coin_denomination':
            case 'coin_diameter':
            case 'coin_mintage_declared':
            case 'coin_mintage_actual':
                return self::leadingNumber($termName);
            default:
                return self::fixedTermPosition($taxonomy, $termName);
        }
    }

    /** Whether `defaultSortKey()` says anything useful for this taxonomy (else: order by name). */
    public static function hasExplicitDefaultOrder(string $taxonomy): bool
    {
        return in_array($taxonomy, [
            'coin_year',
            'coin_denomination',
            'coin_diameter',
            'coin_mintage_declared',
            'coin_mintage_actual',
        ], true) || isset(CoinPostTypeRegistrar::fixedTerms()[$taxonomy]);
    }

    /**
     * Index of a term in its taxonomy's declared list, or a value past the end for anything that
     * isn't on it — an editor-added type/colour/packaging sorts after the known ones (by name,
     * since ties fall through to the caller's name comparison), not randomly among them.
     */
    private static function fixedTermPosition(string $taxonomy, string $termName): float
    {
        $labels = CoinPostTypeRegistrar::fixedTerms()[$taxonomy] ?? null;

        if (null === $labels) {
            return 0.0;
        }

        $position = array_search($termName, $labels, true);

        return false === $position ? (float) count($labels) : (float) $position;
    }

    /**
     * Sort every coin term query by the stored order, then by name.
     *
     * Only applies to the *default* sort — an explicit `orderby` (WPGraphQL's `where: { orderby:
     * COUNT }`, the admin clicking a sortable column, a plugin asking for `term_id`) is left
     * alone. WPGraphQL always fills in `name`/ASC when the query didn't ask for anything
     * (TermObjectConnectionResolver::prepare_query_args), and so does WP_Term_Query itself, so
     * "orderby name, order ASC" is exactly the "caller has no preference" case here.
     *
     * @param  array<string,string> $clauses
     * @param  array<int,string>    $taxonomies
     * @param  array<string,mixed>  $args
     * @return array<string,string>
     */
    public function applyManualOrder(array $clauses, array $taxonomies, array $args): array
    {
        global $wpdb;

        if (empty($clauses['orderby'])) {
            return $clauses;
        }

        if (!$taxonomies || array_diff($taxonomies, self::orderedTaxonomies())) {
            return $clauses;
        }

        $orderby = isset($args['orderby']) ? strtolower((string) $args['orderby']) : 'name';
        $order   = isset($args['order']) ? strtoupper((string) $args['order']) : 'ASC';

        if ('name' !== $orderby || 'ASC' !== $order) {
            return $clauses;
        }

        // wp-admin's term list table also defaults to name/ASC, but there the admin can click a
        // column header to sort; respect that rather than silently re-imposing the manual order.
        if (is_admin() && isset($_GET['orderby'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table state, no side effects.
            return $clauses;
        }

        $clauses['join'] .= $wpdb->prepare(
            " LEFT JOIN {$wpdb->termmeta} AS coins_term_order"
            . ' ON coins_term_order.term_id = t.term_id AND coins_term_order.meta_key = %s',
            self::META_KEY
        );

        $clauses['orderby'] = 'ORDER BY COALESCE(CAST(coins_term_order.meta_value AS SIGNED), '
            . self::ORDER_UNSET . ') ASC, t.name';

        return $clauses;
    }

    /**
     * Give a brand-new year term its default position, so years keep running newest-first without
     * anyone re-running the backfill after every import.
     *
     * @param int    $termId
     * @param int    $ttId
     * @param string $taxonomy
     */
    public function assignDefaultOrder($termId, $ttId, $taxonomy): void
    {
        if ('coin_year' !== $taxonomy) {
            return;
        }

        $term = get_term((int) $termId, $taxonomy);

        if (!$term instanceof WP_Term) {
            return;
        }

        self::seedDefaultOrder((int) $termId, (int) self::defaultSortKey($taxonomy, $term->name));
    }

    /**
     * @param  array<string,string> $columns
     * @return array<string,string>
     */
    public function addOrderColumn($columns): array
    {
        $columns = (array) $columns;
        $ordered = [];

        // Right after the checkbox, where the drag handle reads as part of the row rather than as
        // one more data column tacked on at the end.
        foreach ($columns as $key => $label) {
            $ordered[$key] = $label;

            if ('cb' === $key) {
                $ordered[self::COLUMN] = 'Порядок';
            }
        }

        if (!isset($ordered[self::COLUMN])) {
            $ordered[self::COLUMN] = 'Порядок';
        }

        return $ordered;
    }

    /**
     * @param  string $content
     * @param  string $column
     * @param  int    $termId
     * @return string
     */
    public function renderOrderColumn($content, $column, $termId): string
    {
        if (self::COLUMN !== $column) {
            return (string) $content;
        }

        $order = self::getOrder((int) $termId);

        return '<span class="coins-term-order__handle dashicons dashicons-menu-alt"'
            . ' title="Перетягніть, щоб змінити порядок"></span>'
            . '<span class="coins-term-order__value">'
            . esc_html(null === $order ? '—' : (string) $order)
            . '</span>';
    }

    /**
     * @param WP_Term|mixed $term
     */
    public function renderEditFormField($term): void
    {
        if (!$term instanceof WP_Term) {
            return;
        }

        $order = self::getOrder((int) $term->term_id);

        echo '<tr class="form-field">';
        echo '<th scope="row"><label for="' . esc_attr(self::FIELD) . '">Порядок</label></th>';
        echo '<td>';
        echo '<input name="' . esc_attr(self::FIELD) . '" id="' . esc_attr(self::FIELD) . '"'
            . ' type="number" step="1" value="' . esc_attr(null === $order ? '' : (string) $order) . '" />';
        echo '<p class="description">Менше число — вище у списках фільтрів у застосунку.'
            . ' Порожнє значення ставить термін у кінець. Зазвичай простіше перетягнути рядок'
            . ' на сторінці списку.</p>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * @param int $termId
     */
    public function saveEditFormField($termId): void
    {
        // Core verified the `update-tag_{term_id}` nonce before firing `edited_{$taxonomy}`.
        if (!isset($_POST[self::FIELD])) {
            return;
        }

        $raw = sanitize_text_field(wp_unslash($_POST[self::FIELD]));

        if ('' === $raw) {
            delete_term_meta((int) $termId, self::META_KEY);

            return;
        }

        self::setOrder((int) $termId, (int) $raw);
    }

    /**
     * @param string $hookSuffix
     */
    public function enqueueAdminAssets($hookSuffix): void
    {
        if ('edit-tags.php' !== $hookSuffix) {
            return;
        }

        $screen   = get_current_screen();
        $taxonomy = $screen instanceof WP_Screen ? (string) $screen->taxonomy : '';

        if (!in_array($taxonomy, self::orderedTaxonomies(), true)) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_add_inline_style('common', '
            .column-' . self::COLUMN . ' { width: 6em; }
            .coins-term-order__handle { cursor: move; color: #8c8f94; }
            .coins-term-order__value { margin-left: .4em; color: #646970; }
            .coins-term-order--saving { opacity: .6; }
            .coins-term-order__helper { display: table; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
        ');

        wp_enqueue_script(
            'coins-term-order',
            get_template_directory_uri() . '/assets/admin/term-order.js',
            ['jquery', 'jquery-ui-sortable'],
            (string) filemtime(get_template_directory() . '/assets/admin/term-order.js'),
            true
        );

        // wp_add_inline_script(), not wp_localize_script(): the latter stringifies every scalar,
        // and `offset`/`step` are arithmetic in the JS ("20" + 1 is not 21).
        $config = [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'action'   => self::AJAX_ACTION,
            'nonce'    => wp_create_nonce(self::NONCE),
            'taxonomy' => $taxonomy,
            // Positions are global, not per-page: row 1 of page 3 must not become position 1.
            'offset'   => $this->currentListOffset($taxonomy),
            'step'     => self::ORDER_STEP,
            // A searched or explicitly sorted list isn't the real order, so dragging it would
            // write positions that mean nothing — the rows in between simply aren't on screen.
            'sortable' => !isset($_GET['s']) && !isset($_GET['orderby']), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table state.
            'i18n'     => [
                'saved' => 'Порядок збережено.',
                'error' => 'Не вдалося зберегти порядок. Оновіть сторінку та спробуйте ще раз.',
            ],
        ];

        wp_add_inline_script(
            'coins-term-order',
            'window.coinsTermOrder = ' . wp_json_encode($config) . ';',
            'before'
        );
    }

    /** Persist a dragged page of rows as positions, continuing from where that page starts. */
    public function ajaxReorder(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';

        if (!in_array($taxonomy, self::orderedTaxonomies(), true)) {
            wp_send_json_error(['message' => 'Невідома таксономія.'], 400);
        }

        $taxonomyObject = get_taxonomy($taxonomy);

        if (!$taxonomyObject || !current_user_can($taxonomyObject->cap->manage_terms)) {
            wp_send_json_error(['message' => 'Недостатньо прав.'], 403);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        // absint() is the sanitizer here — term IDs are the only thing this endpoint reads, and
        // anything that isn't one is dropped before it reaches get_term().
        $rawIds = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() applied inline; wp_unslash() is meaningless for integers.
        $ids    = array_values(array_filter($rawIds));

        $written = 0;

        foreach ($ids as $index => $termId) {
            if (!get_term($termId, $taxonomy) instanceof WP_Term) {
                continue;
            }

            self::setOrder($termId, ($offset + $index) * self::ORDER_STEP);
            $written++;
        }

        wp_send_json_success(['ordered' => $written]);
    }

    /**
     * How many terms precede the page currently on screen — mirrors WP_Terms_List_Table's own
     * paging maths so a drag on page 2 writes positions 20…39 rather than starting over at 0.
     */
    private function currentListOffset(string $taxonomy): int
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list-table state, no side effects.
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $perPage = (int) get_user_option('edit_' . $taxonomy . '_per_page');

        if ($perPage < 1) {
            $perPage = 20;
        }

        // Same screen option and filter WP_Terms_List_Table::prepare_items() reads
        // (WP_List_Table::get_items_per_page("edit_{$taxonomy}_per_page")); the extra
        // `edit_tags_per_page`/`edit_categories_per_page` passes it does are for post_tag and
        // category only, neither of which is a coin taxonomy.
        $perPage = (int) apply_filters("edit_{$taxonomy}_per_page", $perPage);

        return ($page - 1) * $perPage;
    }

    /** Leading number in a term name — "10 грн" → 10, "38.6" → 38.6, "2025" → 2025, "срібло" → 0. */
    private static function leadingNumber(string $name): float
    {
        $normalized = str_replace(',', '.', trim($name));

        return preg_match('/-?\d+(\.\d+)?/', $normalized, $matches) ? (float) $matches[0] : 0.0;
    }
}
