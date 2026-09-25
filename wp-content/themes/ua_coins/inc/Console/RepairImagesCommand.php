<?php

namespace Coins\Console;

use Coins\Media\NbuImageSource;
use WP_CLI;

/**
 * `wp coins repair-images` — undoes the damage of two bugs in the NBU image pipeline (both fixed in
 * the same change that added this command):
 *
 * 1. **Wrong images.** `convert-to-webp.php` treated an existing `avers.webp` as "this attachment,
 *    already converted", so every NBU `/media/coins/{id}/avers.jpg` / `revers.jpg` (all coins share
 *    those basenames) that landed in a month folder after the first one was repointed at *that* coin's
 *    file, and its own original deleted. Symptom: a coin's gallery shows another coin's obverse and
 *    reverse. Detected as one `_wp_attached_file` shared by attachments with different source URLs;
 *    every such attachment that a gallery (or thumbnail) uses is re-downloaded from its source URL
 *    into a file of its own — in place, so the attachment ID and every gallery pointing at it stay.
 * 2. **Duplicate attachments.** The importer deduplicated on the raw URL, and NBU bumps a `?v=N`
 *    cache-buster site-wide, so each bump re-downloaded every image as a new attachment. Source URLs
 *    are normalised (query string dropped); an attachment no gallery/thumbnail uses is deleted when
 *    another attachment of the same image survives, or when its file is one of the wrong ones above.
 *
 * Files are only unlinked once no remaining attachment (main file or any generated size) references
 * them. Run with `--dry-run` first; `--coin=<id>` limits deletions and re-downloads to one coin.
 */
class RepairImagesCommand
{
    public static function register(): void
    {
        WP_CLI::add_command('coins repair-images', self::class, [
            'shortdesc' => 'Re-download coin images that point at another coin\'s file, delete duplicate attachments',
            'synopsis'  => [
                [
                    'type'        => 'flag',
                    'name'        => 'dry-run',
                    'optional'    => true,
                    'description' => 'Report what would change, change nothing',
                ],
                [
                    'type'        => 'assoc',
                    'name'        => 'coin',
                    'optional'    => true,
                    'description' => 'Only delete / re-download attachments of this coin post ID',
                ],
            ],
        ]);
    }

