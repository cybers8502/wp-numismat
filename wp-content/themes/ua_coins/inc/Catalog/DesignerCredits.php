<?php

namespace Coins\Catalog;

/**
 * Parses NBU's free-text "Художник:" / "Скульптор:" card fields into people per role.
 *
 * NBU only has those two labels; everything else is prose inside the value, e.g.
 *   "аверс: Таран Володимир, Харук Олександр; реверс: Іваненко Святослав"
 *   "Володимир Дем’яненко; адаптація дизайну – Олександра Кучинська"
 *   "Атаманчук Володимир програмне моделювання: Лук`янов Юрій"
 *   "Автор ідеї – Ладний Юрій. Художник – Бєляєв Сергій. Адаптація дизайну – Балута Тетяна"
 *   "Косинський Олександр (автор ідеї), Руденко Олексій (автор ескізу); Балута Тетяна"
 * The old parser split on commas only, so whole phrases became "designers" — and its LIKE lookup
 * then attached those phrases to unrelated coins.
 *
 * - Segments split on `;`, `,`, a sentence break (". " + capital, not after an initial) and before an
 *   inline role phrase with no separator.
 * - A "label: name" / "label – name" prefix or a "(label)" suffix is dropped. The role is the field's
 *   own, except: «адаптація дизайну» → adaptation, «програмне моделювання»/«скульптор» → sculptor,
 *   «художник» → artist, a bare «дизайн» → designer. A label carries on to the following comma
 *   segments until the next `;` or label (so "Програмне моделювання: A, B" makes both sculptors).
 *   Side/credit labels (аверс, реверс, автор ідеї, дизайн монети, автори дизайну…) keep the field role.
 * - Identity (key()) ignores word order, case and apostrophes: NBU writes both "Таран Володимир" and
 *   "Володимир Таран", and «Демяненко» alongside «Дем`яненко».
 */
final class DesignerCredits
{
    public const ROLES = ['designers_artist', 'designers_designer', 'designers_adaptation', 'designers_sculptor'];

    /**
     * Same person, different spelling on NBU's side — key() can't see these (it only ignores word
     * order, case and apostrophes). Keyed by key() of the variant, value is the spelling to use.
     */
    private const ALIASES = [
        'котовіч роберт'  => 'Котович Роберт',
        'кочубей миколай' => 'Кочубей Микола',
    ];

    /**
     * Given names, to tell "Ім'я Прізвище" from "Прізвище Ім'я" — NBU writes both, the catalog shows
     * "Прізвище Ім'я". Seeded from every designer NBU has credited, plus common Ukrainian names;
     * a two-word name whose first word is here and second isn't gets swapped (displayName()).
     */
    private const GIVEN_NAMES = [
        'аліна', 'аліса', 'анатолій', 'анджей', 'андрій', 'анна', 'борис', 'вадим', 'валерій', 'валентина',
        'василь', 'вероніка', 'віктор', 'вікторія', 'віталій', 'владислав', 'володимир', 'вячеслав',
        'галина', 'григорій', 'дмитро', 'джон', 'драгомир', 'євген', 'есма', 'іван', 'ігор', 'ірина',
        'катерина', 'кріста', 'лариса', 'леонід', 'любов', 'людмила', 'максим', 'марина', 'марія',
        'микола', 'михайло', 'надія', 'наталія', 'нікіта', 'оксана', 'олег', 'олександр', 'олександра',
        'олексій', 'олена', 'ольга', 'павло', 'петро', 'роберт', 'роман', 'світлана', 'святослав',
        'сергій', 'софія', 'тарас', 'тетяна', 'штефан', 'юлія', 'юрій', 'ян', 'яна', 'ярослав',
    ];

    /** Words that make a "label" a label rather than part of a name. */
    private const LABEL_WORDS = '(?:аверс|реверс|дизайн|автор|художник|скульптор|моделюван|адаптац)';

    /**
     * @param array<string,?string> $raw role => raw NBU field text
     * @return array<string,array<int,string>> role => clean names (every role key present)
     */
    public static function parse(array $raw): array
    {
        $result = array_fill_keys(self::ROLES, []);

        foreach ($raw as $fieldRole => $text) {
            $text = self::cleanText((string) $text);
            if ($text === '') {
                continue;
            }

            foreach (self::splitGroups($text) as $group) {
                $carried = $fieldRole;
                foreach (self::splitSegments($group) as $segment) {
                    [$role, $name] = self::parseSegment($segment, $fieldRole, $carried);
                    $carried       = $role;
                    $name          = self::ALIASES[self::key($name)] ?? $name;
                    if ($name !== '') {
                        $result[in_array($role, self::ROLES, true) ? $role : 'designers_designer'][] = $name;
                    }
                }
            }
        }

        foreach ($result as $role => $names) {
            $unique = [];
            foreach ($names as $name) {
                $unique[self::key($name)] ??= $name;
            }
            $result[$role] = array_values($unique);
        }

        return $result;
    }

