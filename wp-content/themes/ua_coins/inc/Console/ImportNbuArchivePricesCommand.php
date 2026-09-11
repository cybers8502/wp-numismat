<?php

namespace Coins\Console;

use Coins\Prices\PriceRepository;
use Coins\Prices\PriceSchema;
use WP_CLI;
use WP_Query;

/**
 * Imports prices from coins.bank.gov.ua (the NBU's own shop archive) into the
 * custom coin_prices table. This does NOT fetch over HTTP — the archive is
 * behind Bunny Shield (a JS proof-of-work anti-bot challenge), so the data has
 * to be collected via a real browser and handed to this command as a JSON
 * dump. See inc/Console/README.md.
 */
class ImportNbuArchivePricesCommand
{
    public static function register(): void
    {
        WP_CLI::add_command(
            'nbuarchive import-prices',
            self::class,
            [
                'shortdesc' => 'Import a coins.bank.gov.ua archive JSON dump into the coin_prices table',
                'synopsis'  => [
                    [
                        'type'        => 'assoc',
                        'name'        => 'file',
                        'optional'    => false,
                        'description' => 'Path to a JSON file: [{"title": "...", "price": 458, "sku": "8GT"}, ...]',
                    ],
                    [
                        'type'        => 'assoc',
                        'name'        => 'price-date',
                        'optional'    => true,
                        'description' => 'Date (Y-m-d) to record for every price in this dump. Defaults to today — the archive only shows "price as of last sale date", not a per-item date.',
                    ],
                    [
                        'type'        => 'assoc',
                        'name'        => 'min-score',
                        'optional'    => true,
                        'description' => 'Minimum title-similarity score 0-100 to accept a match (default 85 — high, because templated titles like "Ролик ... (у ролику 25 монет)" share so much boilerplate that a lower threshold produces false matches between different coins)',
                    ],
                    [
                        'type'        => 'flag',
                        'name'        => 'dry-run',
                        'optional'    => true,
                        'description' => 'Do not write to DB, just output what would be processed',
                    ],
                ],
            ]
        );
    }

    const SOURCE = 'coins.bank.gov.ua';

    protected $post_type = 'coins';

    /**
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        $file = $assoc_args['file'] ?? null;
        if (!$file || !is_readable($file)) {
            WP_CLI::error("Файл не знайдено або недоступний для читання: $file");
        }

        $items = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
            WP_CLI::error('Некоректний JSON у файлі: ' . json_last_error_msg());
        }

        $dry_run    = isset($assoc_args['dry-run']);
        $min_score  = isset($assoc_args['min-score']) ? (float) $assoc_args['min-score'] : 85.0;
        $price_date = $assoc_args['price-date'] ?? current_time('Y-m-d');

        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $price_date)) {
            WP_CLI::error('--price-date має бути у форматі Y-m-d');
        }

        if (!$dry_run) {
            PriceSchema::install();
        }

        WP_CLI::log(sprintf(
            'Позицій у дампі: %d, дата ціни: %s%s',
            count($items),
            $price_date,
            $dry_run ? ' [DRY RUN]' : ''
        ));

        // Матчимо в пам'яті проти всіх опублікованих монет одразу — без запиту на кожен айтем.
        $coins = $this->load_coins();

        $stats = [
            'matched'           => 0,
            'skipped_no_match'  => 0,
            'skipped_bad_item'  => 0,
            'prices_table_rows' => 0,
        ];

        $table_rows = [];

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $price = $item['price'] ?? null;

            if ($title === '' || $price === null) {
                WP_CLI::warning('Пропускаю запис без title/price: ' . wp_json_encode($item, JSON_UNESCAPED_UNICODE));
                $stats['skipped_bad_item']++;
                continue;
            }

            $match = $this->match_coin($title, $coins, $min_score);
            if (!$match) {
                WP_CLI::warning("Не знайдено відповідність для «{$title}» — пропуск");
                $stats['skipped_no_match']++;
                continue;
            }

            WP_CLI::log(sprintf(
                '«%s» → #%d %s (score=%.1f)',
                $title,
                $match['id'],
                $match['title'],
                $match['score']
            ));

            if (!$dry_run) {
                $table_rows[] = [
                    'coin_id'    => $match['id'],
                    'source'     => self::SOURCE,
                    'price_date' => $price_date,
                    'price'      => (float) $price,
                    'sku'        => $item['sku'] ?? null,
                ];
            }

            $stats['matched']++;
        }

        if (!$dry_run && $table_rows) {
            (new PriceRepository())->upsertBatch($table_rows);
            $stats['prices_table_rows'] = count($table_rows);
        }

        WP_CLI::success(sprintf(
            'Готово. Заматчено: %d, без відповідності: %d, некоректних записів: %d, рядків у таблиці цін: %d%s',
            $stats['matched'],
            $stats['skipped_no_match'],
            $stats['skipped_bad_item'],
            $stats['prices_table_rows'],
            $dry_run ? ' [DRY RUN]' : ''
        ));
    }

    /** ----------------------- MATCHING ----------------------- */

    protected function load_coins(): array
    {
        $q = new WP_Query([
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $coins = [];
        foreach ($q->posts as $post_id) {
            $title   = get_the_title($post_id);
            $coins[] = [
                'id'    => (int) $post_id,
                'title' => $title,
                'norm'  => $this->normalize_title($this->clean_title($title)),
            ];
        }

        return $coins;
    }

    protected function match_coin(string $title, array $coins, float $min_score): ?array
    {
        $decoded    = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $norm_input = $this->normalize_title($this->clean_title($decoded));

        $best       = null;
        $best_score = -1.0;
        foreach ($coins as $coin) {
            $percent = 0.0;
            similar_text($norm_input, $coin['norm'], $percent);
            if ($percent > $best_score) {
                $best_score = $percent;
                $best       = $coin;
            }
        }

        if ($best === null || $best_score < $min_score) {
            return null;
        }

        return [
            'id'    => $best['id'],
            'title' => $best['title'],
            'score' => $best_score,
        ];
    }

    // Той самий підхід, що й у FetchUaCoinsPricesCommand::clean_search_query() /
    // normalize_title() — обидва джерела віддають назви з тими самими "шумовими"
    // елементами (бектики, позначки металу в дужках).
    protected function clean_title(string $title): string
    {
        $title = str_replace(['`', '‘', '’', '“', '”'], '', $title);
        $title = preg_replace('~\s*\([а-яіїєґa-z]{1,4}\)\s*$~iu', '', $title);
        return trim((string) $title);
    }

    protected function normalize_title(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('~[^\p{L}\p{N}\s]~u', ' ', $title);
        $title = preg_replace('~\s+~u', ' ', $title);
        return trim((string) $title);
    }
}
