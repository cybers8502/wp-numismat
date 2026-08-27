<?php

namespace Coins\Rest\Controllers;

use Coins\Security\AppTokenService;
use Coins\Security\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class AppTokenController
{
    // GET /app-token
    public function issue(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = sanitize_text_field(trim(explode(',', $ip)[0]));

        if (RateLimiter::isLimited("app_token_issue_{$ip}", 10, MINUTE_IN_SECONDS)) {
            return new WP_Error('rate_limited', 'Too many requests. Please slow down.', ['status' => 429]);
        }

        return new WP_REST_Response([
            'token'      => AppTokenService::issue(),
            'expires_in' => AppTokenService::TTL,
        ], 200);
    }
}
