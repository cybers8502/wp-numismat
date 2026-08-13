<?php

namespace Coins\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CoinController
{
    private const TAXONOMIES = [
        'coin_denomination',
        'coin_quality',
        'coin_material',
        'coin_series',
        'coin_edge',
        'coin_diameter',
        'coin_mintage_declared',
        'coin_mintage_actual',
        'coin_color',
        'coin_packaging',
    ];

    // GET /coins
    public function getList(WP_REST_Request $request): WP_REST_Response
    {
        $per_page = min((int) ($request->get_param('per_page') ?? 20), 100);
        $page     = max((int) ($request->get_param('page') ?? 1), 1);
        $search   = sanitize_text_field($request->get_param('search') ?? '');
        $orderby  = $request->get_param('orderby') ?? 'date';
        $order    = strtoupper($request->get_param('order') ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $args = [
            'post_type'      => 'coins',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => in_array($orderby, ['date', 'title', 'modified'], true) ? $orderby : 'date',
            'order'          => $order,
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }

        // Фільтри по таксономіях: ?coin_quality=15&coin_material=7,8
        $tax_query = [];
        foreach (self::TAXONOMIES as $taxonomy) {
            $raw = $request->get_param($taxonomy);
            if (!$raw) continue;

            $term_ids = array_filter(array_map('intval', explode(',', (string) $raw)));
            if (!$term_ids) continue;

            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_ids,
                'operator' => count($term_ids) > 1 ? 'IN' : 'AND',
            ];
        }

        if ($tax_query) {
            $tax_query['relation'] = 'AND';
            $args['tax_query']     = $tax_query;
        }

        $query = new \WP_Query($args);

        $items = array_map(
            fn($post) => $this->formatListItem($post->ID),
            $query->posts
        );

        return new WP_REST_Response([
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
            'items'       => $items,
        ], 200);
    }

    // GET /coins/{id}
    public function getSingle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');

        $post = get_post($id);
        if (!$post || $post->post_type !== 'coins' || $post->post_status !== 'publish') {
            return new WP_Error('not_found', 'Coin not found.', ['status' => 404]);
        }

        return new WP_REST_Response($this->formatSingleItem($id), 200);
    }

    private function formatListItem(int $id): array
    {
        return [
            'id'         => $id,
            'title'      => get_the_title($id),
            'issue_date' => get_field('issue_date', $id),
            'thumbnail'  => get_the_post_thumbnail_url($id, 'medium') ?: null,
            'taxonomies' => $this->getTaxonomies($id),
        ];
    }

    private function formatSingleItem(int $id): array
    {
        $designer_roles = [
            'designers_artist'     => 'designers_artist',
            'designers_designer'   => 'designers_designer',
            'designers_adaptation' => 'designers_adaptation',
            'designers_sculptor'   => 'designers_sculptor',
        ];
        $designers = [];
        foreach ($designer_roles as $key => $meta) {
            $ids = get_field($meta, $id) ?: [];
            $designers[$key] = array_map(fn($did) => [
                'id'   => $did,
                'name' => get_the_title($did),
            ], (array) $ids);
        }

        $gallery_ids = get_field('images_gallery', $id) ?: [];
        $gallery = array_map(function ($attachment_id) {
            return [
                'id'     => $attachment_id,
                'url'    => wp_get_attachment_url($attachment_id),
                'medium' => wp_get_attachment_image_url($attachment_id, 'medium'),
            ];
        }, (array) $gallery_ids);

        return [
            'id'               => $id,
            'title'            => get_the_title($id),
            'issue_date'       => get_field('issue_date', $id),
            'diameter_mm'      => get_field('diameter_mm', $id) ? (float) get_field('diameter_mm', $id) : null,
            'mintage_declared' => get_field('mintage_declared', $id) ? (int) get_field('mintage_declared', $id) : null,
            'mintage_actual'   => get_field('mintage_actual', $id) ? (int) get_field('mintage_actual', $id) : null,
            'booklet_url'      => get_field('booklet_url', $id) ?: null,
            'description_html' => get_field('description_html', $id) ?: null,
            'designers'        => $designers,
            'gallery'          => $gallery,
            'taxonomies'       => $this->getTaxonomies($id),
        ];
    }

    private function getTaxonomies(int $id): array
    {
        $result = [];
        foreach (self::TAXONOMIES as $taxonomy) {
            $terms = get_the_terms($id, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                $result[$taxonomy] = [];
                continue;
            }
            $result[$taxonomy] = array_map(
                fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug],
                $terms
            );
        }
        return $result;
    }
}