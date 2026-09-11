<?php

namespace Coins\Rest;

class ApiRouter
{

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $appTokenController = new Controllers\AppTokenController();

        // Anon app token — required (via X-App-Token header) to call /graphql.
        // See Security\ApiGuardService. This is the only REST route left: all
        // data access (coins, collection) is served over GraphQL.
        register_rest_route('coins/v1', '/app-token', [
            'methods'             => 'GET',
            'callback'            => [$appTokenController, 'issue'],
            'permission_callback' => '__return_true',
        ]);
    }
}
