<?php

namespace Coins\GraphQL;

use Coins\Catalog\SortKeyService;

/**
 * `catalogSort` — server-side ordering for every column of the apps' coin table.
 *
 * The stock `PostObjectsConnectionOrderbyEnum` only names standard post columns, so a catalog
 * sorted by metal, quality, denomination, mintage or price could previously only be reordered on
 * the client, over whatever pages happened to be loaded. This adds one `where` arg that covers
 * all of them by pointing WP_Query at the denormalised keys SortKeyService maintains.
 *
 * Deliberately expressed as `meta_key` + `orderby: meta_value|meta_value_num` rather than a
 * custom SQL ORDER BY: that is the shape WPGraphQL's cursor can reproduce, so `after:`
 * pagination stays correct across pages. See SortKeyService's own docblock.
 *
 * Registered on all four coin connections the catalog screen routes through (root `coins`, plus
 * the coinYear/coinType/coinDenomination sub-connections) — type names verified against the live
 * schema, since `register_graphql_field()` on a name that does not exist fails silently.
 */
class CatalogSortGraphQL
{
    private const WHERE_ARG = 'catalogSort';

    private const WHERE_TYPES = [
        'RootQueryToCoinConnectionWhereArgs',
        'CoinYearToCoinConnectionWhereArgs',
        'CoinTypeToCoinConnectionWhereArgs',
        'CoinDenominationToCoinConnectionWhereArgs',
    ];

    /**
     * GraphQL enum value => the meta key to order by, or null for the one column that is a real
     * post column (year, via `post_date` — the NBU importer writes `post_date = issue_date`).
     *
     * `numeric` picks `meta_value_num` over `meta_value`, i.e. 1000 sorts above 999 instead of
     * below it.
     */
    private const FIELDS = [
        'TITLE'        => ['meta' => SortKeyService::META_TITLE,        'numeric' => false],
        'YEAR'         => ['meta' => null,                              'numeric' => false],
        'DENOMINATION' => ['meta' => SortKeyService::META_DENOMINATION, 'numeric' => false],
        'MATERIAL'     => ['meta' => SortKeyService::META_MATERIAL,     'numeric' => false],
        'QUALITY'      => ['meta' => SortKeyService::META_QUALITY,      'numeric' => false],
        'MINTAGE'      => ['meta' => SortKeyService::META_MINTAGE,      'numeric' => true],
        'PRICE'        => ['meta' => SortKeyService::META_PRICE,        'numeric' => true],
    ];

    public function registerTypes(): void
    {
        register_graphql_enum_type('CatalogSortField', [
            'description' => 'Column of the catalog table to order by. Every value but YEAR reads a '
                . 'denormalised sort key kept in post meta by SortKeyService; YEAR orders by '
                . 'post_date, which the NBU importer keeps equal to issue_date.',
            'values'      => [
                'TITLE'        => ['value' => 'TITLE',        'description' => 'shortTitle, falling back to the post title'],
                'YEAR'         => ['value' => 'YEAR',         'description' => 'Issue date'],
                'DENOMINATION' => ['value' => 'DENOMINATION', 'description' => 'First coin_denomination term, in the order set in wp-admin'],
                'MATERIAL'     => ['value' => 'MATERIAL',     'description' => 'First coin_material term, in the order set in wp-admin'],
                'QUALITY'      => ['value' => 'QUALITY',      'description' => 'First coin_quality term, in the order set in wp-admin'],
                'MINTAGE'      => ['value' => 'MINTAGE',      'description' => 'mintage_actual, falling back to mintage_declared'],
                'PRICE'        => ['value' => 'PRICE',        'description' => 'Latest market price, same value priceStats.latestPrice returns'],
            ],
        ]);

        register_graphql_input_type('CatalogSortInput', [
            'description' => 'Server-side ordering for the catalog table.',
            'fields'      => [
                'field' => ['type' => ['non_null' => 'CatalogSortField']],
                'order' => ['type' => 'OrderEnum', 'description' => 'ASC or DESC; defaults to ASC.'],
            ],
        ]);

        foreach (self::WHERE_TYPES as $whereType) {
            register_graphql_field($whereType, self::WHERE_ARG, [
                'type'        => 'CatalogSortInput',
                'description' => 'Order the whole filtered catalog server-side. Takes precedence '
                    . 'over `orderby` when both are given.',
            ]);
        }

        // Priority 10 is enough: WPGraphQL applies this filter as the very last step of
        // get_query_args(), so whatever it defaulted `orderby`/`order` to is already in place and
        // this overrides it rather than racing it.
        add_filter('graphql_map_input_fields_to_wp_query', [$this, 'mapToQueryArgs'], 10, 4);
    }

    /**
     * @param array<string,mixed> $queryArgs
     * @param array<string,mixed> $whereArgs
     * @param mixed               $source
     * @param array<string,mixed> $allArgs
     *
     * @return array<string,mixed>
     */
    public function mapToQueryArgs($queryArgs, $whereArgs, $source = null, $allArgs = [])
    {
        $sort = $whereArgs[self::WHERE_ARG] ?? null;

        if (!is_array($sort) || empty($sort['field']) || !isset(self::FIELDS[$sort['field']])) {
            return $queryArgs;
        }

        $order = strtoupper((string) ($sort['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        // Backward pagination runs the query inverted and reverses the rows afterwards, so the
        // requested direction has to be flipped — exactly what PostObjectConnectionResolver does
        // for its own `orderby` input. Skipping this would make `last:`/`before:` return the far
        // end of the catalog instead of the page before the cursor.
        if (!empty($allArgs['last'])) {
            $order = $order === 'ASC' ? 'DESC' : 'ASC';
        }

        $spec = self::FIELDS[$sort['field']];

        // `orderby` as a plain string (not the [field => order] map WPGraphQL builds for its own
        // enum): PostObjectCursor::to_sql() reads that shape too, and routes a string through
        // compare_with(), which is what reaches compare_with_meta_field() for meta_value*.
        $queryArgs['order'] = $order;

        if ($spec['meta'] === null) {
            // `post_date`, not the equivalent `date`: both are valid WP_Query orderby keys, but
            // only `post_date` is in PostObjectCursor's own list of post fields, so only it makes
            // the cursor compare the right column instead of falling through to its default.
            $queryArgs['orderby'] = 'post_date';

            return $queryArgs;
        }

        $queryArgs['meta_key'] = $spec['meta'];
        $queryArgs['orderby']  = $spec['numeric'] ? 'meta_value_num' : 'meta_value';

        return $queryArgs;
    }
}
