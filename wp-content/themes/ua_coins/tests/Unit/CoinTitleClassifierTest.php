<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Catalog\CoinTitleClassifier as C;
use PHPUnit\Framework\TestCase;

/** Titles are real NBU catalog entries. */
final class CoinTitleClassifierTest extends TestCase
{
    public function testCoinInSouvenirPackagingIsACoin(): void
    {
        $title = '`Українська бавовна. Морський дрон "Sea Baby"` (н) у сувенірному пакованні';
        $this->assertSame(C::TYPE_COIN, C::type($title));
        $this->assertSame(C::PACKAGING_SOUVENIR, C::packaging($title));
    }

    public function testOlderUpakovkaSpellingIsPackagingToo(): void
    {
        $title = 'Український борщ у сувенірній упаковці (н)';
        $this->assertSame(C::TYPE_COIN, C::type($title));
        $this->assertSame(C::PACKAGING_SOUVENIR, C::packaging($title));
    }

    public function testSouvenirBanknotesAreBanknotes(): void
    {
        $this->assertSame(C::TYPE_BANKNOTE, C::type('Срібна сувенірна банкнота номіналом 20 грн зразка 2018 року'));
        $this->assertSame(C::TYPE_BANKNOTE, C::type('Пам`ятна банкнота `ПАМ’ЯТАЄМО! НЕ ПРОБАЧИМО!` (у сувенірній упаковці)'));
        $this->assertSame(C::TYPE_BANKNOTE, C::type('Набір із шести сувенірних срібних модифікованих банкнот'));
    }

    public function testMedalInPackagingIsAMedal(): void
    {
        $this->assertSame(C::TYPE_MEDAL, C::type('Пам`ятна медаль "Національне агентство з питань запобігання корупції" у сувенірному пакованні'));
    }

    public function testSetInPackagingIsASetOfCoins(): void
    {
        $title = 'Набір із двох пам’ятних монет "Мешканці морських глибин" у сувенірному пакованні';
        $this->assertSame(C::TYPE_COIN, C::type($title));
        $this->assertSame(C::PACKAGING_SET, C::packaging($title));
    }

    public function testSouvenirOutsidePackagingPhraseStaysSouvenir(): void
    {
        $this->assertSame(C::TYPE_SOUVENIR, C::type('Сувенірний жетон «Київ»'));
    }

    public function testPlainCoin(): void
    {
        $this->assertSame(C::TYPE_COIN, C::type('"Місто-герой Севастополь" (м)'));
        $this->assertSame(C::PACKAGING_NONE, C::packaging('"Місто-герой Севастополь" (м)'));
    }
}
