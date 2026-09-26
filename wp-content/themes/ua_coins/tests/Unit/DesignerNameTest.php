<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Console\FetchNbuDataCommand;
use PHPUnit\Framework\TestCase;

final class DesignerNameTest extends TestCase
{
    public function testWhitespaceAndTrailingDotAreNormalised(): void
    {
        $this->assertSame('Корень Лариса', FetchNbuDataCommand::normalize_designer_name('  Корень  Лариса '));
        $this->assertSame('Аліса Іванова', FetchNbuDataCommand::normalize_designer_name('Аліса Іванова.'));
    }

    public function testNameItselfIsKept(): void
    {
        $this->assertSame('Дем`яненко Володимир', FetchNbuDataCommand::normalize_designer_name('Дем`яненко Володимир'));
    }
}
