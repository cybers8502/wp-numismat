<?php

namespace Coins\GraphQL\Fields;

class CoinFieldsRegistrar
{
    public function register(): void
    {
        $this->registerAcfFields();
        $this->registerGallery();
        $this->registerDesigners();
        $this->registerPriceHistory();
    }

    private function registerAcfFields(): void
    {
        $fields = [
            'issueDate'       => ['type' => 'String', 'meta' => 'issue_date'],
            'bookletUrl'      => ['type' => 'String', 'meta' => 'booklet_url'],
            'descriptionHtml' => ['type' => 'String', 'meta' => 'description_html'],
            'diameterMm'      => ['type' => 'Float',  'meta' => 'diameter_mm'],
            'mintageDeclared' => ['type' => 'Int',    'meta' => 'mintage_declared'],
            'mintageActual'   => ['type' => 'Int',    'meta' => 'mintage_actual'],
        ];

        foreach ($fields as $graphql_name => ['type' => $type, 'meta' => $meta]) {
            register_graphql_field('Coin', $graphql_name, [
                'type'    => $type,
                'resolve' => function ($source) use ($meta) {
                    $value = get_field($meta, $source->databaseId);
                    return $value !== '' && $value !== false ? $value : null;
                },
            ]);
        }
    }

    private function registerGallery(): void
    {
        register_graphql_field('Coin', 'gallery', [
            'type'    => ['list_of' => 'CoinGalleryImage'],
            'resolve' => function ($source) {
                $ids = get_field('images_gallery', $source->databaseId) ?: [];
                return array_map(fn($id) => [
                    'id'     => $id,
                    'url'    => wp_get_attachment_url($id),
                    'medium' => wp_get_attachment_image_url($id, 'medium'),
                ], (array) $ids);
            },
        ]);
    }

    private function registerDesigners(): void
    {
        $roles = [
            'designersArtist'     => 'designers_artist',
            'designersDesigner'   => 'designers_designer',
            'designersAdaptation' => 'designers_adaptation',
            'designersSculptor'   => 'designers_sculptor',
        ];

        foreach ($roles as $graphql_name => $meta_key) {
            register_graphql_field('Coin', $graphql_name, [
                'type'    => ['list_of' => 'Designer'],
                'resolve' => function ($source) use ($meta_key) {
                    $ids = get_field($meta_key, $source->databaseId) ?: [];
                    return array_filter(array_map('get_post', (array) $ids));
                },
            ]);
        }
    }

    private function registerPriceHistory(): void
    {
        register_graphql_field('Coin', 'priceHistory', [
            'type'    => ['list_of' => 'CoinPriceEntry'],
            'resolve' => function ($source) {
                $query = new \WP_Query([
                    'post_type'      => 'coin_price',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'meta_key'       => 'price_date',
                    'orderby'        => 'meta_value',
                    'order'          => 'ASC',
                    'meta_query'     => [[
                        'key'   => 'coin_id',
                        'value' => $source->databaseId,
                        'type'  => 'NUMERIC',
                    ]],
                ]);

                return array_map(fn($post) => [
                    'id'     => $post->ID,
                    'date'   => get_field('price_date', $post->ID),
                    'price'  => (float) get_field('price', $post->ID),
                    'source' => get_field('source', $post->ID) ?: null,
                ], $query->posts);
            },
        ]);
    }
}