<?php

namespace Coins\Admin\PostTypes;

class CoinCollectionPostTypeRegistrar
{
    public function boot(): void
    {
        add_action('init', [$this, 'registerPostType']);
    }

    public function registerPostType(): void
    {
        register_post_type('coin_collection', [
            'labels' => [
                'name'          => 'Collections',
                'singular_name' => 'Collection Item',
                'menu_name'     => 'Collections',
                'all_items'     => 'All Collection Items',
                'add_new_item'  => 'Add Collection Item',
                'add_new'       => 'New Collection Item',
                'edit_item'     => 'Edit Collection Item',
                'search_items'  => 'Search Collection Items',
            ],
            'supports'            => ['title'],
            'hierarchical'        => false,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-portfolio',
            'show_in_admin_bar'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'show_in_rest'        => false,
            'capability_type'     => 'post',
        ]);
    }
}