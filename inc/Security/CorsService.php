<?php

namespace Coins\Security;

class CorsService
{
    public function __construct() {
        add_action('rest_pre_serve_request', [$this, 'handleCors']);
    }

    public function handleCors(): void
    {
        $origin = get_http_origin();

        if ($origin && in_array($origin, $this->allowedOrigins(), true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-App-Token');
        }
    }

    private function allowedOrigins(): array
    {
        $configured = getenv('COINS_ALLOWED_ORIGINS');

        if ($configured) {
            return array_map('trim', explode(',', $configured));
        }

        return ['http://localhost:5173'];
    }
}
