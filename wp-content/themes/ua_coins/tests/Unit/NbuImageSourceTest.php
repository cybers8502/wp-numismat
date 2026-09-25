<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Media\NbuImageSource;
use PHPUnit\Framework\TestCase;

final class NbuImageSourceTest extends TestCase
{
    public function testNormalizeDropsCacheBuster(): void
    {
        $this->assertSame(
            'https://bank.gov.ua/media/coins/1722/avers.jpg',
            NbuImageSource::normalize('https://bank.gov.ua/media/coins/1722/avers.jpg?v=19')
        );
        $this->assertSame(
            'https://bank.gov.ua/files/coins_images/D04a.png',
            NbuImageSource::normalize('https://bank.gov.ua/files/coins_images/D04a.png')
        );
    }

    public function testCommemorativePhotosGetTheNbuIdInTheirName(): void
    {
        $this->assertSame('nbu-1722-avers.jpg', NbuImageSource::localFilename('https://bank.gov.ua/media/coins/1722/avers.jpg?v=19'));
        $this->assertSame('nbu-1766-revers.jpg', NbuImageSource::localFilename('https://bank.gov.ua/media/coins/1766/revers.jpg'));
    }

    public function testCodedImagesKeepTheirBasename(): void
    {
        $this->assertSame('D04a0.png', NbuImageSource::localFilename('https://bank.gov.ua/files/coins_images/D04a0.png?v=19'));
    }
}
