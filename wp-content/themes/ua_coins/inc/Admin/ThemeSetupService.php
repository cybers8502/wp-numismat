<?php

namespace Coins\Admin;

class ThemeSetupService
{
    public function __construct()
    {
        add_action('after_setup_theme', [$this, 'setupTheme']);
    }

    public function setupTheme(): void
    {
        add_theme_support('post-thumbnails');
    }
}

