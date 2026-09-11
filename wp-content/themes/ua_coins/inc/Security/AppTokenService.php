<?php

namespace Coins\Security;

class AppTokenService
{
    public const TTL = 45 * MINUTE_IN_SECONDS;

    private const PREFIX = 'coins_app_token_';

    public static function issue(): string
    {
        $token = wp_generate_password(48, false, false);
        set_transient(self::PREFIX . $token, 1, self::TTL);

        return $token;
    }

    public static function validate(string $token): bool
    {
        return (bool) get_transient(self::PREFIX . $token);
    }
}
