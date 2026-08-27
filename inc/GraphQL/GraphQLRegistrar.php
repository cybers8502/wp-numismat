<?php

namespace Coins\GraphQL;

class GraphQLRegistrar
{
    public function boot(): void
    {
        add_action('graphql_register_types', [$this, 'register']);
    }

    public function register(): void
    {
        (new CoinGraphQL())->registerTypes();
        (new DesignerGraphQL())->registerTypes();
        (new CollectionGraphQL())->registerTypes();
        (new AuthGraphQL())->registerTypes();
    }
}