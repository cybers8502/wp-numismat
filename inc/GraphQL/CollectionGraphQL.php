<?php

namespace Coins\GraphQL;

use GraphQL\Error\UserError;

class CollectionGraphQL
{
    public function registerTypes(): void
    {
        $this->registerSharedTypes();
        $this->registerQueries();
        $this->registerMutations();
    }

    private function registerSharedTypes(): void
    {
        register_graphql_object_type('CollectionItem', [
            'description' => 'A coin in a user\'s collection',
            'fields'      => [
                'id'            => ['type' => 'Int'],
                'coinId'        => ['type' => 'Int'],
                'coinTitle'     => ['type' => 'String'],
                'coinThumbnail' => ['type' => 'String'],
                'quantity'      => ['type' => 'Int'],
                'purchasePrice' => ['type' => 'Float'],
            ],
        ]);

        register_graphql_object_type('CollectionStats', [
            'description' => 'Aggregated stats of a user\'s collection',
            'fields'      => [
                'uniqueCoins'   => ['type' => 'Int'],
                'totalQuantity' => ['type' => 'Int'],
                'totalSpent'    => ['type' => 'Float'],
            ],
        ]);

        register_graphql_object_type('AddToCollectionPayload', [
            'description' => 'Result of addToCollection mutation',
            'fields'      => [
                'success' => ['type' => 'Boolean'],
            ],
        ]);

        register_graphql_object_type('DeleteCollectionItemPayload', [
            'description' => 'Result of deleteCollectionItem mutation',
            'fields'      => [
                'success' => ['type' => 'Boolean'],
                'id'      => ['type' => 'Int', 'description' => 'ID of the deleted collection item'],
            ],
        ]);
    }

    private function registerQueries(): void
    {
        register_graphql_field('RootQuery', 'myCollection', [
            'type'        => ['list_of' => 'CollectionItem'],
            'description' => 'Coins in the current user\'s collection. Requires authentication.',
            'resolve'     => [$this, 'resolveMyCollection'],
        ]);

        register_graphql_field('RootQuery', 'myCollectionStats', [
            'type'        => 'CollectionStats',
            'description' => 'Aggregated stats for the current user\'s collection. Requires authentication.',
            'resolve'     => [$this, 'resolveMyCollectionStats'],
        ]);
    }

