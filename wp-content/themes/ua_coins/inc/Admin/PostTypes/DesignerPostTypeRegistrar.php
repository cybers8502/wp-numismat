<?php

namespace Coins\Admin\PostTypes;

class DesignerPostTypeRegistrar
{
    public function boot(): void
    {
        add_action('init', [$this, 'registerPostType']);
    }

    public function registerPostType(): void
    {
        register_post_type('designer', [
            'labels' => [
                'name'          => 'Designers',
                'singular_name' => 'Designer',
                'menu_name'     => 'Designers',
                'add_new_item'  => 'Add Designer',
                'edit_item'     => 'Edit Designer',
                'all_items'     => 'All Designers',
            ],
            'supports'      => ['title', 'editor', 'thumbnail'],
            'public'        => true,
            'show_ui'       => true,
            'menu_icon'     => 'dashicons-admin-users',
            'show_in_menu'  => true,
            'show_in_rest'        => true,
            'show_in_graphql'     => true,
            'graphql_single_name' => 'designer',
            'graphql_plural_name' => 'designers',
            'has_archive'         => false,
            'rewrite'       => ['slug' => 'designers', 'with_front' => true],
        ]);
    }
}
