<?php

namespace Coins\Security;

use WP_Error;
use WP_REST_Request;

class ApiGuardService
{
    // GET /coins/v1/coins, /coins/v1/coins/{id}, /coins/v1/coins/{id}/price-history
    private const GUARDED_REST_PATTERN = '#^/coins/v1/coins(/\d+(/price-history)?)?$#';

    private const GUARDED_GRAPHQL_ROUTE = '/graphql';

    public function __construct()
    {
        add_filter('rest_pre_dispatch', [$this, 'guard'], 10, 3);
    }

    public function guard($result, $server, WP_REST_Request $request)
    {
        if ($request->get_method() === 'OPTIONS') {
            return $result;
        }

        $route = $request->get_route();
        $isGuardedGraphQL = $route === self::GUARDED_GRAPHQL_ROUTE;
        $isGuardedRest    = preg_match(self::GUARDED_REST_PATTERN, $route) === 1;

        if (!$isGuardedGraphQL && !$isGuardedRest) {
            return $result;
        }

        // Requests that already carry credentials (JWT / Application Passwords) proved
        // their identity through a stronger mechanism — the anon app-token is redundant.
        if ($request->get_header('authorization')) {
            return $result;
        }

        $ip = $this->clientIp();

        if (RateLimiter::isLimited("api_ip_{$ip}", 60, MINUTE_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many requests. Please slow down.', ['status' => 429]);
        }

        $token = $request->get_header('x-app-token');

        if (!$token || !AppTokenService::validate($token)) {
            return new WP_Error(
                'missing_app_token',
                'A valid X-App-Token header is required. Fetch one from GET /wp-json/coins/v1/app-token.',
                ['status' => 401]
            );
        }

        return $result;
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = explode(',', $ip)[0];

        return sanitize_text_field(trim($ip));
    }
}
