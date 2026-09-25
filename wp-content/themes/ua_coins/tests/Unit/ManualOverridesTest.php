<?php

declare(strict_types=1);

namespace Coins\Tests\Unit;

use Coins\Catalog\ManualOverrides;
use PHPUnit\Framework\TestCase;

/**
 * An untouched field must never read as changed — otherwise every admin save would lock it and the
 * importer would silently stop updating it. These are the shapes that differ between what the
 * importer stores and what the edit screen submits back.
 */
final class ManualOverridesTest extends TestCase
{
    public function testDatePickerYmdEqualsImportedIsoDate(): void
    {
        $this->assertSame(
            ManualOverrides::normalize('date_picker', '2026-07-29'),
            ManualOverrides::normalize('date_picker', '20260729')
        );
    }

    public function testWysiwygIgnoresTinyMceMarkup(): void
    {
        $this->assertSame(
            ManualOverrides::normalize('wysiwyg', "Пам’ятна монета\nприсвячена &amp; дрону"),
            ManualOverrides::normalize('wysiwyg', '<p>Пам’ятна монета присвячена &amp; дрону</p>')
        );
    }

    public function testTaxonomyIntEqualsSubmittedString(): void
    {
        $this->assertSame(ManualOverrides::normalize('taxonomy', 2), ManualOverrides::normalize('taxonomy', '2'));
        $this->assertSame(ManualOverrides::normalize('taxonomy', null), ManualOverrides::normalize('taxonomy', ''));
    }

    public function testRealChangesStillDiffer(): void
    {
        $this->assertNotSame(ManualOverrides::normalize('taxonomy', 2), ManualOverrides::normalize('taxonomy', '4'));
        $this->assertNotSame(ManualOverrides::normalize('gallery', ['1', '2']), ManualOverrides::normalize('gallery', ['2', '1']));
        $this->assertNotSame(ManualOverrides::normalize('text', 'a'), ManualOverrides::normalize('text', 'b'));
    }
}
