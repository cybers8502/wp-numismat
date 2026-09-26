<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Catalog\DesignerCredits as D;
use PHPUnit\Framework\TestCase;

/** Every input is a real NBU "Художник:" / "Скульптор:" value. */
final class DesignerCreditsTest extends TestCase
{
    /**
     * @param array<string,string> $raw
     * @return array<string,array<int,string>> non-empty roles only
     */
    private function parse(array $raw): array
    {
        return array_filter(D::parse($raw));
    }

    public function testPlainList(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Таран Володимир', 'Харук Олександр', 'Харук Сергій']],
            $this->parse(['designers_artist' => 'Таран Володимир , Харук Олександр , Харук Сергій'])
        );
    }

    public function testObverseReverseLabelsAreDropped(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Івахненко Олександр', 'Іваненко Святослав', 'Таран Володимир', 'Харук Олександр', 'Харук Сергій']],
            $this->parse(['designers_artist' => 'аверс: Івахненко Олександр, Іваненко Святослав; реверс: Таран Володимир, Харук Олександр, Харук Сергій'])
        );
    }

    public function testDigitalModellingIsSculptorEvenWithoutSeparator(): void
    {
        $this->assertSame(
            ['designers_sculptor' => ['Атаманчук Володимир', 'Лук’янов Юрій']],
            $this->parse(['designers_sculptor' => 'Атаманчук Володимир програмне моделювання: Лук`янов Юрій'])
        );
        $this->assertSame(
            ['designers_sculptor' => ['Демяненко Анатолій', 'Андріянов Віталій', 'Лук’янов Юрій']],
            $this->parse(['designers_sculptor' => 'Демяненко Анатолій; програмне моделювання: Андріянов Віталій, Лук’янов Юрій'])
        );
    }

    public function testAdaptationAndDesignRoles(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Володимир Дем’яненко'], 'designers_adaptation' => ['Олександра Кучинська']],
            $this->parse(['designers_artist' => 'Володимир Дем’яненко; адаптація дизайну – ОлександраКучинська'])
        );
        $this->assertSame(
            ['designers_artist' => ['Дем’яненко Володимир'], 'designers_designer' => ['Балута Тетяна']],
            $this->parse(['designers_artist' => 'Дем`яненко Володимир; Дизайн - Балута Тетяна'])
        );
    }

    public function testSentencesWithRoles(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Ладний Юрій', 'Бєляєв Сергій'], 'designers_adaptation' => ['Балута Тетяна']],
            $this->parse(['designers_artist' => 'Автор ідеї – Ладний Юрій. Художник – Бєляєв Сергій. Адаптація дизайну – Балута Тетяна'])
        );
    }

    public function testParentheticalCredits(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Косинський Олександр', 'Руденко Олексій', 'Балута Тетяна'], 'designers_designer' => ['Кучинська Олександра']],
            $this->parse(['designers_artist' => 'Косинський Олександр (автор ідеї), Руденко Олексій (автор ескізу); Балута Тетяна, Кучинська Олександра (дизайн)'])
        );
    }

    public function testInitialsKeepTheirDotAndHyphenatedNamesSurvive(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Івахненко Олександр', 'Коцарь Д.', 'Лизунов В.']],
            $this->parse(['designers_artist' => 'аверс: Івахненко Олександр; реверс: Коцарь Д. , Лизунов В.'])
        );
        $this->assertSame(
            ['designers_artist' => ['Дерегус-Лоренс Наталія', 'Дерегус Марина', 'Груденко Борис']],
            $this->parse(['designers_artist' => 'аверс: Дерегус-Лоренс Наталія, Дерегус Марина; реверс: Груденко Борис'])
        );
    }

    public function testTrailingDotAndPlaceholder(): void
    {
        $this->assertSame(
            ['designers_sculptor' => ['Атаманчук Володимир', 'Дем’яненко Володимир']],
            $this->parse(['designers_sculptor' => 'Атаманчук Володимир, Дем’яненко Володимир .'])
        );
        $this->assertSame([], $this->parse(['designers_sculptor' => 'undefined']));
    }

    public function testDuplicatesInOneFieldCollapse(): void
    {
        $this->assertSame(
            ['designers_artist' => ['Таран Володимир', 'Харук Олександр']],
            $this->parse(['designers_artist' => 'Таран Володимир, Харук Олександр, Харук Олександр'])
        );
    }

    public function testCreditWithConjunctionIsOnePerson(): void
    {
        $this->assertSame(
            ['designers_adaptation' => ['Андрій Сагач']],
            $this->parse(['designers_sculptor' => 'Автор ідеї та адаптація дизайну – Андрій Сагач'])
        );
    }

    public function testKnownSpellingVariantsAreMerged(): void
    {
        $this->assertSame(['designers_sculptor' => ['Котович Роберт']], $this->parse(['designers_sculptor' => 'реверс: Роберт Котовіч']));
        $this->assertSame(['designers_artist' => ['Кочубей Микола']], $this->parse(['designers_artist' => 'Кочубей Миколай']));
    }

    public function testDisplayNameIsSurnameFirst(): void
    {
        $this->assertSame('Кучинська Олександра', D::displayName('Олександра Кучинська'));
        $this->assertSame('Кучинська Олександра', D::displayName('Кучинська Олександра'));
        $this->assertSame('Чернай Ян', D::displayName('Чернай Ян'));
        $this->assertSame('Цанашка А.', D::displayName('Цанашка А.'));
        $this->assertSame('Дерегус-Лоренс Наталія', D::displayName('Наталія Дерегус-Лоренс'));
    }

    public function testKeyIgnoresOrderCaseAndApostrophes(): void
    {
        $this->assertSame(D::key('Таран Володимир'), D::key('Володимир Таран'));
        $this->assertSame(D::key('Дем`яненко Анатолій'), D::key('Анатолій Демяненко'));
        $this->assertNotSame(D::key('Дем’яненко Володимир'), D::key('Дем’яненко Анатолій'));
    }
}
