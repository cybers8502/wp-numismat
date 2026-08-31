<?php

namespace Coins\Security;

class CorsService
{
    private const ALLOWED_HEADERS = ['Authorization', 'Content-Type', 'X-WP-Nonce', 'X-App-Token'];

    public function __construct() {
        add_action('rest_pre_serve_request', [$this, 'handleCors']);

        // WPGraphQL builds its own CORS headers in its Router and ignores the
        // `rest_pre_serve_request` hook above, so the custom `X-App-Token`
        // header (see ApiGuardService) has to be whitelisted here too or the
        // browser preflight for /graphql fails.
        add_filter('graphql_access_control_allow_headers', [$this, 'allowGraphqlHeaders']);
    }

    /**
     * @param string[] $headers
     * @return string[]
     */
    public function allowGraphqlHeaders(array $headers): array
    {
        return array_values(array_unique(array_merge($headers, self::ALLOWED_HEADERS)));
    }

    public function handleCors(): void
    {
        $origin = get_http_origin();

        if ($origin && in_array($origin, $this->allowedOrigins(), true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: ' . implode(', ', self::ALLOWED_HEADERS));
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
