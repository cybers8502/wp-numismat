<?php

namespace Coins\GraphQL;

use Coins\Prices\PriceRepository;

class CoinGraphQL
{
    /** Source tag written by the node-coins-price-parser `uacoins` importer (../node-coins-price-parser) */
    private const SOURCE_MARKET = 'ua-coins.info';
    /** Source tag written by the node-coins-price-parser `nbuarchive` importer (../node-coins-price-parser) */
    private const SOURCE_NBU = 'coins.bank.gov.ua';

    public function registerTypes(): void
    {
        $this->registerSharedTypes();
        $this->registerAcfFields();
        $this->registerGallery();
        $this->registerDesigners();
        $this->registerPriceHistory();
        $this->registerPriceStats();
    }

    private function registerSharedTypes(): void
    {
        register_graphql_object_type('CoinGalleryImage', [
            'description' => 'Gallery image of a coin',
            'fields'      => [
                'id'     => ['type' => 'Int',    'description' => 'Attachment ID'],
                'url'    => ['type' => 'String', 'description' => 'Full-size URL'],
                'medium' => ['type' => 'String', 'description' => 'Medium-size URL'],
            ],
        ]);

        register_graphql_object_type('CoinPriceEntry', [
            'description' => 'Historical price entry for a coin',
            'fields'      => [
                'id'     => ['type' => 'Int'],
                'date'   => ['type' => 'String'],
                'price'  => ['type' => 'Float'],
                'source' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('CoinPriceStats', [
            'description' => 'Aggregated price-dynamics summary for a coin, computed over its priceHistory',
            'fields'      => [
                'latestPrice'    => ['type' => 'Float',  'description' => 'Most recent market price (source: ua-coins.info)'],
                'latestDate'     => ['type' => 'String', 'description' => 'Date of latestPrice'],
                'trend'          => ['type' => 'String', 'description' => '"up" | "down" | "flat" — latestPrice vs. the market entry before it'],
                'nbuPrice'       => ['type' => 'Float',  'description' => 'Most recently known NBU shop price (source: coins.bank.gov.ua). Note: this is the last-scraped snapshot, not necessarily the price at issue date.'],
                'nbuDate'        => ['type' => 'String', 'description' => 'Date nbuPrice was recorded'],
                'vsNbuPct'       => ['type' => 'Float',  'description' => '(latestPrice - nbuPrice) / nbuPrice * 100, null if nbuPrice is unknown'],
                'periodStart'    => ['type' => 'String', 'description' => 'First date within the requested period that has a market price'],
                'periodEnd'      => ['type' => 'String', 'description' => 'Last date within the requested period that has a market price'],
                'periodDeltaPct' => ['type' => 'Float',  'description' => 'Change over the period: (last - first) / first * 100'],
                'periodMin'      => ['type' => 'Float',  'description' => 'Lowest market price within the period'],
                'periodMax'      => ['type' => 'Float',  'description' => 'Highest market price within the period'],
            ],
        ]);
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
            'resolve' => fn($source) => $this->fetchPriceEntries($source->databaseId),
        ]);
    }

    private function registerPriceStats(): void
    {
        register_graphql_field('Coin', 'priceStats', [
            'type'    => 'CoinPriceStats',
            'args'    => [
                'days' => [
                    'type'        => 'Int',
                    'description' => 'Limit the period-based fields (periodStart/End/DeltaPct/Min/Max) to the last N days. Omit for all-time.',
                ],
            ],
            'resolve' => function ($source, $args) {
                $entries = $this->fetchPriceEntries($source->databaseId);

                $market = array_values(array_filter($entries, fn($e) => $e['source'] === self::SOURCE_MARKET));
                $nbu    = array_values(array_filter($entries, fn($e) => $e['source'] === self::SOURCE_NBU));

                $latest       = $market ? end($market) : null;
                $previous     = $latest && count($market) > 1 ? $market[count($market) - 2] : null;
                $latestPrice  = $latest['price'] ?? null;
                $nbuLatest    = $nbu ? end($nbu) : null;
                $nbuPrice     = $nbuLatest['price'] ?? null;

                $trend = null;
                if ($latest && $previous) {
                    $trend = match (true) {
                        $latest['price'] > $previous['price'] => 'up',
                        $latest['price'] < $previous['price'] => 'down',
                        default                                => 'flat',
                    };
                }

                $period = $market;
                if (!empty($args['days'])) {
                    $since  = date('Y-m-d', strtotime(sprintf('-%d days', (int) $args['days'])));
                    $period = array_values(array_filter($market, fn($e) => $e['date'] >= $since));
                }

                $periodFirst = $period ? $period[0] : null;
                $periodLast  = $period ? end($period) : null;
                $prices      = array_column($period, 'price');

                return [
                    'latestPrice'    => $latestPrice,
                    'latestDate'     => $latest['date'] ?? null,
                    'trend'          => $trend,
                    'nbuPrice'       => $nbuPrice,
                    'nbuDate'        => $nbuLatest['date'] ?? null,
                    'vsNbuPct'       => ($latestPrice !== null && $nbuPrice) ? ($latestPrice - $nbuPrice) / $nbuPrice * 100 : null,
                    'periodStart'    => $periodFirst['date'] ?? null,
                    'periodEnd'      => $periodLast['date'] ?? null,
                    'periodDeltaPct' => ($periodFirst && $periodFirst['price']) ? ($periodLast['price'] - $periodFirst['price']) / $periodFirst['price'] * 100 : null,
                    'periodMin'      => $prices ? min($prices) : null,
                    'periodMax'      => $prices ? max($prices) : null,
                ];
            },
        ]);
    }

    /**
     * All price entries for a coin, across every source, ordered by date ASC.
     * Shared by priceHistory (raw) and priceStats (aggregated).
     *
     * Reads the custom {prefix}coin_prices table (indexed, one row per point)
     * — the coin_price CPT this used to query was retired in migration phase 6.
     */
    private function fetchPriceEntries(int $coin_id): array
    {
        return (new PriceRepository())->forCoin($coin_id);
    }
}
