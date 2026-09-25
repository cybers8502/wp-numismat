<?php

namespace Coins\Console;

use Coins\Catalog\CoinTitleClassifier;
use Coins\Catalog\ManualOverrides;
use WP_CLI;

/**
 * `wp coins backfill-types` — re-derives `coin_type` and `coin_packaging` from every coin's title
 * with CoinTitleClassifier, the same rule `wp nbu parse-souvenir` applies on each import.
 *
 * Exists for the rule change that stopped treating souvenir packaging as a type (boxed coins →
 * Монета, souvenir banknotes → Банкнота) and taught packaging the older «у сувенірній упаковці»
 * spelling; the nightly import would get there too, but only for coins NBU still lists. Like the
 * import, it leaves alone a field changed by hand in wp-admin (ManualOverrides). Idempotent.
 */
class BackfillCoinTypesCommand
{
    private const TAXONOMIES = ['coin_type', 'coin_packaging'];

    public static function register(): void
    {
        WP_CLI::add_command('coins backfill-types', self::class, [
            'shortdesc' => 'Re-derive coin_type and coin_packaging from every coin title',
            'synopsis'  => [
                [
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'optional'    => true,
                    'description' => 'List the changes, write nothing',
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
        $dry = isset($assoc_args['dry-run']);

        $ids = get_posts([
            'post_type'      => 'coins',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $changed = [];
        foreach ($ids as $id) {
            $title  = html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8');
            $target = [
                'coin_type'      => CoinTitleClassifier::type($title),
                'coin_packaging' => CoinTitleClassifier::packaging($title),
            ];

            foreach (self::TAXONOMIES as $taxonomy) {
                if (ManualOverrides::isLocked((int) $id, $taxonomy)) {
                    continue;
                }
                $current = wp_get_post_terms($id, $taxonomy, ['fields' => 'names']);
                $current = is_array($current) && $current ? $current[0] : '—';
                if ($current === $target[$taxonomy]) {
                    continue;
                }

                $key             = sprintf('%s: %s → %s', $taxonomy, $current, $target[$taxonomy]);
                $changed[$key][] = (int) $id;

                if (!$dry) {
                    $term = get_term_by('name', $target[$taxonomy], $taxonomy);
                    if (!$term) {
                        WP_CLI::warning(sprintf('#%d: терм «%s» (%s) не існує', $id, $target[$taxonomy], $taxonomy));
                        continue;
                    }
                    wp_set_post_terms($id, [$term->term_id], $taxonomy, false);
                }
            }
        }

        foreach ($changed as $key => $postIds) {
            WP_CLI::log(sprintf('%s: %d (напр. #%s)', $key, count($postIds), implode(', #', array_slice($postIds, 0, 5))));
        }

        $total = array_sum(array_map('count', $changed));
        WP_CLI::success(sprintf($dry ? 'Dry run — змінилося б %d призначень.' : 'Оновлено %d призначень.', $total));
    }
}
