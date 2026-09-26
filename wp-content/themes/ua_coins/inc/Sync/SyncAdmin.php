<?php

namespace Coins\Sync;

/**
 * wp-admin side of the NBU sync: the "Оновлювати з НБУ" checkbox on the coin edit screen, the НБУ
 * column + "Очікують оновлення" view on the coin list, a one-line summary above that list, and the
 * Coins → Синхронізація НБУ page (current state, per-month totals, recent runs).
 *
 * No Dashboard widget: AdminMenuManager removes the Dashboard page on this site.
 */
final class SyncAdmin
{
    private const NONCE     = 'coins_nbu_sync';
    private const QUERY_VAR = 'nbu_pending';
    private const PAGE      = 'coins-nbu-sync';

    public function boot(): void
    {
        add_action('add_meta_boxes_coins', [$this, 'addMetaBox']);
        add_action('save_post_coins', [$this, 'saveMetaBox'], 10, 2);

        add_filter('manage_coins_posts_columns', [$this, 'addColumn']);
        add_action('manage_coins_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('views_edit-coins', [$this, 'addView']);
        add_action('pre_get_posts', [$this, 'filterPending']);
        add_action('admin_notices', [$this, 'listSummary']);

        add_action('admin_menu', [$this, 'addPage']);
    }

    public function addMetaBox(): void
    {
        add_meta_box('coins-nbu-sync', 'Синхронізація НБУ', [$this, 'renderMetaBox'], 'coins', 'side', 'high');
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        $pending  = SyncStatus::isPending($post->ID);
        $missing  = SyncStatus::missing($post->ID);
        $syncedAt = (string) get_post_meta($post->ID, SyncStatus::META_SYNCED_AT, true);
        $labels   = SyncStatus::reasonLabels();

        wp_nonce_field(self::NONCE, self::NONCE . '_nonce');
        printf(
            '<p><label><input type="checkbox" name="nbu_sync_pending" value="1" %s> <strong>Оновлювати з НБУ</strong></label></p>',
            checked($pending, true, false)
        );
        echo '<p class="description">Поки галочка стоїть, нічний імпорт перезаписує монету даними НБУ (ручні правки теж). Знімається сама, коли все стягнуто.</p>';

        if ($missing) {
            echo '<p>Чого бракує:</p><ul style="list-style:disc;margin-left:18px">';
            foreach ($missing as $reason) {
                echo '<li>' . esc_html($labels[$reason] ?? $reason) . '</li>';
            }
            echo '</ul>';
        } elseif ($syncedAt) {
            echo '<p>✓ Усі дані стягнуто.</p>';
        }

        echo '<p class="description">Остання синхронізація: ' . esc_html($syncedAt ? mysql2date('d.m.Y H:i', $syncedAt) : '—') . '</p>';
    }

    public function saveMetaBox(int $postId, \WP_Post $post): void
    {
        if (
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || !isset($_POST[self::NONCE . '_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE . '_nonce'])), self::NONCE)
            || !current_user_can('edit_post', $postId)
        ) {
            return;
        }
        SyncStatus::setPending($postId, !empty($_POST['nbu_sync_pending']));
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function addColumn(array $columns): array
    {
        $columns['nbu_sync'] = 'НБУ';

        return $columns;
    }

    public function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'nbu_sync') {
            return;
        }
        if (!SyncStatus::isPending($postId)) {
            echo '<span title="Усі дані стягнуто">✓</span>';

            return;
        }
        $labels  = SyncStatus::reasonLabels();
        $missing = array_map(fn ($r) => $labels[$r] ?? $r, SyncStatus::missing($postId));
        printf('<span title="%s">⟳ оновлюється</span>', esc_attr($missing ? 'Бракує: ' . implode(', ', $missing) : 'Галочку поставлено вручну'));
    }

    /**
     * @param array<string,string> $views
     * @return array<string,string>
     */
    public function addView(array $views): array
    {
        $counts  = SyncStatus::counts();
        $current = isset($_GET[self::QUERY_VAR]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        $views[self::QUERY_VAR] = sprintf(
            '<a href="%s"%s>Оновлюються з НБУ <span class="count">(%d)</span></a>',
            esc_url(admin_url('edit.php?post_type=coins&' . self::QUERY_VAR . '=1')),
            $current ? ' class="current" aria-current="page"' : '',
            $counts['pending']
        );

        return $views;
    }

    public function filterPending(\WP_Query $query): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'coins' || !isset($_GET[self::QUERY_VAR])) {
            return;
        }
        $query->set('meta_query', [
            'relation' => 'OR',
            ['key' => SyncStatus::META_PENDING, 'value' => '0', 'compare' => '!='],
            ['key' => SyncStatus::META_PENDING, 'compare' => 'NOT EXISTS'],
        ]);
    }

    public function listSummary(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-coins') {
            return;
        }
        $counts = SyncStatus::counts();
        $last   = SyncRunRepository::recent(1)[0] ?? null;

        printf(
            '<div class="notice notice-info"><p>НБУ: %s · очікують оновлення <strong>%d</strong> з %d · <a href="%s">Статистика синхронізації →</a></p></div>',
            $last ? esc_html(sprintf(
                'остання синхронізація %s (%s): нових %d, оновлено %d, фото %d',
                mysql2date('d.m.Y H:i', $last['started_at']),
                self::statusLabel($last['status']),
                $last['created'],
                $last['updated'],
                $last['images_downloaded']
            )) : 'синхронізацій ще не записано',
            (int) $counts['pending'],
            (int) $counts['total'],
            esc_url(admin_url('edit.php?post_type=coins&page=' . self::PAGE))
        );
    }

