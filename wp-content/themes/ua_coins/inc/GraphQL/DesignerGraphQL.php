<?php

namespace Coins\GraphQL;

class DesignerGraphQL
{
    public function registerTypes(): void
    {
        register_graphql_field('Designer', 'fullName', [
            'type'    => 'String',
            'resolve' => fn($source) => get_field('full_name', $source->databaseId) ?: null,
        ]);

        register_graphql_field('Designer', 'note', [
            'type'    => 'String',
            'resolve' => fn($source) => get_field('note', $source->databaseId) ?: null,
        ]);
    }
}
