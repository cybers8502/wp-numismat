<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- a `wp eval-file` script: it runs top to bottom, its helper functions are only there for that run.

/**
 * Run: wp --path=/path/to/wp eval-file convert-to-webp.php
 *
 * Converts all JPG/PNG attachments to WebP, updates DB records,
 * deletes originals. Safe: skips files already in WebP, skips if
 * imagick/gd can't handle the source.
 *
 * A `.webp` that already exists at the target path belongs to some OTHER
 * attachment, never to this one (this attachment is still a JPG/PNG), so it
 * is never reused: the new file gets a unique name instead. Treating it as
 * "already converted" used to repoint every NBU `avers.jpg`/`revers.jpg`
 * (all coins share those basenames) at whichever coin's `avers.webp` got
 * there first — and delete the attachment's own, correct original.
 */

$dry_run = in_array('--dry-run', $GLOBALS['argv'] ?? []);

if ($dry_run) {
    WP_CLI::log('=== DRY RUN — no files will be changed ===');
}

$upload_dir = wp_upload_dir();
$base_dir   = $upload_dir['basedir'];

$query = new WP_Query([
    'post_type'      => 'attachment',
    'post_mime_type' => [ 'image/jpeg', 'image/png' ],
    'post_status'    => 'inherit',
    'posts_per_page' => -1,
    'fields'         => 'ids',
]);

$ids   = $query->posts;
$total = count($ids);

WP_CLI::log("Found $total attachments to process.");

$converted = 0;
$skipped   = 0;
$errors    = 0;

foreach ($ids as $id) {
    $file = get_attached_file($id);

    if (! $file || ! file_exists($file)) {
        WP_CLI::warning("[$id] File not found: $file — skipping.");
        $skipped++;
        continue;
    }

    $info    = pathinfo($file);
    $dir     = $info['dirname'];
    $name    = $info['filename'];
    $webp    = free_webp_path($dir, $name);
    $rel_orig = str_replace($base_dir . '/', '', $file);
    $rel_webp = str_replace($base_dir . '/', '', $webp);

    // Convert main file
    if (! convert_to_webp($file, $webp, $dry_run)) {
        WP_CLI::warning("[$id] Conversion failed: $file");
        $errors++;
        continue;
    }

    // Update metadata
    $meta = wp_get_attachment_metadata($id);

    if (! $dry_run) {
        // Update registered thumbnail sizes
        if (! empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $size => &$size_data) {
                $src_thumb  = $dir . '/' . $size_data['file'];
                $info_thumb = pathinfo($src_thumb);
                $webp_thumb = free_webp_path($info_thumb['dirname'], $info_thumb['filename']);

                if (file_exists($src_thumb) && convert_to_webp($src_thumb, $webp_thumb, false)) {
                    unlink($src_thumb);
                    $size_data['file']      = basename($webp_thumb);
                    $size_data['mime-type'] = 'image/webp';
                }
            }
            unset($size_data);
        }

        // Read before update_attached_file(), which changes what this returns.
        $old_url = wp_get_attachment_url($id);

        $meta['file'] = $rel_webp;

        wp_update_attachment_metadata($id, $meta);
        update_attached_file($id, $webp);

        // Update mime type and guid in wp_posts
        wp_update_post([
            'ID'             => $id,
            'post_mime_type' => 'image/webp',
            'guid'           => str_replace($rel_orig, $rel_webp, get_post_field('guid', $id)),
        ]);

        // Replace URL references in post content across the site
        $new_url = wp_get_attachment_url($id);
        if ($old_url !== $new_url) {
            // Update src references in post_content
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                    $old_url,
                    $new_url,
                    '%' . $wpdb->esc_like($old_url) . '%'
                )
            );
        }

        // Delete original only after everything is saved
        unlink($file);
    }

    WP_CLI::log(sprintf('[%d] %s → %s%s', $id, basename($file), basename($webp), $dry_run ? ' (dry run)' : ''));
    $converted++;
}

WP_CLI::success("Done. Converted: $converted | Skipped: $skipped | Errors: $errors");

// ---------------------------------------------------------------------------

/**
 * `$dir/$name.webp`, or a `-N`-suffixed variant of it when that name is taken.
 */
function free_webp_path(string $dir, string $name): string
{
    $path = $dir . '/' . $name . '.webp';
    if (! file_exists($path)) {
        return $path;
    }
    return $dir . '/' . wp_unique_filename($dir, $name . '.webp');
}

function convert_to_webp(string $src, string $dest, bool $dry_run): bool
{
    if ($dry_run) {
        return true;
    }

    // Prefer Imagick (better quality, handles large files)
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick($src);
            $img->setImageFormat('webp');
            $img->setImageCompressionQuality(82);
            // Strip EXIF to save space (remove if you need to keep it)
            $img->stripImage();
            $img->writeImage($dest);
            $img->clear();
            return true;
        } catch (Exception $e) {
            // fall through to GD
        }
    }

    // Fallback: GD
    if (! function_exists('imagewebp')) {
        return false;
    }

    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $img = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($src),
        'png'         => @imagecreatefrompng($src),
        default       => false,
    };

    if (! $img) {
        return false;
    }

    $ok = imagewebp($img, $dest, 82);
    imagedestroy($img);
    return $ok;
}
