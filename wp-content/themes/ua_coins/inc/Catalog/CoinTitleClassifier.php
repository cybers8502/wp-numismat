<?php

namespace Coins\Catalog;

/**
 * Derives a coin's `coin_type` and `coin_packaging` terms from its NBU title — NBU has no
 * structured field for either.
 *
 * **Packaging is not a type.** "… у сувенірному пакованні" / "… у сувенірній упаковці" is a coin
 * (or banknote, or medal) sold in a box: its type is what's inside, and the box is recorded as
 * packaging. Type used to be "Сувенірна продукція" for anything with «сувенір» in the title, checked
 * first, so every boxed coin and every «сувенірна банкнота» landed there. «Сувенірна продукція» is
 * now only for a title that says «сувенір» outside that packaging phrase and isn't a banknote,
 * medal or investment coin — none in the catalog as of this change.
 */
final class CoinTitleClassifier
{
    public const TYPE_COIN       = 'Монета';
    public const TYPE_BANKNOTE   = 'Банкнота';
    public const TYPE_MEDAL      = 'Медаль';
    public const TYPE_INVESTMENT = 'Інвестиційна';
    public const TYPE_SOUVENIR   = 'Сувенірна продукція';

    public const PACKAGING_SET      = 'Набір';
    public const PACKAGING_ROLL     = 'Ролик';
    public const PACKAGING_SOUVENIR = 'В сувенірному пакуванні';
    public const PACKAGING_NONE     = 'Без пакування';

    /** Both spellings NBU uses: «у сувенірному пакованні» (newer) and «у сувенірній упаковці» (older). */
    private const SOUVENIR_PACKAGING = '~сувенірн\S*\s+(пакован|пакуван|упаков)~u';

    public static function type(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');

        if (mb_strpos($title, 'банкнот') !== false) {
            return self::TYPE_BANKNOTE;
        }
        if (mb_strpos($title, 'медал') !== false) {
            return self::TYPE_MEDAL;
        }
        if (mb_strpos($title, 'інвестиційн') !== false) {
            return self::TYPE_INVESTMENT;
        }
        if (mb_strpos($title, 'сувенір') !== false && !self::inSouvenirPackaging($title)) {
            return self::TYPE_SOUVENIR;
        }

        return self::TYPE_COIN;
    }

    public static function packaging(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');

        if (mb_strpos($title, 'набір') !== false) {
            return self::PACKAGING_SET;
        }
        if (mb_strpos($title, 'ролик') !== false) {
            return self::PACKAGING_ROLL;
        }
        if (self::inSouvenirPackaging($title)) {
            return self::PACKAGING_SOUVENIR;
        }

        return self::PACKAGING_NONE;
    }

    private static function inSouvenirPackaging(string $lowerTitle): bool
    {
        return (bool) preg_match(self::SOUVENIR_PACKAGING, $lowerTitle);
    }
}
