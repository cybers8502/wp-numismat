<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Taxonomy\TermOrderService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure part of term ordering: the "natural" position a term gets before anyone
 * reorders its taxonomy by hand (`wp coins backfill-term-order`, and `created_term` for years).
 * The WP-facing halves — the terms_clauses rewrite and the admin/AJAX glue — need a real
 * WP_Term_Query and aren't unit-testable here.
 */
final class TermOrderServiceTest extends TestCase
{
    public function testYearsSortNewestFirst(): void
    {
        $newer = TermOrderService::defaultSortKey('coin_year', '2025');
        $older = TermOrderService::defaultSortKey('coin_year', '1995');

        self::assertLessThan($older, $newer);
        self::assertSame(-2025.0, $newer);
    }

    public function testNumericTaxonomiesSortByValueNotByName(): void
    {
        // "10 грн" sorts before "2 грн" alphabetically, which is the whole reason these have a
        // numeric default order at all.
        $two = TermOrderService::defaultSortKey('coin_denomination', '2 грн');
        $ten = TermOrderService::defaultSortKey('coin_denomination', '10 грн');

        self::assertLessThan($ten, $two);
    }

    public function testFractionalDiametersKeepTheirValue(): void
    {
        self::assertSame(38.6, TermOrderService::defaultSortKey('coin_diameter', '38.6'));
        self::assertSame(38.6, TermOrderService::defaultSortKey('coin_diameter', '38,6'));
    }

    public function testOpenEndedTaxonomiesHaveNoNaturalOrderAndFallBackToName(): void
    {
        self::assertFalse(TermOrderService::hasExplicitDefaultOrder('coin_material'));
        self::assertSame(0.0, TermOrderService::defaultSortKey('coin_material', 'срібло'));
    }

    public function testFixedTermTaxonomiesKeepTheirDeclaredOrder(): void
    {
        // Монета is the type nearly every coin has; alphabetically it lands fourth.
        $coin     = TermOrderService::defaultSortKey('coin_type', 'Монета');
        $banknote = TermOrderService::defaultSortKey('coin_type', 'Банкнота');

        self::assertLessThan($banknote, $coin);
        self::assertTrue(TermOrderService::hasExplicitDefaultOrder('coin_type'));
    }

    public function testTermsMissingFromAFixedListSortAfterTheKnownOnes(): void
    {
        $known   = TermOrderService::defaultSortKey('coin_type', 'Інвестиційна');
        $unknown = TermOrderService::defaultSortKey('coin_type', 'Жетон');

        self::assertGreaterThan($known, $unknown);
    }

    public function testExplicitDefaultOrderAppliesToNumericAndFixedTaxonomiesOnly(): void
    {
        $withOrder = [
            'coin_year',
            'coin_denomination',
            'coin_diameter',
            'coin_mintage_declared',
            'coin_mintage_actual',
            'coin_type',
            'coin_color',
            'coin_packaging',
        ];

        foreach ($withOrder as $taxonomy) {
            self::assertTrue(TermOrderService::hasExplicitDefaultOrder($taxonomy), $taxonomy);
        }

        foreach (['coin_series', 'coin_quality', 'coin_edge'] as $taxonomy) {
            self::assertFalse(TermOrderService::hasExplicitDefaultOrder($taxonomy), $taxonomy);
        }
    }
}