    private function registerMutations(): void
    {
        register_graphql_mutation('addToCollection', [
            'description'         => 'Add a coin to the current user\'s collection. Requires authentication.',
            'inputFields'         => [
                'coinId' => [
                    'type'        => ['non_null' => 'Int'],
                    'description' => 'Post ID of the coin to add.',
                ],
            ],
            'outputFields'        => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => [$this, 'resolveAddToCollection'],
        ]);

        register_graphql_mutation('updateCollectionItem', [
            'description'         => 'Update quantity and/or purchase price of a collection item. Requires authentication.',
            'inputFields'         => [
                'id' => [
                    'type'        => ['non_null' => 'Int'],
                    'description' => 'ID of the collection item to update.',
                ],
                'quantity' => [
                    'type'        => 'Int',
                    'description' => 'New quantity (must be at least 1).',
                ],
                'purchasePrice' => [
                    'type'        => 'Float',
                    'description' => 'New purchase price.',
                ],
            ],
            'outputFields'        => [
                'item' => ['type' => 'CollectionItem'],
            ],
            'mutateAndGetPayload' => [$this, 'resolveUpdateCollectionItem'],
        ]);

        register_graphql_mutation('deleteCollectionItem', [
            'description'         => 'Remove a coin from the current user\'s collection. Requires authentication.',
            'inputFields'         => [
                'id' => [
                    'type'        => ['non_null' => 'Int'],
                    'description' => 'ID of the collection item to delete.',
                ],
            ],
            'outputFields'        => [
                'success' => ['type' => 'Boolean'],
                'id'      => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => [$this, 'resolveDeleteCollectionItem'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Resolvers
    // -------------------------------------------------------------------------

    public function resolveMyCollection(): array
    {
        if (!is_user_logged_in()) {
            throw new UserError('You must be logged in to view your collection.');
        }

        $query = new \WP_Query([
            'post_type'      => 'coin_collection',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [[
                'key'   => 'user_id',
                'value' => get_current_user_id(),
                'type'  => 'NUMERIC',
            ]],
        ]);

        return array_map(fn($post) => $this->formatItem($post->ID), $query->posts);
    }

    public function resolveMyCollectionStats(): array
    {
        if (!is_user_logged_in()) {
            throw new UserError('You must be logged in to view collection stats.');
        }

        $query = new \WP_Query([
            'post_type'      => 'coin_collection',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'   => 'user_id',
                'value' => get_current_user_id(),
                'type'  => 'NUMERIC',
            ]],
        ]);

        $unique   = 0;
        $quantity = 0;
        $spent    = 0.0;

        foreach ($query->posts as $post_id) {
            $qty   = (int) get_field('quantity', $post_id);
            $price = get_field('purchase_price', $post_id);

            $unique++;
            $quantity += $qty;

            if ($price !== '' && $price !== false) {
                $spent += (float) $price * $qty;
            }
        }

        return [
            'uniqueCoins'   => $unique,
            'totalQuantity' => $quantity,
            'totalSpent'    => round($spent, 2),
        ];
    }

    public function resolveAddToCollection(array $input): array
    {
        if (!is_user_logged_in()) {
            throw new UserError('You must be logged in to add coins to your collection.');
        }

        $coin_id = (int) $input['coinId'];
        $user_id = get_current_user_id();

        if (get_post_type($coin_id) !== 'coins') {
            throw new UserError('Invalid coin ID.');
        }

        // If the coin is already in the collection — increment quantity
        $existing = $this->findExistingItem($user_id, $coin_id);
        if ($existing) {
            $quantity = (int) get_field('quantity', $existing) ?: 0;
            update_field('quantity', $quantity + 1, $existing);

            return ['success' => true];
        }

        // Create new collection entry
        $post_id = wp_insert_post([
            'post_type'   => 'coin_collection',
            'post_status' => 'publish',
            'post_title'  => 'Collection item',
        ]);

        if (is_wp_error($post_id)) {
            throw new UserError('Failed to create collection entry.');
        }

        update_field('user_id', $user_id, $post_id);
        update_field('coin_id', $coin_id, $post_id);
        update_field('quantity', 1, $post_id);

        return ['success' => true];
    }

    public function resolveUpdateCollectionItem(array $input): array
    {
        $item_id = (int) $input['id'];
        $this->authorizeItem($item_id);

        if (array_key_exists('quantity', $input)) {
            $quantity = (int) $input['quantity'];
            if ($quantity < 1) {
                throw new UserError('Quantity must be at least 1.');
            }
            update_field('quantity', $quantity, $item_id);
        }

        if (array_key_exists('purchasePrice', $input)) {
            update_field('purchase_price', (float) $input['purchasePrice'], $item_id);
        }

        return ['item' => $this->formatItem($item_id)];
    }

    public function resolveDeleteCollectionItem(array $input): array
    {
        $item_id = (int) $input['id'];
        $this->authorizeItem($item_id);

        wp_delete_post($item_id, true);

        return ['success' => true, 'id' => $item_id];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findExistingItem(int $user_id, int $coin_id): ?int
    {
        $query = new \WP_Query([
            'post_type'      => 'coin_collection',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => 'user_id',
                    'value' => $user_id,
                    'type'  => 'NUMERIC',
                ],
                [
                    'key'   => 'coin_id',
                    'value' => $coin_id,
                    'type'  => 'NUMERIC',
                ],
            ],
        ]);

        return $query->posts[0] ?? null;
    }

    private function authorizeItem(int $item_id): void
    {
        if (!is_user_logged_in()) {
            throw new UserError('You must be logged in to manage your collection.');
        }

        $post = get_post($item_id);

        if (!$post || $post->post_type !== 'coin_collection' || $post->post_status !== 'publish') {
            throw new UserError('Collection item not found.');
        }

        $owner = (int) get_field('user_id', $item_id);
        if ($owner !== get_current_user_id()) {
            throw new UserError('You do not have access to this item.');
        }
    }

    private function formatItem(int $post_id): array
    {
        $coin_id = (int) get_field('coin_id', $post_id);
        $price   = get_field('purchase_price', $post_id);

        return [
            'id'            => $post_id,
            'coinId'        => $coin_id,
            'coinTitle'     => get_the_title($coin_id),
            'coinThumbnail' => get_the_post_thumbnail_url($coin_id, 'medium') ?: null,
            'quantity'      => (int) get_field('quantity', $post_id),
            'purchasePrice' => $price !== '' && $price !== false ? (float) $price : null,
        ];
    }
}
