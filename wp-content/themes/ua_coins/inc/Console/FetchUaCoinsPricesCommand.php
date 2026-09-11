<?php

namespace Coins\Console;

use Coins\Prices\PriceRepository;
use Coins\Prices\PriceSchema;
use WP_CLI;
use WP_Query;
use WP_Error;
use DOMDocument;
use DOMXPath;

class FetchUaCoinsPricesCommand
{
    public static function register(): void
    {
        WP_CLI::add_command(
            'uacoins import-prices',
            self::class,
            [
                'shortdesc' => 'Import coin price history from ua-coins.info',
                'synopsis'  => [
                    [
                        'type'        => 'assoc',
                        'name'        => 'post_id',
                        'optional'    => true,
                        'description' => 'Import only for a single coin post ID',
                    ],
                    [
                        'type'        => 'assoc',
                        'name'        => 'limit',
                        'optional'    => true,
                        'description' => 'Limit number of coins processed',
                    ],
                    [
                        'type'        => 'flag',
                        'name'        => 'rematch',
                        'optional'    => true,
                        'description' => 'Force re-search on ua-coins.info even if a cached match exists',
                    ],
                    [
                        'type'        => 'assoc',
                        'name'        => 'min-score',
                        'optional'    => true,
                        'description' => 'Minimum title-similarity score 0-100 to accept a match (default 55)',
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

    const BASE        = 'https://www.ua-coins.info';
    const SEARCH_PATH  = '/ua/search';
    const SOURCE       = 'ua-coins.info';

    // /coin/prices/{id} 403-ить без Referer + браузерного User-Agent (Cloudflare/WAF
    // блокує запити з нетиповим UA навіть із дійсним підписаним посиланням).
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected $post_type = 'coins';

    /**
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        $dry_run   = isset($assoc_args['dry-run']);
        $rematch   = isset($assoc_args['rematch']);
        $min_score = isset($assoc_args['min-score']) ? (float) $assoc_args['min-score'] : 55.0;
        $limit     = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : null;

        if (!$dry_run) {
            PriceSchema::install();
        }

        if (isset($assoc_args['post_id'])) {
            $post_ids = [(int) $assoc_args['post_id']];
        } else {
            $q = new WP_Query([
                'post_type'      => $this->post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            $post_ids = $q->posts;
        }

        if ($limit !== null) {
            $post_ids = array_slice($post_ids, 0, $limit);
        }

        WP_CLI::log(sprintf('Монет до обробки: %d%s', count($post_ids), $dry_run ? ' [DRY RUN]' : ''));

        $stats = [
            'matched'           => 0,
            'skipped_no_match'  => 0,
            'prices_table_rows' => 0,
            'errors'            => 0,
        ];

        foreach ($post_ids as $post_id) {
            // Троттлінг перед кожною монетою (пошук + сторінка + ціни = до 3 запитів) —
            // сайт рейт-лімітить (HTTP 429) при надто частих запитах.
            sleep(1);

            $title = get_the_title($post_id);
            if (!$title) {
                WP_CLI::warning("Пост #$post_id не знайдено — пропуск");
                continue;
            }
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            WP_CLI::log("Монета #$post_id: $title");

            $match = $this->resolve_uacoins_coin($post_id, $this->clean_search_query($title), $rematch, $min_score);
            if (!$match) {
                WP_CLI::warning('  Не знайдено відповідність на ua-coins.info — пропуск');
                $stats['skipped_no_match']++;
                continue;
            }

            WP_CLI::log(sprintf('  ua-coins.info ID=%d (score=%.1f)', $match['id'], $match['score']));

            $page_url   = self::BASE . "/ua/list/{$match['id']}-{$match['slug']}";
            $prices_url = $this->fetch_signed_prices_url($page_url, $match['id']);
            if (is_wp_error($prices_url)) {
                WP_CLI::warning('  Не вдалось отримати посилання на ціни: ' . $prices_url->get_error_message());
                $stats['errors']++;
                continue;
            }

            $prices = $this->fetch_prices($prices_url, $page_url);
            if (is_wp_error($prices)) {
                WP_CLI::warning('  Не вдалось отримати ціни: ' . $prices->get_error_message());
                $stats['errors']++;
                continue;
            }

            WP_CLI::log('  Знайдено записів цін: ' . count($prices));

            if (!$dry_run) {
                $this->store_prices($post_id, $prices, $stats);
            }

            $stats['matched']++;
        }

        WP_CLI::success(sprintf(
            'Готово. Монет зі співпадінням: %d, без співпадіння: %d, рядків у таблиці цін: %d, помилок: %d%s',
            $stats['matched'],
            $stats['skipped_no_match'],
            $stats['prices_table_rows'],
            $stats['errors'],
            $dry_run ? ' [DRY RUN]' : ''
        ));
    }

    /**
     * Writes a coin's price points to the custom {prefix}coin_prices table.
     * Mutates $stats by reference.
     *
     * @param array<int,array{date?:string,price?:mixed}> $prices
     * @param array<string,int>                           $stats
     */
    protected function store_prices(int $coin_post_id, array $prices, array &$stats): void
    {
        $rows = [];

        foreach ($prices as $entry) {
            $date  = $entry['date'] ?? null;
            $price = $entry['price'] ?? null;
            if (!$date || $price === null) {
                continue;
            }

            $rows[] = [
                'coin_id'    => $coin_post_id,
                'source'     => self::SOURCE,
                'price_date' => $date,
                'price'      => (float) $price,
            ];
        }

        if ($rows) {
            (new PriceRepository())->upsertBatch($rows);
            $stats['prices_table_rows'] += count($rows);
        }
    }

    /** ----------------------- MATCHING ----------------------- */

    protected function resolve_uacoins_coin(int $post_id, string $title, bool $rematch, float $min_score): ?array
    {
        if (!$rematch) {
            $cached_id = (int) get_post_meta($post_id, '_uacoins_id', true);
            if ($cached_id) {
                return [
                    'id'    => $cached_id,
                    'slug'  => (string) get_post_meta($post_id, '_uacoins_slug', true),
                    'score' => (float) get_post_meta($post_id, '_uacoins_match_score', true),
                ];
            }
        }

        $candidates = $this->search_uacoins($title);
        if (is_wp_error($candidates)) {
            WP_CLI::warning('  Пошук на ua-coins.info не вдався: ' . $candidates->get_error_message());
            return null;
        }
        if (empty($candidates)) {
            return null;
        }

        $norm_title = $this->normalize_title($title);

        $best       = null;
        $best_score = -1.0;
        foreach ($candidates as $candidate) {
            $percent = 0.0;
            $norm_candidate = $this->normalize_title($candidate['title']);
            similar_text($norm_title, $norm_candidate, $percent);
            if ($percent > $best_score) {
                $best_score = $percent;
                $best       = $candidate;
            }
        }

        if ($best === null || $best_score < $min_score) {
            return null;
        }

        update_post_meta($post_id, '_uacoins_id', $best['id']);
        update_post_meta($post_id, '_uacoins_slug', $best['slug']);
        update_post_meta($post_id, '_uacoins_match_score', $best_score);

        return [
            'id'    => $best['id'],
            'slug'  => $best['slug'],
            'score' => $best_score,
        ];
    }

    /**
     * GET з автоматичним повтором при HTTP 429 (сайт рейт-лімітить — nginx limit_req
     * повертає Retry-After, зазвичай ~5с при короткому сплеску запитів).
     */
    protected function http_get(string $url, array $headers, int $max_retries = 3)
    {
        $attempt = 0;
        while (true) {
            $resp = wp_remote_get($url, [
                'timeout' => 20,
                'headers' => $headers,
            ]);

            if (is_wp_error($resp)) {
                return $resp;
            }

            $code = wp_remote_retrieve_response_code($resp);
            if ($code === 429 && $attempt < $max_retries) {
                $retry_after = (int) wp_remote_retrieve_header($resp, 'retry-after');
                $wait        = $retry_after > 0 ? $retry_after : 5;
                WP_CLI::log("  HTTP 429 — чекаю {$wait}с і повторюю (спроба " . ($attempt + 1) . "/$max_retries)");
                sleep($wait);
                $attempt++;
                continue;
            }

            return $resp;
        }
    }

    protected function search_uacoins(string $title)
    {
        $resp = $this->http_get(self::BASE . self::SEARCH_PATH . '?search=' . rawurlencode($title), [
            'User-Agent' => self::USER_AGENT,
            'Accept'     => 'text/html',
        ]);

        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return new WP_Error('http', "HTTP $code при пошуку на ua-coins.info");
        }

        $body = wp_remote_retrieve_body($resp);
        if (!is_string($body) || $body === '') {
            return new WP_Error('empty', 'Порожня відповідь пошуку');
        }

        return $this->parse_search_results($body);
    }

    protected function parse_search_results(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xp    = new DOMXPath($dom);
        // Обмежуємось основним контентом — інакше в матчі потрапляють випадкові
        // посилання на монети з бічних віджетів (наприклад, "Останні коментарі").
        $nodes = $xp->query("//div[@id='main-content-block']//a[contains(@class,'nbu-store-title-text')]");

        $results = [];
        foreach ($nodes as $node) {
            $href = trim($node->attributes->getNamedItem('href')->nodeValue ?? '');
            if (!preg_match('~/ua/list/(\d+)-([^/?#]+)~', $href, $m)) {
                continue;
            }

            $title_attr = $node->attributes->getNamedItem('title');
            $title      = $title_attr ? trim($title_attr->nodeValue) : trim($node->textContent);

            $id = (int) $m[1];
            if (!isset($results[$id])) {
                $results[$id] = [
                    'id'    => $id,
                    'slug'  => $m[2],
                    'title' => $title,
                ];
            }
        }

        return array_values($results);
    }

    protected function clean_search_query(string $title): string
    {
        // Прибираємо бектики-обрамлення і кінцеву позначку металу в дужках —
        // такого немає в назвах на ua-coins.info і воно псує пошук/скоринг.
        $title = str_replace(['`', '‘', '’', '“', '”'], '', $title);
        $title = preg_replace('~\s*\([а-яіїєґa-z]{1,4}\)\s*$~iu', '', $title);
        return trim((string) $title);
    }

    protected function normalize_title(string $title): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('~[^\p{L}\p{N}\s]~u', ' ', $title);
        $title = preg_replace('~\s+~u', ' ', $title);
        return trim((string) $title);
    }

    /** ----------------------- PRICES ----------------------- */

    protected function fetch_signed_prices_url(string $page_url, int $id)
    {
        $resp = $this->http_get($page_url, [
            'User-Agent' => self::USER_AGENT,
            'Accept'     => 'text/html',
        ]);

        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return new WP_Error('http', "HTTP $code при завантаженні сторінки монети #$id");
        }

        $body = wp_remote_retrieve_body($resp);
        if (!preg_match('~data-prices-url="([^"]+)"~', $body, $m)) {
            return new WP_Error('no_prices_url', "Не знайдено data-prices-url на сторінці монети #$id");
        }

        $decoded = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === '' || $decoded[0] !== '/') {
            return new WP_Error('bad_prices_url', "Некоректний data-prices-url для монети #$id");
        }

        return self::BASE . $decoded;
    }

    protected function fetch_prices(string $url, string $referer)
    {
        // Ендпоінт цін віддає 403 без Referer на сторінку монети, навіть з дійсним
        // підписаним посиланням (_hash/expires) — це окрема перевірка на боці ua-coins.info.
        $resp = $this->http_get($url, [
            'User-Agent'       => self::USER_AGENT,
            'Accept'           => 'application/json',
            'Referer'          => $referer,
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return new WP_Error('http', "HTTP $code при завантаженні цін");
        }

        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new WP_Error('bad_json', 'Некоректний JSON у відповіді цін');
        }

        return $data;
    }
}
