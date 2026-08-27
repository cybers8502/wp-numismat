<?php

namespace Coins\Security;

class RateLimiter
{
    public static function isLimited(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $transientKey = 'coins_rl_' . md5($key);
        $count        = (int) get_transient($transientKey);

        if ($count >= $maxAttempts) {
            return true;
        }

        set_transient($transientKey, $count + 1, $windowSeconds);

        return false;
    }
}
