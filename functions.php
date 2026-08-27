<?php

require_once get_template_directory() . '/vendor/autoload.php';

(new \Coins\App())->boot();

add_filter('determine_current_user', function ($user_id) {
    if ($user_id) {
        return $user_id;
    }

    $auth_header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if (!$auth_header || !str_starts_with($auth_header, 'Basic ')) {
        return $user_id;
    }

    $decoded = base64_decode(substr($auth_header, 6));
    [$username, $password] = explode(':', $decoded, 2) + [null, null];

    if (!$username || !$password) {
        return $user_id;
    }

    $user = wp_authenticate_application_password(null, $username, $password);

    if (is_wp_error($user)) {
        $user = wp_authenticate_username_password(null, $username, $password);
    }

    return !is_wp_error($user) ? $user->ID : $user_id;
}, 20);

if (defined('WP_CLI') && WP_CLI) {
    \Coins\Console\FetchNbuDataCommand::register();
    \Coins\Console\FetchUaCoinsPricesCommand::register();
}