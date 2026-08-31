<?php

namespace Coins\GraphQL;

use GraphQL\Type\Definition\ResolveInfo;

/**
 * WPGraphQL caps every connection at 100 nodes per request
 * (`graphql_connection_max_query_amount`). r-numismat needs the full coins
 * catalog in a single query to build its client-side filter, so raise the cap
 * for the root `coins` connection only — every other connection keeps the
 * default 100.
 */
class ConnectionLimits
{
    /** Enough headroom for the whole catalog (~1.1k coins today). */
    private const COINS_MAX = 5000;

    public function boot(): void
    {
        add_filter('graphql_connection_max_query_amount', [$this, 'raiseCoinsLimit'], 10, 5);
    }

    /**
     * @param int          $maxAmount
     * @param mixed        $source
     * @param array<mixed> $args
     * @param mixed        $context
     * @param ResolveInfo  $info
     * @return int
     */
    public function raiseCoinsLimit($maxAmount, $source, $args, $context, $info)
    {
        // Root `coins` connection only: no parent node, field named "coins".
        if ($source === null && $info instanceof ResolveInfo && $info->fieldName === 'coins') {
            return self::COINS_MAX;
        }

        return $maxAmount;
    }
}
