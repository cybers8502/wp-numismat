<?php

namespace Coins\GraphQL;

class CatalogGraphQL
{
    /** Coin taxonomy slugs — must match CoinPostTypeRegistrar::registerCoinTaxonomies(). */
    private const TAX_TYPE         = 'coin_type';
    private const TAX_DENOMINATION = 'coin_denomination';
    private const TAX_MATERIAL     = 'coin_material';
    private const TAX_YEAR         = 'coin_year';

    public function registerTypes(): void
    {
        $this->registerSharedTypes();
        $this->registerQueries();
    }

    private function registerSharedTypes(): void
    {
        register_graphql_object_type('CatalogCounts', [
            'description' => 'Real coin counts for the catalog\'s "Показано N з M" line — WPGraphQL\'s '
                . 'coins connection has no aggregate/total-count field and no taxQuery to combine '
                . 'facets server-side, so this exists specifically to give the client both numbers '
                . 'in one round trip instead of client-side-filtering whatever page happens to be loaded.',
            'fields'      => [
                'matched' => ['type' => 'Int', 'description' => 'Coins matching the given filters.'],
                'total'   => ['type' => 'Int', 'description' => 'All published coins, unconditional.'],
            ],
        ]);
    }

    private function registerQueries(): void
    {
        register_graphql_field('RootQuery', 'catalogCounts', [
            'type'        => 'CatalogCounts',
            'description' => 'Matched vs. total coin counts for the catalog screen\'s counter line. '
                . 'Filters mirror CatalogScreen\'s client-side facets (type, denomination, material, '
                . 'year, search). Year became filterable here once the coin_year taxonomy was added '
                . '(it mirrors the issue_date ACF field as a real term) — before that it had no '
                . 'indexed/queryable field to filter on server-side.',
            'args'        => [
                'search' => [
                    'type'        => 'String',
                    'description' => 'Same free-text search used by the coins/coinType/coinDenomination queries.',
                ],
                'typeSlug' => [
                    'type'        => 'String',
                    'description' => 'coin_type term slug, e.g. "монета".',
                ],
                'denominations' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Included coin_denomination slugs (OR\'d together).',
                ],
                'excludedDenominations' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Excluded coin_denomination slugs.',
                ],
                'materials' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Included coin_material slugs (OR\'d together).',
                ],
                'excludedMaterials' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Excluded coin_material slugs.',
                ],
                'years' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Included coin_year slugs, e.g. "2024" (OR\'d together).',
                ],
                'excludedYears' => [
                    'type'        => ['list_of' => 'String'],
                    'description' => 'Excluded coin_year slugs.',
                ],
            ],
            'resolve' => [$this, 'resolveCatalogCounts'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Resolvers
    // -------------------------------------------------------------------------

    public function resolveCatalogCounts($root, array $args): array
    {
        return [
            'matched' => $this->countCoins($args),
            // Unconditional — deliberately re-queried per request rather than cached, since a
            // WP_Query "fields => ids, posts_per_page => 1" count is cheap (index-only count via
            // SQL_CALC_FOUND_ROWS) and this keeps the number always fresh.
            'total' => $this->countCoins([]),
        ];
    }

    /**
     * @param array{
     *   search?: string|null,
     *   typeSlug?: string|null,
     *   denominations?: string[]|null,
     *   excludedDenominations?: string[]|null,
     *   materials?: string[]|null,
     *   excludedMaterials?: string[]|null,
     *   years?: string[]|null,
     *   excludedYears?: string[]|null,
     * } $args
     */
    private function countCoins(array $args): int
    {
        $tax_query = [];

        if (!empty($args['typeSlug'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_TYPE,
                'field'    => 'slug',
                'terms'    => [$args['typeSlug']],
            ];
        }

        if (!empty($args['denominations'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_DENOMINATION,
                'field'    => 'slug',
                'terms'    => $args['denominations'],
                'operator' => 'IN',
            ];
        }

        if (!empty($args['excludedDenominations'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_DENOMINATION,
                'field'    => 'slug',
                'terms'    => $args['excludedDenominations'],
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($args['materials'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_MATERIAL,
                'field'    => 'slug',
                'terms'    => $args['materials'],
                'operator' => 'IN',
            ];
        }

        if (!empty($args['excludedMaterials'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_MATERIAL,
                'field'    => 'slug',
                'terms'    => $args['excludedMaterials'],
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($args['years'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_YEAR,
                'field'    => 'slug',
                'terms'    => $args['years'],
                'operator' => 'IN',
            ];
        }

        if (!empty($args['excludedYears'])) {
            $tax_query[] = [
                'taxonomy' => self::TAX_YEAR,
                'field'    => 'slug',
                'terms'    => $args['excludedYears'],
                'operator' => 'NOT IN',
            ];
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        $query_args = [
            'post_type'              => 'coins',
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if (!empty($args['search'])) {
            $query_args['s'] = $args['search'];
        }

        if ($tax_query) {
            $query_args['tax_query'] = $tax_query;
        }

        return (int) (new \WP_Query($query_args))->found_posts;
    }
}
