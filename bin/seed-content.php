<?php
/**
 * Content seeder for development and integration testing.
 *
 * Generates posts, pages, or CPTs with optional media for testing the import process.
 *
 * Important: For all typical uses, you should use the bin/seed script. This file
 * shouldn't be invoked directly, unless you are already inside a WP-CLI session
 * with access to the target WordPress install.
 *
 * Arguments:
 *   count=   Number of posts to create (default: 20).
 *   start=   Starting post number (default: 1). Use to avoid duplicate numbers across batches.
 *   type=    Post type slug (default: post).
 *   editor=  Content format: gutenberg (default), classic, or mixed (2/3 Gutenberg, 1/3 classic).
 *   images=  Image mode: 1, 2, 2-resized, or auto (default).
 *            auto rotates through all three modes as posts are created.
 *   fresh=   Set to 1 to delete all previously seeded content before seeding.
 *
 * Seeded content is tagged with _seeder_generated=1 post meta, enabling clean fresh runs.
 *
 * @package Safe_Publish
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( 'This script must be run via WP-CLI.' . PHP_EOL );
}

/**
 * Main seeder entry point.
 *
 * @param array $args WP-CLI positional arguments (key=value format).
 */
function safe_publish_seeder_run( array $args ): void {
	parse_str( implode( '&', $args ), $params );

	$count  = max( 1, (int) ( $params['count'] ?? 20 ) );
	$start  = max( 1, (int) ( $params['start'] ?? 1 ) );
	$type   = sanitize_key( $params['type'] ?? 'post' );
	$editor = $params['editor'] ?? 'gutenberg';
	$images = $params['images'] ?? 'auto';
	$fresh  = ! empty( $params['fresh'] );

	if ( ! in_array( $editor, array( 'gutenberg', 'classic', 'mixed' ), true ) ) {
		WP_CLI::error( "Invalid editor value '{$editor}'. Use: gutenberg, classic, or mixed." );
	}

	if ( ! in_array( $images, array( '1', '2', '2-resized', 'auto' ), true ) ) {
		WP_CLI::error( "Invalid images value '{$images}'. Use: 1, 2, 2-resized, or auto." );
	}

	if ( ! post_type_exists( $type ) ) {
		WP_CLI::error( "Post type '{$type}' is not registered." );
	}

	if ( $fresh ) {
		safe_publish_seeder_delete_content();
	}

	$inserted = 0;

	for ( $i = $start; $i < $start + $count; $i++ ) {
		$use_gutenberg = safe_publish_seeder_resolve_editor( $editor, $i );
		$image_mode    = safe_publish_seeder_resolve_image_mode( $images, $i );
		$image_ids     = safe_publish_seeder_generate_images( $i, $image_mode );
		$editor_label  = $use_gutenberg ? 'BE' : 'CE';
		$img_label     = safe_publish_seeder_image_label( $image_mode, count( $image_ids ) );
		$title         = ucfirst( $type ) . " {$i} {$editor_label} - {$img_label}";
		$slug          = "seeder-{$type}-{$i}";
		$content       = $use_gutenberg
			? safe_publish_seeder_gutenberg_content( $i, $image_ids )
			: safe_publish_seeder_classic_content( $i, $image_ids );
		$excerpt       = "Excerpt for seeded {$type} number {$i}.";

		// Rotate statuses: publish (default), draft every 5th, private every 6th.
		$status = 'publish';
		if ( 0 === $i % 6 ) {
			$status = 'private';
		} elseif ( 0 === $i % 5 ) {
			$status = 'draft';
		}

		// Spread posts over the past 90 days, oldest first.
		$days_ago  = (int) round( ( $count - $i ) * 90 / max( 1, $count ) );
		$post_date = wp_date( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $status,
				'post_type'    => $type,
				'post_date'    => $post_date,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::warning( "Could not create '{$title}': " . $post_id->get_error_message() );
			continue;
		}

		update_post_meta( $post_id, '_seeder_generated', '1' );
		safe_publish_seeder_add_meta( $post_id, $i );
		safe_publish_seeder_assign_terms( $post_id, $i );

		if ( ! empty( $image_ids ) ) {
			set_post_thumbnail( $post_id, $image_ids[0] );
		}

		++$inserted;
	}

	WP_CLI::success( "Seeded {$inserted} {$type}(s)." );
}

