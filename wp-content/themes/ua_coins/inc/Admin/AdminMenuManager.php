<?php

namespace Coins\Admin;

class AdminMenuManager
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'removeBaseMenus']);
        add_action('admin_init', [$this, 'removeMenusForNonAdmins']);
    }

    public function removeBaseMenus(): void
    {
        remove_menu_page('index.php');            // Dashboard
        remove_menu_page('edit-comments.php');    // Comments
    }

    public function removeMenusForNonAdmins(): void
    {
        if (!current_user_can('administrator')) {
            $slugs = [
                'index.php',
                'edit.php',
                'edit-comments.php',
                'themes.php',
                'plugins.php',
                'users.php',
                'tools.php',
                'theme-setup',
                'edit.php?post_type=page',
                'edit.php?post_type=acf-field-group',
            ];

            foreach ($slugs as $slug) {
                remove_menu_page($slug);
            }
        }
    }
}
