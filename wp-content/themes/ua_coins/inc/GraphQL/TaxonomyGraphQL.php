<?php

namespace Coins\GraphQL;

use Coins\Admin\PostTypes\CoinPostTypeRegistrar;
use Coins\Taxonomy\TermOrderService;
use WPGraphQL\Model\Term;

/**
 * Coin taxonomy terms: the `termOrder` field behind admin-controlled facet ordering.
 *
 * The ordering itself is applied server-side for every term query (see
 * `Taxonomy\TermOrderService`), so a client that just reads `coinMaterials { nodes { … } }` in
 * order already gets it — this field exists so a client can *tell* whether an order was ever set.
 * expo-numismat needs that for years: it sorted them newest-first on its own long before this
 * existed, and must keep doing so against a backend where `wp coins backfill-term-order` hasn't
 * run yet (every term null → fall back to the client sort), while stepping out of the way as soon
 * as the terms carry a real order.
 */
class TaxonomyGraphQL
{
    public function registerTypes(): void
    {
        foreach (CoinPostTypeRegistrar::taxonomies() as $config) {
            register_graphql_field(ucfirst($config['graphql_single_name']), 'termOrder', [
                'type'        => 'Int',
                'description' => 'Manual sort position set in wp-admin (lower comes first). Null '
                    . 'when the term has never been ordered — those sort after every ordered term, '
                    . 'by name. Terms are already returned in this order unless the query asks for '
                    . 'an explicit `orderby`.',
                'resolve'     => static function ($term) {
                    if (!$term instanceof Term || !isset($term->databaseId)) {
                        return null;
                    }

                    return TermOrderService::getOrder((int) $term->databaseId);
                },
            ]);
        }
    }
}
