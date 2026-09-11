<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Coins\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testNotLimitedBelowMaxAttemptsAndBumpsTheCounter(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->with('coins_rl_' . md5('key-a'))
            ->andReturn(2);

        Functions\expect('set_transient')
            ->once()
            ->with('coins_rl_' . md5('key-a'), 3, 60)
            ->andReturn(true);

        self::assertFalse(RateLimiter::isLimited('key-a', 5, 60));
    }

    public function testLimitedAtMaxAttemptsAndDoesNotBumpTheCounter(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->with('coins_rl_' . md5('key-b'))
            ->andReturn(5);

        Functions\expect('set_transient')->never();

        self::assertTrue(RateLimiter::isLimited('key-b', 5, 60));
    }

    public function testMissingTransientIsTreatedAsZeroAttempts(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->with('coins_rl_' . md5('key-c'))
            ->andReturn(false);

        Functions\expect('set_transient')
            ->once()
            ->with('coins_rl_' . md5('key-c'), 1, 30)
            ->andReturn(true);

        self::assertFalse(RateLimiter::isLimited('key-c', 5, 30));
    }
}
