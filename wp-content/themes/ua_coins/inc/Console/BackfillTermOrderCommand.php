<?php

namespace Coins\Console;

use Coins\Taxonomy\TermOrderService;
use WP_Term;
use WP_CLI;

/**
 * `wp coins backfill-term-order` — gives every coin term a starting sort position.
 *
 * Ordering is stored per term as `coin_term_order` meta (see `Taxonomy\TermOrderService`); terms
 * without it sort last, by name. That's a fine end state for a taxonomy nobody cares to order,
 * but it's a poor *starting* point for the ones with an obvious natural order — years read best
 * newest-first, and anything numeric ("2 грн", "10 грн", "38.6") sorts nonsensically by name.
 * This writes that natural order once, so an admin drags rows from a sane list rather than an
 * alphabetical one — including the fixed-term taxonomies (`coin_type`/`coin_color`/
 * `coin_packaging`), whose declared order puts Монета first where the alphabet puts Банкнота.
 *
 * Idempotent by default: a term that already has a position keeps it (someone put it there on
 * purpose). `--force` renumbers everything from the natural order, discarding manual ordering.
 */
class BackfillTermOrderCommand
{
    public static function register(): void
    {
        WP_CLI::add_command('coins backfill-term-order', self::class, [
            'shortdesc' => 'Assign the default sort position (coin_term_order) to coin taxonomy terms',
            'synopsis'  => [
                [
                    'type'        => 'assoc',
                    'name'        => 'taxonomy',
                    'optional'    => true,
                    'description' => 'Single taxonomy slug, e.g. coin_year (default: all coin taxonomies)',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'force',
                    'optional'    => true,
                    'description' => 'Renumber terms that already have a position, discarding manual ordering',
                ],
                [
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'optional'    => true,
                    'description' => 'Report what would change; do not write any term meta',
                ],
            ],
        ]);
    }

    /**
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        $dryRun = isset($assoc_args['dry-run']);
        $force  = isset($assoc_args['force']);

        $taxonomies = TermOrderService::orderedTaxonomies();

        if (isset($assoc_args['taxonomy'])) {
            $requested = sanitize_key($assoc_args['taxonomy']);

            if (!in_array($requested, $taxonomies, true)) {
                WP_CLI::error(sprintf(
                    'Таксономія %s не є таксономією монет. Доступні: %s',
                    $requested,
                    implode(', ', $taxonomies)
                ));
            }

            $taxonomies = [$requested];
        }

        $totalWritten = 0;
        $totalKept    = 0;

        foreach ($taxonomies as $taxonomy) {
            $terms = $this->terms($taxonomy);

            if (!$terms) {
                WP_CLI::log(sprintf('%s: термінів немає', $taxonomy));
                continue;
            }

            $this->sortByNaturalOrder($taxonomy, $terms);

            $written = 0;
            $kept    = 0;

            foreach ($terms as $index => $term) {
                $termId = (int) $term->term_id;

                if (!$force && null !== TermOrderService::getOrder($termId)) {
                    $kept++;
                    continue;
                }

                if (!$dryRun) {
                    TermOrderService::setOrder($termId, $index * TermOrderService::ORDER_STEP);
                }

                $written++;
            }

            $totalWritten += $written;
            $totalKept    += $kept;

            WP_CLI::log(sprintf(
                '%s: %d %s, %d вже впорядковано (перші: %s)',
                $taxonomy,
                $written,
                $dryRun ? 'потребують запису' : 'записано',
                $kept,
                implode(', ', array_map(
                    static fn (WP_Term $term): string => $term->name,
                    array_slice($terms, 0, 5)
                ))
            ));
        }

        WP_CLI::success(sprintf(
            'Таксономій: %d, %s: %d, збережено наявний порядок: %d%s',
            count($taxonomies),
            $dryRun ? 'потребують запису' : 'записано позицій',
            $totalWritten,
            $totalKept,
            $dryRun ? ' [DRY RUN]' : ''
        ));
    }

    /**
     * @return array<int,WP_Term>
     */
    private function terms(string $taxonomy): array
    {
        // `orderby => none`: this command decides the order itself, and asking for `name` would
        // route through TermOrderService's own clause filter (i.e. the order being backfilled).
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'none',
        ]);

        if (is_wp_error($terms)) {
            WP_CLI::warning(sprintf('%s: %s', $taxonomy, $terms->get_error_message()));

            return [];
        }

        return array_values(array_filter(
            (array) $terms,
            static fn ($term): bool => $term instanceof WP_Term
        ));
    }

    /**
     * @param array<int,WP_Term> $terms
     */
    private function sortByNaturalOrder(string $taxonomy, array &$terms): void
    {
        $explicit = TermOrderService::hasExplicitDefaultOrder($taxonomy);

        usort($terms, static function (WP_Term $a, WP_Term $b) use ($taxonomy, $explicit): int {
            if ($explicit) {
                $delta = TermOrderService::defaultSortKey($taxonomy, $a->name)
                    <=> TermOrderService::defaultSortKey($taxonomy, $b->name);

                if (0 !== $delta) {
                    return $delta;
                }
            }

            return strcmp(mb_strtolower($a->name), mb_strtolower($b->name));
        });
    }
}
