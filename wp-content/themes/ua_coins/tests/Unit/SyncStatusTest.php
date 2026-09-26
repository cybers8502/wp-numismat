<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Sync\SyncStatus;
use PHPUnit\Framework\TestCase;

final class SyncStatusTest extends TestCase
{
    private const NOW = 1790000000; // 2026-09-21

    public function testOldCoinWithEverythingIsComplete(): void
    {
        $this->assertSame([], SyncStatus::reasons(2, true, true, '2025-01-10', self::NOW));
    }

    public function testFreshCoinStaysPendingForLatePhotos(): void
    {
        $this->assertSame(['fresh'], SyncStatus::reasons(6, true, true, '2026-09-01', self::NOW));
    }

    public function testAcfDatePickerFormatIsUnderstood(): void
    {
        $this->assertSame(['fresh'], SyncStatus::reasons(6, true, true, '20260901', self::NOW));
    }

    public function testMissingPhotosAndData(): void
    {
        $this->assertSame(['images', 'description', 'mintage'], SyncStatus::reasons(1, false, false, '2020-05-01', self::NOW));
    }

    public function testNoIssueDateIsNotFresh(): void
    {
        $this->assertSame([], SyncStatus::reasons(2, true, true, '', self::NOW));
    }
}
