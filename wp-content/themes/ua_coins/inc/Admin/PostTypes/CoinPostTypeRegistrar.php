<?php

namespace Coins\Admin\PostTypes;

use Coins\Taxonomy\TermOrderService;

class CoinPostTypeRegistrar
{
    public function boot(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('init', [$this, 'registerCoinTaxonomies']);
        add_action('init', [$this, 'seedFixedTerms'], 20);
    }

    public function registerPostType(): void
    {
        register_post_type('coins', [
            'labels' => [
                'name'               => 'Coins',
                'singular_name'      => 'Coin',
                'menu_name'          => 'Coins',
                'all_items'          => 'All Coins',
                'view_item'          => 'View Coin',
                'add_new_item'       => 'Add Coin',
                'add_new'            => 'New Coin',
                'edit_item'          => 'Edit Coin',
                'update_item'        => 'Update Coin',
                'search_items'       => 'Search Coins',
            ],
            'supports'            => ['title', 'editor', 'thumbnail'],
            'hierarchical'        => false,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-star-filled',
            'show_in_admin_bar'   => true,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'taxonomies'          => [],
            'show_in_rest'        => true,
            'show_in_graphql'     => true,
            'graphql_single_name' => 'coin',
            'graphql_plural_name' => 'coins',
            'capability_type'     => 'post',
            'rewrite'             => ['with_front' => true],
        ]);
    }

    /**
     * Every taxonomy registered against the `coins` post type, keyed by slug.
     *
     * Public/static because it's the single source of truth for "which taxonomies are coin
     * facets" — `Taxonomy\TermOrderService` (manual term ordering) and `GraphQL\TaxonomyGraphQL`
     * (the `termOrder` field) both read it rather than keeping their own copy of the list.
     *
     * @return array<string,array{label:string,hierarchical:bool,graphql_single_name:string,graphql_plural_name:string}>
     */
    public static function taxonomies(): array
    {
        return [
            'coin_denomination' => [
                'label'               => 'Denomination',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinDenomination',
                'graphql_plural_name' => 'coinDenominations',
            ],
            'coin_quality' => [
                'label'               => 'Quality',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinQuality',
                'graphql_plural_name' => 'coinQualities',
            ],
            'coin_material' => [
                'label'               => 'Material',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinMaterial',
                'graphql_plural_name' => 'coinMaterials',
            ],
            'coin_series' => [
                'label'               => 'Series',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinSeries',
                'graphql_plural_name' => 'coinSeriesList',
            ],
            'coin_edge' => [
                'label'               => 'Edge',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinEdge',
                'graphql_plural_name' => 'coinEdges',
            ],
            // Mirrors the year of the `issue_date` ACF field as a real term, the same way
            // coin_diameter/coin_mintage_* mirror their own numeric ACF fields — so clients can
            // list the years that actually exist (with counts, hideEmpty) and filter by them via
            // tax_query, instead of guessing a range from min/max. Assigned by
            // FetchNbuDataCommand on import; backfill existing posts with `wp coins backfill-years`.
            'coin_year' => [
                'label'               => 'Year',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinYear',
                'graphql_plural_name' => 'coinYears',
            ],
            'coin_diameter' => [
                'label'               => 'Diameter',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinDiameter',
                'graphql_plural_name' => 'coinDiameters',
            ],
            'coin_mintage_declared' => [
                'label'               => 'Mintage (declared)',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinMintageDeclared',
                'graphql_plural_name' => 'coinMintagesDeclared',
            ],
            'coin_mintage_actual' => [
                'label'               => 'Mintage (actual)',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinMintageActual',
                'graphql_plural_name' => 'coinMintagesActual',
            ],
            'coin_color' => [
                'label'               => 'Color',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinColor',
                'graphql_plural_name' => 'coinColors',
            ],
            'coin_packaging' => [
                'label'               => 'Packaging',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinPackaging',
                'graphql_plural_name' => 'coinPackagings',
            ],
            'coin_type' => [
                'label'               => 'Type',
                'hierarchical'        => false,
                'graphql_single_name' => 'coinType',
                'graphql_plural_name' => 'coinTypes',
            ],
        ];
    }

    public function registerCoinTaxonomies(): void
    {
        foreach (self::taxonomies() as $slug => $config) {
            register_taxonomy($slug, ['coins'], [
                'label'               => $config['label'],
                'public'              => true,
                'hierarchical'        => $config['hierarchical'],
                'show_ui'             => true,
                'show_in_rest'        => true,
                'show_in_graphql'     => true,
                'graphql_single_name' => $config['graphql_single_name'],
                'graphql_plural_name' => $config['graphql_plural_name'],
                'rewrite'             => ['slug' => $slug],
            ]);
        }
    }

    /**
     * Taxonomies whose full set of terms is known up front, in the order they should be offered.
     *
     * The listed order is itself meaningful — Монета is the type nearly every coin has, and
     * alphabetical ordering buries it behind Банкнота/Інвестиційна/Медаль — so it doubles as the
     * default facet order these terms are seeded with (`Taxonomy\TermOrderService`, and
     * `BackfillTermOrderCommand` for installs whose terms predate that).
     *
     * @return array<string,array<int,string>>
     */
    public static function fixedTerms(): array
    {
        return [
            'coin_type' => [
                'Монета',
                'Банкнота',
                'Сувенірна продукція',
                'Медаль',
                'Інвестиційна',
            ],
            'coin_color' => [
                'Кольорова',
                'Некольорова',
            ],
            'coin_packaging' => [
                'Без пакування',
                'В сувенірному пакуванні',
                'Набір',
                'Ролик',
            ],
        ];
    }

    public function seedFixedTerms(): void
    {
        foreach (self::fixedTerms() as $taxonomy => $labels) {
            foreach ($labels as $index => $label) {
                if (term_exists($label, $taxonomy)) {
                    continue;
                }

                $created = wp_insert_term($label, $taxonomy);

                // Seeded here rather than on every `init` pass: one write per term, ever. Existing
                // installs — where these terms were inserted long before ordering existed — get
                // the same positions from `wp coins backfill-term-order`, which reads the list
                // above for exactly that reason.
                if (is_array($created)) {
                    TermOrderService::seedDefaultOrder(
                        (int) $created['term_id'],
                        $index * TermOrderService::ORDER_STEP
                    );
                }
            }
        }
    }
}