// $args is provided by WP-CLI eval-file.
safe_publish_seeder_run( $args ?? array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $args is a WP-CLI global.

/**
 * Deletes all content previously created by the seeder.
 */
function safe_publish_seeder_delete_content(): void {
	$meta_query = array(
		array(
			'key'   => '_seeder_generated',
			'value' => '1',
		),
	);

	$ids = get_posts(
		array(
			'post_type'              => 'any',
			'post_status'            => 'any',
			'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- development tool.
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- development tool.
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		)
	);

	$deleted = 0;

	foreach ( $ids as $id ) {
		if ( 'attachment' === get_post_type( (int) $id ) ) {
			wp_delete_attachment( (int) $id, true );
		} else {
			wp_delete_post( (int) $id, true );
		}
		++$deleted;
	}

	if ( 0 === $deleted ) {
		WP_CLI::log( 'No previously seeded content found.' );
	} else {
		WP_CLI::log( "Deleted {$deleted} previously seeded item(s)." );
	}

	safe_publish_seeder_delete_terms();
}

/**
 * Resolves the concrete image mode for a given post index.
 *
 * @param string $mode  Image mode: '1', '2', '2-resized', or 'auto'.
 * @param int    $index Post index, used to cycle through modes in 'auto'.
 * @return string Resolved mode: '1', '2', or '2-resized'.
 */
function safe_publish_seeder_resolve_image_mode( string $mode, int $index ): string {
	if ( 'auto' !== $mode ) {
		return $mode;
	}

	$modes = array( '1', '2', '2-resized' );
	return $modes[ ( $index - 1 ) % count( $modes ) ];
}

/**
 * Returns a human-readable image label for use in post titles.
 *
 * @param string $mode      Resolved image mode: '1', '2', or '2-resized'.
 * @param int    $img_count Actual number of generated images.
 * @return string Label such as '1 img', '2 imgs', or '2 imgs resized'.
 */
function safe_publish_seeder_image_label( string $mode, int $img_count ): string {
	$base = 1 === $img_count ? '1 img' : "{$img_count} imgs";
	return '2-resized' === $mode ? "{$base} resized" : $base;
}

/**
 * Generates one or more images based on the requested mode.
 *
 * @param int    $index Post index, used to label images.
 * @param string $mode  Resolved image mode: '1', '2', or '2-resized'.
 * @return int[] Array of attachment IDs (may be empty if GD is unavailable).
 */
function safe_publish_seeder_generate_images( int $index, string $mode ): array {

	$first_id = safe_publish_seeder_generate_image( "Seeded image {$index}a" );

	if ( '1' === $mode || 0 === $first_id ) {
		return $first_id > 0 ? array( $first_id ) : array();
	}

	$ids = array( $first_id );

	if ( '2' === $mode ) {
		$second_id = safe_publish_seeder_generate_image( "Seeded image {$index}b" );
		if ( $second_id > 0 ) {
			$ids[] = $second_id;
		}
	} elseif ( '2-resized' === $mode ) {
		$second_id = safe_publish_seeder_generate_resized_image( $first_id, $index );
		if ( $second_id > 0 ) {
			$ids[] = $second_id;
		}
	}

	return $ids;
}

/**
 * Creates a half-size copy of an existing attachment and imports it into the media library.
 *
 * @param int $source_id Attachment ID of the original image.
 * @param int $index     Post index, used to label the resized image.
 * @return int Attachment ID of the resized image, or 0 on failure.
 */
function safe_publish_seeder_generate_resized_image( int $source_id, int $index ): int {
	$source_path = get_attached_file( $source_id );

	if ( ! $source_path || ! file_exists( $source_path ) ) {
		WP_CLI::warning( "Source image file not found for attachment {$source_id}. Skipping resize." );
		return 0;
	}

	$img = imagecreatefromjpeg( $source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_system_read -- no WP Filesystem equivalent for loading GD resources.

	if ( false === $img ) {
		WP_CLI::warning( "Could not load source image for resizing (attachment {$source_id})." );
		return 0;
	}

	$new_w   = (int) round( imagesx( $img ) * 0.5 );
	$new_h   = (int) round( imagesy( $img ) * 0.5 );
	$resized = imagescale( $img, $new_w, $new_h );
	imagedestroy( $img );

	if ( false === $resized ) {
		WP_CLI::warning( "Could not scale image for attachment {$source_id}." );
		return 0;
	}

	$tmpfile = wp_tempnam( 'seeder_resized_' );
	imagejpeg( $resized, $tmpfile, 90 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_system_read -- imagejpeg() has no WP Filesystem equivalent.
	imagedestroy( $resized );

	$attachment_id = media_handle_sideload(
		array(
			'name'     => sanitize_file_name( "Seeded image {$index} resized.jpg" ),
			'tmp_name' => $tmpfile,
		),
		0
	);

	wp_delete_file( $tmpfile );

	if ( is_wp_error( $attachment_id ) ) {
		WP_CLI::warning( 'Failed to import resized image: ' . $attachment_id->get_error_message() );
		return 0;
	}

	update_post_meta( $attachment_id, '_seeder_generated', '1' );

	return $attachment_id;
}

/**
 * Generates a unique JPEG using GD and imports it into the media library.
 *
 * @param string $label Text label to render on the image.
 * @return int Attachment ID, or 0 on failure.
 */
function safe_publish_seeder_generate_image( string $label ): int {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		WP_CLI::warning( 'GD extension is not available. Skipping image generation.' );
		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$img = imagecreatetruecolor( 800, 600 );

	if ( false === $img ) {
		WP_CLI::warning( "Could not allocate GD image for '{$label}'." );
		return 0;
	}

	$bg = imagecolorallocate( $img, wp_rand( 80, 200 ), wp_rand( 80, 200 ), wp_rand( 80, 200 ) );
	$fg = imagecolorallocate( $img, 255, 255, 255 );

	imagefill( $img, 0, 0, $bg );
	imagestring( $img, 5, 20, 20, $label, $fg );

	$tmpfile = wp_tempnam( 'seeder_' );
	imagejpeg( $img, $tmpfile, 90 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_system_read -- imagejpeg() has no WP Filesystem equivalent.
	imagedestroy( $img );

	$attachment_id = media_handle_sideload(
		array(
			'name'     => sanitize_file_name( $label . '.jpg' ),
			'tmp_name' => $tmpfile,
		),
		0
	);

	wp_delete_file( $tmpfile );

	if ( is_wp_error( $attachment_id ) ) {
		WP_CLI::warning( "Failed to import image '{$label}': " . $attachment_id->get_error_message() );
		return 0;
	}

	update_post_meta( $attachment_id, '_seeder_generated', '1' );

	return $attachment_id;
}

/**
 * Resolves whether a given post index should use the block editor.
 *
 * @param string $editor Editor mode: 'gutenberg', 'classic', or 'mixed'.
 * @param int    $index  Post index.
 * @return bool True for block editor, false for classic editor.
 */
function safe_publish_seeder_resolve_editor( string $editor, int $index ): bool {
	return match ( $editor ) {
		'gutenberg' => true,
		'classic'   => false,
		default     => 0 !== $index % 3, // 2 out of 3 use Gutenberg in mixed mode.
	};
}

/**
 * Produces Gutenberg block markup with a paragraph and optional image blocks.
 *
 * @param int   $index     Post index, used for varied content.
 * @param int[] $image_ids Attachment IDs to embed. Empty array to skip.
 * @return string Block markup.
 */
function safe_publish_seeder_gutenberg_content( int $index, array $image_ids ): string {
	$content  = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Heading for post {$index}</h2>\n<!-- /wp:heading -->";
	$content .= "\n\n<!-- wp:paragraph -->\n<p>This is seeded post number {$index}. It uses the block editor.</p>\n<!-- /wp:paragraph -->";
	$content .= "\n\n<!-- wp:paragraph -->\n<p>Second paragraph with additional content for testing the import process and URL rewriting.</p>\n<!-- /wp:paragraph -->";

	foreach ( $image_ids as $image_id ) {
		$image_url = wp_get_attachment_url( $image_id );

		if ( $image_url ) {
			$content .= "\n\n<!-- wp:image {\"id\":{$image_id},\"sizeSlug\":\"large\"} -->\n";
			$content .= '<figure class="wp-block-image size-large">';
			$content .= '<img src="' . esc_url( $image_url ) . '" alt="" class="wp-image-' . absint( $image_id ) . '"/>';
			$content .= "</figure>\n";
			$content .= '<!-- /wp:image -->';
		}
	}

	$content .= "\n\n<!-- wp:paragraph -->\n<p>Third paragraph, appearing after any images, for additional import testing coverage.</p>\n<!-- /wp:paragraph -->";
	$content .= "\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>List item one</li><li>List item two</li><li>List item three</li></ul>\n<!-- /wp:list -->";

	return $content;
}

/**
 * Produces classic editor HTML with a paragraph and optional inline images.
 *
 * @param int   $index     Post index, used for varied content.
 * @param int[] $image_ids Attachment IDs to embed. Empty array to skip.
 * @return string HTML content.
 */
function safe_publish_seeder_classic_content( int $index, array $image_ids ): string {
	$content  = "<h2>Heading for post {$index}</h2>";
	$content .= "\n<p>This is seeded post number {$index}. It uses the classic editor.</p>";
	$content .= "\n<p>Second paragraph with additional content for testing the import process and URL rewriting.</p>";

	$first = true;

	foreach ( $image_ids as $image_id ) {
		$image_url = wp_get_attachment_url( $image_id );

		if ( $image_url ) {
			if ( $first ) {
				// First image uses a [caption] shortcode to test shortcode preservation on import.
				$content .= "\n[caption id=\"attachment_{$image_id}\" align=\"aligncenter\" width=\"800\"]";
				$content .= '<img src="' . esc_url( $image_url ) . '" alt="Seeded image" width="800" height="600" />';
				$content .= " Caption for seeded image {$index}.[/caption]";
				$first    = false;
			} else {
				$content .= "\n<p><img src=\"" . esc_url( $image_url ) . '" alt="Seeded image" /></p>';
			}
		}
	}

	$content .= "\n<p>Third paragraph, appearing after any images, for additional import testing coverage.</p>";
	$content .= "\n<ul>\n<li>List item one</li>\n<li>List item two</li>\n<li>List item three</li>\n</ul>";

	return $content;
}

/**
 * Adds custom meta fields to a seeded post.
 *
 * @param int $post_id Post ID.
 * @param int $index   Post index, used to vary values across posts.
 */
function safe_publish_seeder_add_meta( int $post_id, int $index ): void {
	$colors = array( 'red', 'green', 'blue', 'yellow' );

	update_post_meta( $post_id, 'seeder_color', $colors[ $index % count( $colors ) ] );
	update_post_meta( $post_id, 'seeder_priority', ( $index % 10 ) + 1 );
}

/**
 * Returns the canonical seeder term configuration used by assign and delete operations.
 *
 * @return array{category: array{field: string, terms: string[]}, post_tag: array{field: string, terms: string[]}}
 */
function safe_publish_seeder_term_config(): array {
	return array(
		'category' => array(
			'field' => 'name',
			'terms' => array( 'Seeder Category A', 'Seeder Category B', 'Seeder Category C' ),
		),
		'post_tag' => array(
			'field' => 'slug',
			'terms' => array( 'seeder-alpha', 'seeder-beta', 'seeder-gamma', 'seeder-delta' ),
		),
	);
}

/**
 * Creates and assigns seeder categories and tags to a post.
 *
 * Silently skips post types that do not support a given taxonomy.
 *
 * @param int $post_id Post ID.
 * @param int $index   Post index, used to rotate term assignments.
 */
function safe_publish_seeder_assign_terms( int $post_id, int $index ): void {
	$post_type = get_post_type( $post_id );

	foreach ( safe_publish_seeder_term_config() as $taxonomy => $config ) {
		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			continue;
		}

		$ids = safe_publish_seeder_get_or_create_term_ids( $config['terms'], $taxonomy, $config['field'] );

		if ( ! empty( $ids ) ) {
			$n = count( $ids );
			wp_set_object_terms( $post_id, array( $ids[ $index % $n ], $ids[ ( $index + 1 ) % $n ] ), $taxonomy );
		}
	}
}

/**
 * Returns term IDs for the given term values, creating any that do not yet exist.
 *
 * @param string[] $values   Term names or slugs to look up.
 * @param string   $taxonomy Taxonomy name.
 * @param string   $field    Field to look up by ('name' or 'slug').
 * @return int[] Array of term IDs.
 */
function safe_publish_seeder_get_or_create_term_ids( array $values, string $taxonomy, string $field ): array {
	$ids = array();

	foreach ( $values as $value ) {
		$term = get_term_by( $field, $value, $taxonomy );
		if ( $term instanceof WP_Term ) {
			$ids[] = $term->term_id;
		} else {
			$result = wp_insert_term( $value, $taxonomy );
			if ( ! is_wp_error( $result ) ) {
				$ids[] = (int) $result['term_id'];
			}
		}
	}

	return $ids;
}

/**
 * Deletes the seeder categories and tags created by safe_publish_seeder_assign_terms().
 */
function safe_publish_seeder_delete_terms(): void {
	foreach ( safe_publish_seeder_term_config() as $taxonomy => $config ) {
		foreach ( $config['terms'] as $value ) {
			$term = get_term_by( $config['field'], $value, $taxonomy );
			if ( $term instanceof WP_Term ) {
				wp_delete_term( $term->term_id, $taxonomy );
			}
		}
	}
}
