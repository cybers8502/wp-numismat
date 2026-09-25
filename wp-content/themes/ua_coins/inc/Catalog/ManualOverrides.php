<?php

namespace Coins\Catalog;

/**
 * Remembers which importer-managed fields of a coin were changed by hand in wp-admin, so
 * `wp nbu parse-souvenir` (nightly) and `wp coins backfill-types` stop overwriting them.
 *
 * The set is an ordinary ACF checkbox field (`import_locked_fields`, in the edit screen's sidebar):
 * on every admin save the fields whose submitted value differs from the stored one are ticked
 * automatically, and unticking a box hands that field back to the importer. Keys are the ACF field
 * names, plus `post_title`. Some keys guard more than their own field, because the importer derives
 * several values from one NBU source value:
 *
 * - `issue_date` also guards `post_date` (the catalog's Рік sort) and the `coin_year` term;
 * - `description_html` also guards `post_content`;
 * - `coin_diameter` / `coin_mintage_*` / `coin_quality` / `coin_edge` also guard the plain meta
 *   copies of the same value (`diameter_mm`, `mintage_*`, `quality`, `edge`).
 *
 * Posts are matched to NBU entries by `_nbu_key`, set once at creation, so a hand-edited title
 * doesn't make the importer create a duplicate.
 */
final class ManualOverrides
{
    public const FIELD_NAME = 'import_locked_fields';
    public const FIELD_KEY  = 'field_coin_import_locked_fields';

    /** @return array<string,string> key => label, in edit-screen order */
    public static function choices(): array
    {
        return [
            'post_title'            => 'Назва',
            'short_title'           => 'Short title',
            'issue_date'            => 'Issue date (+ рік, дата поста)',
            'coin_denomination'     => 'Denomination',
            'coin_quality'          => 'Quality',
            'coin_material'         => 'Material',
            'coin_series'           => 'Series',
            'coin_edge'             => 'Edge',
            'coin_diameter'         => 'Diameter',
            'coin_mintage_declared' => 'Mintage (declared)',
            'coin_mintage_actual'   => 'Mintage (actual)',
            'coin_type'             => 'Type',
            'coin_color'            => 'Color',
            'coin_packaging'        => 'Packaging',
            'designers_artist'      => 'Художник',
            'designers_designer'    => 'Дизайнер',
            'designers_adaptation'  => 'Адаптація дизайну',
            'designers_sculptor'    => 'Скульптор',
            'booklet_url'           => 'Booklet URL',
            'description_html'      => 'Description (+ контент поста)',
            'images_gallery'        => 'Images gallery',
        ];
    }

    /** @return array<int,string> */
    public static function locked(int $postId): array
    {
        $value = get_post_meta($postId, self::FIELD_NAME, true);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    public static function isLocked(int $postId, string $key): bool
    {
        return in_array($key, self::locked($postId), true);
    }

    public function boot(): void
    {
        add_action('acf/init', [$this, 'registerField']);
        // Before ACF's own save (priority 10) — the stored values are still the old ones here.
        add_action('acf/save_post', [$this, 'lockChangedFields'], 5);
        add_action('post_updated', [$this, 'lockChangedTitle'], 10, 3);
    }

    public function registerField(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'      => 'group_coin_import_locks',
            'title'    => 'Захищено від імпорту НБУ',
            'fields'   => [[
                'key'          => self::FIELD_KEY,
                'label'        => '',
                'name'         => self::FIELD_NAME,
                'type'         => 'checkbox',
                'instructions' => 'Поля, змінені вручну, позначаються автоматично — нічний імпорт їх не чіпає. Зніміть позначку, щоб імпорт знову оновлював поле.',
                'choices'      => self::choices(),
                'layout'       => 'vertical',
                'return_format' => 'value',
            ]],
            'location' => [[[
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'coins',
            ]]],
            'position' => 'side',
            'style'    => 'default',
        ]);
    }

    /**
     * @param int|string $postId
     */
    public function lockChangedFields($postId): void
    {
        if (!$this->isManualEdit() || !is_numeric($postId) || get_post_type((int) $postId) !== 'coins') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ACF verifies its nonce before acf/save_post and sanitises on save; values here are only compared, never stored.
        $posted = isset($_POST['acf']) && is_array($_POST['acf']) ? wp_unslash($_POST['acf']) : null;
        if ($posted === null) {
            return;
        }

        $postId = (int) $postId;
        $locked = isset($posted[self::FIELD_KEY]) ? array_map('strval', (array) $posted[self::FIELD_KEY]) : [];

        foreach ($posted as $fieldKey => $newValue) {
            $field = acf_get_field($fieldKey);
            if (!$field || !isset(self::choices()[$field['name']])) {
                continue;
            }
            $oldValue = get_field($field['name'], $postId, false);
            if (self::normalize($field['type'], $oldValue) !== self::normalize($field['type'], $newValue)) {
                $locked[] = $field['name'];
            }
        }

        $locked = array_values(array_unique($locked));
        // Written back into the submission so ACF saves the merged set as the checkbox's value.
        $_POST['acf'][self::FIELD_KEY] = $locked;
    }

    public function lockChangedTitle(int $postId, \WP_Post $after, \WP_Post $before): void
    {
        if (!$this->isManualEdit() || $after->post_type !== 'coins' || $after->post_title === $before->post_title) {
            return;
        }
        $locked = self::locked($postId);
        if (!in_array('post_title', $locked, true)) {
            $locked[] = 'post_title';
            update_post_meta($postId, self::FIELD_NAME, $locked);
        }
    }

    /**
     * Comparable form of an ACF value, so an untouched field never reads as changed: the date
     * picker submits `Ymd` while the importer stores `Y-m-d`, TinyMCE re-serialises plain text into
     * markup, and single-select taxonomy fields submit a string where the stored value is an int.
     *
     * @param mixed $value
     */
    public static function normalize(string $type, $value): string
    {
        switch ($type) {
            case 'date_picker':
                return (string) preg_replace('~\D~', '', (string) $value);
            case 'wysiwyg':
            case 'textarea':
                $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');

                return trim((string) preg_replace('~\s+~u', ' ', $text));
            case 'taxonomy':
            case 'relationship':
            case 'gallery':
                $ids = array_filter(array_map('intval', is_array($value) ? $value : [$value]));

                return implode(',', $ids);
            default:
                return trim((string) (is_array($value) ? implode(',', $value) : $value));
        }
    }

    private function isManualEdit(): bool
    {
        return !(defined('WP_CLI') && WP_CLI) && is_user_logged_in();
    }
}
