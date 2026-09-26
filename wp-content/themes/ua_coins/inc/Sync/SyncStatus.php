<?php

namespace Coins\Sync;

/**
 * Whether the nightly NBU import should (re)write a coin — the "Оновлювати з НБУ" checkbox.
 *
 * NBU rarely changes a coin once it's published, so an imported coin is treated as finished: the
 * importer leaves it alone, and hand edits in wp-admin stay. It keeps a coin *pending* (re-imported
 * every night, all fields overwritten) only while NBU may still be filling it in:
 *
 * - fewer than 2 images — NBU often publishes a coin before its obverse/reverse photos;
 * - no description, or no declared/actual mintage;
 * - issued less than GRACE_DAYS ago — extra photos (packaging, sets) tend to arrive in the first
 *   weeks even when the obverse and reverse are already there.
 *
 * Deliberately *not* required: diameter, series, denomination, quality — sets and rolls legitimately
 * have none, and would otherwise stay pending forever.
 *
 * After each import the importer re-evaluates, so the flag clears itself once everything is in. An
 * admin can tick it to force one more re-import (e.g. after fixing something on NBU's side), or
 * untick it to stop the importer touching a coin that will never be complete. A coin with no flag
 * stored yet counts as pending — the safe default before `wp coins backfill-sync-status` has run.
 */
final class SyncStatus
{
    public const META_PENDING   = '_nbu_sync_pending';
    public const META_MISSING   = '_nbu_sync_missing';
    public const META_SYNCED_AT = '_nbu_synced_at';

    public const GRACE_DAYS = 60;
    public const MIN_IMAGES = 2;

    /** @return array<string,string> reason key => label */
    public static function reasonLabels(): array
    {
        return [
            'images'      => 'Фото (аверс і реверс)',
            'description' => 'Опис',
            'mintage'     => 'Тираж',
            'fresh'       => 'Свіжий випуск (до ' . self::GRACE_DAYS . ' днів)',
        ];
    }

    /**
     * Pure rule, unit-tested: which reasons keep a coin pending.
     *
     * @return array<int,string> reason keys, empty when the coin is complete
     */
    public static function reasons(int $imageCount, bool $hasDescription, bool $hasMintage, ?string $issueDate, int $now): array
    {
        $reasons = [];
        if ($imageCount < self::MIN_IMAGES) {
            $reasons[] = 'images';
        }
        if (!$hasDescription) {
            $reasons[] = 'description';
        }
        if (!$hasMintage) {
            $reasons[] = 'mintage';
        }
        $issued = $issueDate ? strtotime(substr(preg_replace('~\D~', '', $issueDate), 0, 8)) : false;
        if ($issued !== false && $issued > $now - self::GRACE_DAYS * 86400) {
            $reasons[] = 'fresh';
        }

        return $reasons;
    }

    /**
     * Recomputes and stores the flag from the coin's current data. Returns whether it's pending.
     */
    public static function evaluate(int $postId): bool
    {
        $gallery = get_post_meta($postId, 'images_gallery', true);
        $reasons = self::reasons(
            is_array($gallery) ? count($gallery) : 0,
            trim((string) get_post_meta($postId, 'description_html', true)) !== '',
            (int) get_post_meta($postId, 'mintage_declared', true) > 0 && (int) get_post_meta($postId, 'mintage_actual', true) > 0,
            (string) get_post_meta($postId, 'issue_date', true),
            time()
        );

        update_post_meta($postId, self::META_MISSING, $reasons);
        self::setPending($postId, $reasons !== []);

        return $reasons !== [];
    }

    public static function isPending(int $postId): bool
    {
        return get_post_meta($postId, self::META_PENDING, true) !== '0';
    }

    public static function setPending(int $postId, bool $pending): void
    {
        update_post_meta($postId, self::META_PENDING, $pending ? '1' : '0');
    }

    /** @return array<int,string> */
    public static function missing(int $postId): array
    {
        $value = get_post_meta($postId, self::META_MISSING, true);

        return is_array($value) ? $value : [];
    }

    /**
     * Catalog-wide numbers for the dashboard.
     *
     * @return array{total:int, pending:int, reasons:array<string,int>}
     */
    public static function counts(): array
    {
        global $wpdb;

        $total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'coins' AND post_status NOT IN ('trash','auto-draft')");
        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
              WHERE p.post_type = 'coins' AND p.post_status NOT IN ('trash','auto-draft')
                AND (m.meta_value IS NULL OR m.meta_value <> '0')",
            self::META_PENDING
        ));

        $reasons = array_fill_keys(array_keys(self::reasonLabels()), 0);
        $rows    = $wpdb->get_col($wpdb->prepare(
            "SELECT m.meta_value FROM {$wpdb->postmeta} m
               JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'coins' AND p.post_status NOT IN ('trash','auto-draft')
              WHERE m.meta_key = %s",
            self::META_MISSING
        ));
        foreach ($rows as $row) {
            foreach ((array) maybe_unserialize($row) as $reason) {
                if (isset($reasons[$reason])) {
                    $reasons[$reason]++;
                }
            }
        }

        return ['total' => $total, 'pending' => $pending, 'reasons' => $reasons];
    }
}