    /** Identity of a person: lowercase words, apostrophes dropped, order-insensitive. */
    public static function key(string $name): string
    {
        $name  = mb_strtolower(str_replace(['’', "'", '`', 'ʼ'], '', $name), 'UTF-8');
        $words = preg_split('~\s+~u', trim($name)) ?: [];
        sort($words);

        return implode(' ', $words);
    }

    /** "Олександра Кучинська" → "Кучинська Олександра"; anything else unchanged. */
    public static function displayName(string $name): string
    {
        $words = explode(' ', $name);
        if (count($words) !== 2) {
            return $name;
        }
        $isGiven = fn (string $w): bool => in_array(mb_strtolower($w, 'UTF-8'), self::GIVEN_NAMES, true);

        return $isGiven($words[0]) && !$isGiven($words[1]) ? $words[1] . ' ' . $words[0] : $name;
    }

    public static function cleanName(string $name): string
    {
        // Not trim(): its char list is bytewise, and «–»/«—» share bytes with Cyrillic letters.
        $name = (string) preg_replace(['~\s+~u', '~^[\s,;:–—-]+|[\s,;:–—-]+$~u'], [' ', ''], $name);
        // Trailing dot is punctuation — unless the last word is an initial ("Цанашка А.").
        if (str_ends_with($name, '.') && !preg_match('~(?:^|\s)\p{Lu}\.$~u', $name)) {
            $name = rtrim($name, '. ');
        }
        if (
            in_array(mb_strtolower($name), ['undefined', 'null', 'nan'], true)
            || !preg_match('~\p{L}~u', $name)
            || preg_match('~(?:^|\s)' . self::LABEL_WORDS . '~iu', $name) // a label left without a name
        ) {
            return '';
        }

        return $name;
    }

    private static function cleanText(string $text): string
    {
        $text = str_replace(['`', "'", 'ʼ'], '’', $text);
        // "ОлександраКучинська" — a lost space between two words.
        $text = (string) preg_replace('~(\p{Ll})(\p{Lu})~u', '$1 $2', $text);

        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }

    /** @return array<int,string> `;`-separated groups, with sentence breaks treated the same way. */
    private static function splitGroups(string $text): array
    {
        // ". " before a capital is a sentence break, unless the dot closes an initial ("Д. Лизунов").
        $text = (string) preg_replace('~(?<!\s\p{Lu})\.\s+(?=\p{Lu})~u', ';', $text);

        return array_values(array_filter(array_map('trim', explode(';', $text)), fn (string $s): bool => $s !== ''));
    }

    /** @return array<int,string> */
    private static function splitSegments(string $group): array
    {
        // An inline role phrase with no separator in front of it starts a new segment.
        // Not after a conjunction: "Автор ідеї та адаптація дизайну – Андрій Сагач" is one credit.
        $group = (string) preg_replace('~(?<=\p{L})(?<!\sта)(?<!\sі)(?<!\sй)\s+(?=(?:програмне моделювання|адаптація дизайну)\b)~iu', ',', $group);

        return array_values(array_filter(array_map('trim', explode(',', $group)), fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array{0:string,1:string} [role, name]
     */
    private static function parseSegment(string $segment, string $fieldRole, string $carried): array
    {
        $role = $carried;

        // "(автор ідеї)" / "(дизайн)" suffix
        if (preg_match('~^(.*?)\s*\(([^)]*)\)\s*$~u', $segment, $m) && preg_match('~' . self::LABEL_WORDS . '~iu', $m[2])) {
            $segment = $m[1];
            $role    = self::roleForLabel($m[2], $fieldRole);
        }

        // "label: name" / "label – name" (a hyphen only counts with spaces, so "Дерегус-Лоренс" survives)
        if (preg_match('~^([^:–—]*?)\s*(?::|\s[–—-]\s|[–—])\s*(.+)$~u', $segment, $m) && preg_match('~' . self::LABEL_WORDS . '~iu', $m[1])) {
            $segment = $m[2];
            $role    = self::roleForLabel($m[1], $fieldRole);
        }

        return [$role, self::cleanName($segment)];
    }

    private static function roleForLabel(string $label, string $fieldRole): string
    {
        $label = mb_strtolower(trim($label), 'UTF-8');

        if (str_contains($label, 'адаптац')) {
            return 'designers_adaptation';
        }
        if (str_contains($label, 'моделюван') || str_contains($label, 'скульптор')) {
            return 'designers_sculptor';
        }
        if (str_contains($label, 'художник')) {
            return 'designers_artist';
        }
        if ($label === 'дизайн') {
            return 'designers_designer';
        }

        return $fieldRole; // аверс, реверс, автор ідеї, дизайн монети, автори дизайну…
    }
}