    public function addPage(): void
    {
        add_submenu_page('edit.php?post_type=coins', 'Синхронізація НБУ', 'Синхронізація НБУ', 'edit_posts', self::PAGE, [$this, 'renderPage']);
    }

    public function renderPage(): void
    {
        $counts  = SyncStatus::counts();
        $labels  = SyncStatus::reasonLabels();
        $recent  = SyncRunRepository::recent(30);
        $monthly = SyncRunRepository::monthly(12);
        $last    = $recent[0] ?? null;
        $listUrl = admin_url('edit.php?post_type=coins&' . self::QUERY_VAR . '=1');

        echo '<div class="wrap"><h1>Синхронізація НБУ</h1>';

        echo '<h2>Зараз</h2><table class="widefat striped" style="max-width:640px"><tbody>';
        self::row('Монет у каталозі', number_format_i18n($counts['total']));
        self::row('Очікують оновлення', sprintf('<a href="%s">%s</a>', esc_url($listUrl), number_format_i18n($counts['pending'])), false);
        self::row('Повністю стягнуто', number_format_i18n($counts['total'] - $counts['pending']));
        foreach ($labels as $key => $label) {
            self::row('— бракує: ' . $label, number_format_i18n($counts['reasons'][$key]));
        }
        if ($last) {
            self::row('Остання синхронізація', sprintf(
                '%s — %s',
                mysql2date('d.m.Y H:i', $last['started_at']),
                self::statusLabel($last['status'])
            ));
        }
        echo '</tbody></table>';
        echo '<p class="description">Монета «повністю стягнута», коли є аверс і реверс, опис і тираж, і з випуску минуло понад '
            . (int) SyncStatus::GRACE_DAYS . ' днів. Повні монети нічний імпорт не чіпає; галочка «Оновлювати з НБУ» на екрані монети повертає її в оновлення.</p>';

        echo '<h2>По місяцях</h2>';
        self::table(
            ['Місяць', 'Запусків', 'Успішних', 'Нових', 'Оновлено', 'Фото завантажено', 'Фото з помилкою', 'Помилок'],
            array_map(fn ($m) => [
                esc_html($m['month']),
                (int) $m['runs'],
                (int) $m['ok_runs'],
                (int) $m['created'],
                (int) $m['updated'],
                (int) $m['images_downloaded'],
                (int) $m['images_failed'],
                (int) $m['errors'],
            ], $monthly)
        );

        echo '<h2>Останні запуски</h2>';
        self::table(
            ['Початок', 'Тривалість', 'Статус', 'Сторінок', 'Переглянуто', 'Нових', 'Оновлено', 'Пропущено повних', 'Фото', 'Фото з помилкою', 'Помилок', 'Очікують після'],
            array_map(fn ($r) => [
                esc_html(mysql2date('d.m.Y H:i', $r['started_at'])) . ((int) $r['forced'] ? ' <em>(--force)</em>' : ''),
                $r['finished_at'] ? esc_html(human_time_diff(strtotime($r['started_at']), strtotime($r['finished_at']))) : '—',
                esc_html(self::statusLabel($r['status'])) . ($r['message'] ? '<br><small>' . esc_html($r['message']) . '</small>' : ''),
                (int) $r['pages'],
                (int) $r['seen'],
                (int) $r['created'],
                (int) $r['updated'],
                (int) $r['skipped'],
                (int) $r['images_downloaded'],
                (int) $r['images_failed'],
                (int) $r['errors'],
                (int) $r['pending_after'],
            ], $recent)
        );

        echo '</div>';
    }

    private static function statusLabel(string $status): string
    {
        return ['ok' => 'успішно', 'failed' => 'помилка', 'running' => 'не завершено'][$status] ?? $status;
    }

    private static function row(string $label, string $value, bool $escape = true): void
    {
        printf('<tr><th style="width:60%%">%s</th><td>%s</td></tr>', esc_html($label), $escape ? esc_html($value) : wp_kses_post($value));
    }

    /**
     * @param array<int,string>              $head
     * @param array<int,array<int,int|string>> $rows cells already escaped
     */
    private static function table(array $head, array $rows): void
    {
        if (!$rows) {
            echo '<p>Ще немає записаних синхронізацій.</p>';

            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($head as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $cells) {
            echo '<tr>';
            foreach ($cells as $cell) {
                echo '<td>' . wp_kses_post((string) $cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
