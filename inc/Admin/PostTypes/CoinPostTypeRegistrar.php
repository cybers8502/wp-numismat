<?php

namespace Coins\Admin\PostTypes;

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

    public function registerCoinTaxonomies(): void
    {
        $taxonomies = [
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

        foreach ($taxonomies as $slug => $config) {
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

    public function seedFixedTerms(): void
    {
        $terms = [
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

        foreach ($terms as $taxonomy => $labels) {
            foreach ($labels as $label) {
                if (!term_exists($label, $taxonomy)) {
                    wp_insert_term($label, $taxonomy);
                }
            }
        }
    }
}
