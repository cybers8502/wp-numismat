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
        $coinController       = new Controllers\CoinController();
        $priceController      = new Controllers\CoinPriceController();
        $collectionController = new Controllers\CoinCollectionController();
        $appTokenController   = new Controllers\AppTokenController();

        // Anon app token — required (via X-App-Token header) to call the guarded
        // catalog endpoints below and /graphql. See Security\ApiGuardService.
        register_rest_route('coins/v1', '/app-token', [
            'methods'             => 'GET',
            'callback'            => [$appTokenController, 'issue'],
            'permission_callback' => '__return_true',
        ]);

        // Coins list
        register_rest_route('coins/v1', '/coins', [
            'methods'             => 'GET',
            'callback'            => [$coinController, 'getList'],
            'permission_callback' => '__return_true',
            'args'                => [
                'page'     => ['default' => 1,    'sanitize_callback' => 'absint'],
                'per_page' => ['default' => 20,   'sanitize_callback' => 'absint'],
                'search'   => ['default' => '',   'sanitize_callback' => 'sanitize_text_field'],
                'orderby'  => ['default' => 'date'],
                'order'    => ['default' => 'DESC'],
            ],
        ]);

        // Single coin
        register_rest_route('coins/v1', '/coins/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$coinController, 'getSingle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn($v) => is_numeric($v) && $v > 0,
                ],
            ],
        ]);

        register_rest_route('coins/v1', '/coins/(?P<id>\d+)/price-history', [
            'methods'             => 'GET',
            'callback'            => [$priceController, 'getPriceHistory'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn($v) => is_numeric($v) && $v > 0,
                ],
            ],
        ]);

        // Collection stats
        register_rest_route('coins/v1', '/collection/stats', [
            'methods'             => 'GET',
            'callback'            => [$collectionController, 'getStats'],
            'permission_callback' => fn() => is_user_logged_in(),
        ]);

        // Collection — list & add
        register_rest_route('coins/v1', '/collection', [
            [
                'methods'             => 'GET',
                'callback'            => [$collectionController, 'getCollection'],
                'permission_callback' => fn() => is_user_logged_in(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$collectionController, 'addItem'],
                'permission_callback' => fn() => is_user_logged_in(),
                'args'                => [
                    'coin_id' => [
                        'required'          => true,
                        'validate_callback' => fn($v) => is_numeric($v) && $v > 0,
                    ],
                    'quantity' => [
                        'required'          => false,
                        'default'           => 1,
                        'validate_callback' => fn($v) => is_numeric($v) && $v >= 1,
                    ],
                    'purchase_price' => [
                        'required'          => false,
                        'validate_callback' => fn($v) => is_numeric($v) && $v >= 0,
                    ],
                ],
            ],
        ]);

        // Collection — update & delete single item
        register_rest_route('coins/v1', '/collection/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [$collectionController, 'updateItem'],
                'permission_callback' => fn() => is_user_logged_in(),
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'validate_callback' => fn($v) => is_numeric($v) && $v > 0,
                    ],
                    'quantity' => [
                        'required'          => false,
                        'validate_callback' => fn($v) => is_numeric($v) && $v >= 1,
                    ],
                    'purchase_price' => [
                        'required'          => false,
                        'validate_callback' => fn($v) => is_numeric($v) && $v >= 0,
                    ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$collectionController, 'deleteItem'],
                'permission_callback' => fn() => is_user_logged_in(),
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'validate_callback' => fn($v) => is_numeric($v) && $v > 0,
                    ],
                ],
            ],
        ]);
    }
}