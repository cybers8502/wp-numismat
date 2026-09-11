<?php
namespace Coins\Assets;

class AssetManager {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('script_loader_tag', [$this, 'addModuleAttribute'], 10, 3);
        add_filter('style_loader_tag', [$this, 'addCrossOriginToStyles'], 10, 2);
    }

    public function enqueue() {
        wp_deregister_script('jquery');
    }

    public function addModuleAttribute($tag, $handle, $src) {
        if ($handle === 'custom_module_script') {
            return '<script type="module" crossorigin src="' . esc_url($src) . '"></script>';
        }
        return $tag;
    }

    public function addCrossOriginToStyles($html, $handle) {
        if ($handle === 'custom_style') {
            return str_replace('<link ', '<link crossorigin ', $html);
        }
        return $html;
    }
}
