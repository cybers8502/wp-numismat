<?php
/**
 * Run: wp --path=/path/to/wp eval-file convert-to-webp.php
 *
 * Converts all JPG/PNG attachments to WebP, updates DB records,
 * deletes originals. Safe: skips files already in WebP, skips if
 * imagick/gd can't handle the source.
 */

$dry_run = in_array( '--dry-run', $GLOBALS['argv'] ?? [] );

if ( $dry_run ) {
	WP_CLI::log( '=== DRY RUN — no files will be changed ===' );
}

$upload_dir = wp_upload_dir();
$base_dir   = $upload_dir['basedir'];

$query = new WP_Query( [
	'post_type'      => 'attachment',
	'post_mime_type' => [ 'image/jpeg', 'image/png' ],
	'post_status'    => 'inherit',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

$ids   = $query->posts;
$total = count( $ids );

WP_CLI::log( "Found $total attachments to process." );

$converted = 0;
$skipped   = 0;
$errors    = 0;

foreach ( $ids as $id ) {
	$file = get_attached_file( $id );

	if ( ! $file || ! file_exists( $file ) ) {
		WP_CLI::warning( "[$id] File not found: $file — skipping." );
		$skipped++;
		continue;
	}

	$info    = pathinfo( $file );
	$dir     = $info['dirname'];
	$name    = $info['filename'];
	$ext     = strtolower( $info['extension'] );
	$webp    = $dir . '/' . $name . '.webp';
	$rel_orig = str_replace( $base_dir . '/', '', $file );
	$rel_webp = str_replace( $base_dir . '/', '', $webp );

	// Convert main file
	if ( ! convert_to_webp( $file, $webp, $dry_run ) ) {
		WP_CLI::warning( "[$id] Conversion failed: $file" );
		$errors++;
		continue;
	}

	// Update metadata
	$meta = wp_get_attachment_metadata( $id );

	if ( ! $dry_run ) {
		// Update registered thumbnail sizes
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => &$size_data ) {
				$src_thumb  = $dir . '/' . $size_data['file'];
				$info_thumb = pathinfo( $src_thumb );
				$webp_thumb = $info_thumb['dirname'] . '/' . $info_thumb['filename'] . '.webp';

				if ( file_exists( $src_thumb ) && convert_to_webp( $src_thumb, $webp_thumb, false ) ) {
					unlink( $src_thumb );
					$size_data['file']      = $info_thumb['filename'] . '.webp';
					$size_data['mime-type'] = 'image/webp';
				}
			}
			unset( $size_data );
		}

		$meta['file'] = $rel_webp;

		wp_update_attachment_metadata( $id, $meta );
		update_attached_file( $id, $webp );

		// Update mime type and guid in wp_posts
		wp_update_post( [
			'ID'             => $id,
			'post_mime_type' => 'image/webp',
			'guid'           => str_replace( $rel_orig, $rel_webp, get_post_field( 'guid', $id ) ),
		] );

		// Replace URL references in post content across the site
		$old_url = wp_get_attachment_url( $id );
		$new_url = str_replace( '.' . $ext, '.webp', $old_url );
		if ( $old_url !== $new_url ) {
			// Update src references in post_content
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
					$old_url,
					$new_url,
					'%' . $wpdb->esc_like( $old_url ) . '%'
				)
			);
		}

		// Delete original only after everything is saved
		unlink( $file );
	}

	WP_CLI::log( sprintf( '[%d] %s → %s%s', $id, basename( $file ), basename( $webp ), $dry_run ? ' (dry run)' : '' ) );
	$converted++;
}

WP_CLI::success( "Done. Converted: $converted | Skipped: $skipped | Errors: $errors" );

// ---------------------------------------------------------------------------

function convert_to_webp( string $src, string $dest, bool $dry_run ): bool {
	if ( $dry_run ) {
		return true;
	}

	if ( file_exists( $dest ) ) {
		return true; // already converted
	}

	// Prefer Imagick (better quality, handles large files)
	if ( class_exists( 'Imagick' ) ) {
		try {
			$img = new Imagick( $src );
			$img->setImageFormat( 'webp' );
			$img->setImageCompressionQuality( 82 );
			// Strip EXIF to save space (remove if you need to keep it)
			$img->stripImage();
			$img->writeImage( $dest );
			$img->clear();
			return true;
		} catch ( Exception $e ) {
			// fall through to GD
		}
	}

	// Fallback: GD
	if ( ! function_exists( 'imagewebp' ) ) {
		return false;
	}

	$ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
	$img = match ( $ext ) {
		'jpg', 'jpeg' => @imagecreatefromjpeg( $src ),
		'png'         => @imagecreatefrompng( $src ),
		default       => false,
	};

	if ( ! $img ) {
		return false;
	}

	$ok = imagewebp( $img, $dest, 82 );
	imagedestroy( $img );
	return $ok;
}