    /**
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $dry  = isset($assoc_args['dry-run']);
        $coin = isset($assoc_args['coin']) ? (int) $assoc_args['coin'] : null;

        // 1) Normalise source URLs — global and idempotent, independent of --coin.
        $toNormalise = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            NbuImageSource::META_KEY,
            '%?%'
        ));
        if (!$dry && $toNormalise > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = SUBSTRING_INDEX(meta_value, '?', 1)
                  WHERE meta_key = %s AND meta_value LIKE %s",
                NbuImageSource::META_KEY,
                '%?%'
            ));
            wp_cache_flush();
        }
        WP_CLI::log(sprintf('URL без ?v=: %d нормалізовано', $toNormalise));

        $atts = $this->loadAttachments();
        $used = $this->usedAttachmentIds();

        // 2) Files shared by attachments of different images.
        $urlsByFile = [];
        foreach ($atts as $a) {
            if ($a['url'] !== '') {
                $urlsByFile[$a['file']][$a['url']] = true;
            }
        }
        $badFiles = [];
        foreach ($urlsByFile as $file => $urls) {
            if (count($urls) > 1) {
                $badFiles[$file] = true;
            }
        }

        // 3) Pick deletions: unused, and either wrong or a duplicate of a kept attachment.
        $byUrl = [];
        foreach ($atts as $a) {
            if ($a['url'] !== '') {
                $byUrl[$a['url']][] = $a;
            }
        }
        $delete = [];
        foreach ($byUrl as $group) {
            $keep = array_filter($group, fn ($a) => isset($used[$a['id']]));
            if (!$keep) {
                // Nobody displays this image: keep one copy, preferring one with a correct file.
                $good = array_values(array_filter($group, fn ($a) => !isset($badFiles[$a['file']])));
                $keep = $good ? [end($good)] : [];
            }
            $keepIds = array_column($keep, 'id');
            foreach ($group as $a) {
                if (!in_array($a['id'], $keepIds, true) && !isset($used[$a['id']])) {
                    $delete[$a['id']] = $a;
                }
            }
        }

        // 4) Pick re-downloads: every used attachment sitting on a shared, wrong file.
        $redownload = [];
        foreach ($atts as $a) {
            if (isset($badFiles[$a['file']]) && isset($used[$a['id']]) && $a['url'] !== '') {
                $redownload[$a['id']] = $a;
            }
        }

        if ($coin) {
            $delete     = array_filter($delete, fn ($a) => $a['parent'] === $coin);
            $redownload = array_filter($redownload, fn ($a) => $a['parent'] === $coin);
        }

        WP_CLI::log(sprintf(
            'Вкладень: %d, використовуються: %d, спільних файлів з різними картинками: %d',
            count($atts),
            count($used),
            count($badFiles)
        ));
        WP_CLI::log(sprintf('Перезавантажити: %d, видалити дублів: %d', count($redownload), count($delete)));

        if ($dry) {
            foreach (array_slice($redownload, 0, 10, true) as $a) {
                WP_CLI::log(sprintf('  [DRY] re-download #%d (coin #%d) %s  ← зараз %s', $a['id'], $a['parent'], $a['url'], $a['file']));
            }
            WP_CLI::success('Dry run — нічого не змінено.');

            return;
        }

        // Files that may become orphaned; unlinked at the end only if nothing references them.
        $candidates = [];

        // wp_delete_attachment() would unlink files other attachments still use; block every
        // unlink it attempts and leave file removal to step 5.
        add_filter('wp_delete_file', '__return_empty_string');
        foreach ($delete as $a) {
            $candidates += array_flip($a['paths']);
            wp_delete_attachment($a['id'], true);
        }
        remove_filter('wp_delete_file', '__return_empty_string');
        WP_CLI::log(sprintf('Видалено дублів: %d', count($delete)));

        $fixed = 0;
        foreach ($redownload as $a) {
            $candidates += array_flip($a['paths']);
            $result = $this->redownload($a);
            if (is_wp_error($result)) {
                WP_CLI::warning(sprintf('  #%d %s: %s', $a['id'], $a['url'], $result->get_error_message()));
                continue;
            }
            $fixed++;
            WP_CLI::log(sprintf('  #%d (coin #%d) → %s', $a['id'], $a['parent'], $result));
            usleep(200000); // same pacing as the importer
        }
        WP_CLI::log(sprintf('Перезавантажено: %d/%d', $fixed, count($redownload)));

        // 5) Unlink candidate files no remaining attachment references.
        wp_cache_flush();
        $referenced = [];
        foreach ($this->loadAttachments() as $a) {
            $referenced += array_flip($a['paths']);
        }
        $basedir  = wp_upload_dir()['basedir'];
        $unlinked = 0;
        foreach (array_keys($candidates) as $rel) {
            if (!isset($referenced[$rel]) && is_file($basedir . '/' . $rel)) {
                unlink($basedir . '/' . $rel);
                $unlinked++;
            }
        }
        WP_CLI::success(sprintf('Готово. Видалено осиротілих файлів: %d', $unlinked));
    }

    /**
     * @return array<int, array{id:int, parent:int, file:string, url:string, paths:string[]}>
     */
    private function loadAttachments(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_parent, f.meta_value AS file, s.meta_value AS url, m.meta_value AS meta
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
          LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
          LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
              WHERE p.post_type = 'attachment'",
            NbuImageSource::META_KEY
        ));

        $out = [];
        foreach ($rows as $r) {
            $paths = [$r->file];
            $meta  = maybe_unserialize((string) $r->meta);
            if (is_array($meta)) {
                $prefix = dirname($r->file) === '.' ? '' : dirname($r->file) . '/';
                foreach ($meta['sizes'] ?? [] as $size) {
                    if (!empty($size['file'])) {
                        $paths[] = $prefix . $size['file'];
                    }
                }
                if (!empty($meta['original_image'])) {
                    $paths[] = $prefix . $meta['original_image'];
                }
            }
            $out[(int) $r->ID] = [
                'id'     => (int) $r->ID,
                'parent' => (int) $r->post_parent,
                'file'   => (string) $r->file,
                'url'    => NbuImageSource::normalize((string) $r->url),
                'paths'  => array_values(array_unique($paths)),
            ];
        }

        return $out;
    }

    /** @return array<int,true> */
    private function usedAttachmentIds(): array
    {
        global $wpdb;

        $used = [];
        foreach ($wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'images_gallery'") as $v) {
            foreach ((array) maybe_unserialize($v) as $id) {
                $used[(int) $id] = true;
            }
        }
        foreach ($wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'") as $id) {
            $used[(int) $id] = true;
        }
        unset($used[0]);

        return $used;
    }

    /**
     * Downloads the attachment's source image into a new WebP file in the same month folder and
     * repoints the attachment at it. Returns the new relative path.
     *
     * @param array{id:int, parent:int, file:string, url:string, paths:string[]} $a
     * @return string|\WP_Error
     */
    private function redownload(array $a)
    {
        $tmp = download_url($a['url'], 20);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        // download_url() leaves a `.tmp`; give the image editor the real extension to go by.
        $typed = $tmp . '.' . pathinfo($a['url'], PATHINFO_EXTENSION);
        if (rename($tmp, $typed)) {
            $tmp = $typed;
        }

        $uploads = wp_upload_dir();
        $relDir  = dirname($a['file']);
        $dir     = $uploads['basedir'] . '/' . $relDir;
        $name    = pathinfo(NbuImageSource::localFilename($a['url']), PATHINFO_FILENAME) . '.webp';
        $dest    = $dir . '/' . wp_unique_filename($dir, $name);

        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            @unlink($tmp);

            return $editor;
        }
        $editor->set_quality(82); // convert-to-webp.php's quality
        $saved = $editor->save($dest, 'image/webp');
        @unlink($tmp);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $rel = $relDir . '/' . basename($saved['path']);

        update_attached_file($a['id'], $saved['path']);
        wp_update_post([
            'ID'             => $a['id'],
            'post_mime_type' => 'image/webp',
            'guid'           => $uploads['baseurl'] . '/' . $rel,
        ]);
        wp_update_attachment_metadata($a['id'], wp_generate_attachment_metadata($a['id'], $saved['path']));

        return $rel;
    }
}
