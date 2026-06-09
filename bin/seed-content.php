<?php
/**
 * Content seeder for development and integration testing.
 *
 * Generates posts, pages, or CPTs with optional media for testing the import
 * process.
 *
 * Important: For all typical uses, you should use the bin/seed script. This
 * file shouldn't be invoked directly, unless you are already inside a WP-CLI
 * session with access to the target WordPress install.
 *
 * Arguments:
 *   mode=    create (default) inserts new posts; update bumps existing
 *            seeded posts to a new revision.
 *   count=   Number of posts to create (default: 20). Ignored when
 *            mode=update.
 *   start=   Starting post number (default: 1). Use to avoid duplicate numbers
 *            across batches. Ignored when mode=update.
 *   type=    Post type slug (default: post). Filters which seeded posts to
 *            touch in update mode.
 *   editor=  Content format: gutenberg (default), classic, or mixed (2/3
 *            Gutenberg, 1/3 classic).
 *   images=  Image mode: 1, 2, 2-resized, or auto (default).
 *            auto rotates through all three modes as posts are created.
 *   date-offset= Shift all post dates this many additional days into the
 *            past (default: 0). Use in multi-batch presets so each batch
 *            occupies a distinct date range.
 *   purge=   Set to 1 to delete all previously seeded content and exit
 *            without inserting anything. Not allowed with mode=update.
 *   fresh=   Set to 1 to delete all previously seeded content before seeding.
 *            Not allowed with mode=update.
 *   prefix=  Optional string prepended to every post title (e.g. prefix=Run2
 *            → "Run2 Post 1 - 1P").
 *   revision= Target revision number for mode=update. Omit to auto-bump
 *            each post's current revision by one.
 *
 * Seeded content is tagged with _seeder_generated=1 post meta, enabling
 * clean fresh runs.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

use Safe_Publish\Seeder\Content_Generator;

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

	$mode     = $params['mode'] ?? 'create';
	$count    = max( 1, (int) ( $params['count'] ?? 20 ) );
	$start    = max( 1, (int) ( $params['start'] ?? 1 ) );
	$type     = sanitize_key( $params['type'] ?? 'post' );
	$editor   = $params['editor'] ?? 'gutenberg';
	$images   = $params['images'] ?? 'auto';
	$fresh    = ! empty( $params['fresh'] );
	$purge    = ! empty( $params['purge'] );
	$prefix   = isset( $params['prefix'] )
		? sanitize_text_field( $params['prefix'] )
		: '';
	$offset   = max( 0, (int) ( $params['date-offset'] ?? 0 ) );
	$revision = max( 0, (int) ( $params['revision'] ?? 0 ) );

	if ( ! in_array( $mode, array( 'create', 'update' ), true ) ) {
		WP_CLI::error( "Invalid mode '{$mode}'. Use: create or update." );
	}

	if ( 'update' === $mode ) {
		if ( $fresh || $purge ) {
			WP_CLI::error(
				'fresh=1 and purge=1 are not allowed with mode=update.'
			);
		}

		$author_id = safe_publish_seeder_resolve_author();
		if ( 0 === $author_id ) {
			WP_CLI::error(
				'No users available to attribute seeded updates to.'
			);
		}
		wp_set_current_user( $author_id );

		// Limit by type only when the caller passed it explicitly, so a
		// bare mode=update touches everything the seeder has produced.
		$update_filter = isset( $params['type'] ) ? $type : '';
		safe_publish_seeder_update_content( $revision, $update_filter );
		return;
	}

	if ( $purge ) {
		safe_publish_seeder_delete_content();
		return;
	}

	if ( ! post_type_exists( $type ) ) {
		WP_CLI::error( "Post type '{$type}' is not registered." );
	}

	$author_id = safe_publish_seeder_resolve_author();
	if ( 0 === $author_id ) {
		WP_CLI::error(
			'No users available to attribute seeded content to.'
		);
	}

	// Drives post_author and attachment ownership for posts and media
	// inserted below; eval-file runs unauthenticated by default.
	wp_set_current_user( $author_id );

	try {
		$generator = new Content_Generator(
			$type,
			$editor,
			$images,
			$count,
			$start,
			$offset,
			$prefix,
			time(),
			home_url()
		);
	} catch ( \InvalidArgumentException $e ) {
		// WP_CLI::error() exits; the return keeps flow explicit for static analysis.
		WP_CLI::error( $e->getMessage() );
		return;
	}

	if ( $fresh ) {
		safe_publish_seeder_delete_content();
	}

	$inserted = 0;

	for ( $i = $start; $i < $start + $count; $i++ ) {
		$image_mode = $generator->resolve_image_mode( $i );
		$image_ids  = safe_publish_seeder_generate_images( $i, $image_mode );
		$image_refs = safe_publish_seeder_image_refs( $image_ids );

		$payload = $generator->generate( $i, $image_refs );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $payload['title'],
				'post_name'    => $payload['slug'],
				'post_content' => $payload['content'],
				'post_excerpt' => $payload['excerpt'],
				'post_status'  => $payload['status'],
				'post_type'    => $payload['post_type'],
				'post_date'    => $payload['date'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::warning(
				"Could not create '{$payload['title']}': "
				. $post_id->get_error_message()
			);
			continue;
		}

		foreach ( $payload['meta'] as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		safe_publish_seeder_apply_term_assignments(
			$post_id,
			$payload['terms']
		);

		if ( $payload['featured_media'] > 0 ) {
			set_post_thumbnail( $post_id, $payload['featured_media'] );
		}

		++$inserted;
	}

	WP_CLI::success( "Seeded {$inserted} {$type}(s)." );
}

// $args is provided by WP-CLI eval-file.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $args is a WP-CLI global.
safe_publish_seeder_run( $args ?? array() );

/**
 * Resolves the user ID to attribute seeded posts and media to.
 *
 * WP-CLI eval-file runs unauthenticated, so wp_insert_post() and
 * media_handle_sideload() would otherwise default post_author to 0. Prefers
 * the lowest-ID administrator and falls back to the lowest-ID user of any
 * role.
 *
 * @return int User ID, or 0 if no users exist.
 */
function safe_publish_seeder_resolve_author(): int {
	$admins = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		)
	);

	if ( array() !== $admins ) {
		return (int) $admins[0];
	}

	$users = get_users(
		array(
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		)
	);

	return array() === $users ? 0 : (int) $users[0];
}

/**
 * Converts an array of attachment IDs into the [id, url] structure expected
 * by Content_Generator.
 *
 * @param int[] $image_ids Attachment IDs (may be empty).
 * @return list<array{id: int, url: string}> Image references; entries with
 *                                           no resolvable URL are dropped.
 */
function safe_publish_seeder_image_refs( array $image_ids ): array {
	$refs = array();

	foreach ( $image_ids as $id ) {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			continue;
		}
		$refs[] = array(
			'id'  => $id,
			'url' => $url,
		);
	}

	return $refs;
}

/**
 * Applies term assignments from a seeder payload to a post.
 *
 * Silently skips taxonomies that the post's type does not support.
 *
 * @param int                         $post_id     Post ID.
 * @param array<string, list<string>> $assignments Term assignments keyed by taxonomy.
 */
function safe_publish_seeder_apply_term_assignments(
	int $post_id,
	array $assignments
): void {
	$post_type = get_post_type( $post_id );
	$config    = Content_Generator::term_config();

	foreach ( $assignments as $taxonomy => $term_values ) {
		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			continue;
		}

		$field = $config[ $taxonomy ]['field'] ?? 'name';
		$ids   = safe_publish_seeder_get_or_create_term_ids(
			$term_values,
			$taxonomy,
			$field
		);

		if ( array() !== $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy );
		}
	}
}

/**
 * Returns term IDs for the given term values, creating any that don't yet
 * exist.
 *
 * @param string[] $values   Term names or slugs to look up.
 * @param string   $taxonomy Taxonomy name.
 * @param string   $field    Field to look up by ('name' or 'slug').
 * @return int[] Array of term IDs.
 */
function safe_publish_seeder_get_or_create_term_ids(
	array $values,
	string $taxonomy,
	string $field
): array {
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
 * Bumps every seeded post to a new revision, applying deterministic
 * mutations to title, excerpt, content, status, meta, and term assignments.
 *
 * Slug, date, and attachments are preserved so the migration's update path
 * can be exercised without churning IDs or media.
 *
 * @param int    $revision_arg Explicit revision number; 0 means auto-bump
 *                             each post's existing revision by one.
 * @param string $type_filter  Post type to limit the update to; empty
 *                             string means every seeded post type.
 */
function safe_publish_seeder_update_content(
	int $revision_arg,
	string $type_filter
): void {
	$post_ids = get_posts(
		array(
			'post_type'              => '' !== $type_filter ? $type_filter : 'any',
			'post_status'            => 'any',
			'posts_per_page'         => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- development tool.
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- development tool.
				array(
					'key'   => Content_Generator::SEEDER_META_KEY,
					'value' => '1',
				),
			),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		)
	);

	if ( array() === $post_ids ) {
		WP_CLI::log( 'No previously seeded content found to update.' );
		return;
	}

	// One generator covers every post: the methods we call below don't read
	// any of the configuration-bound state.
	$generator = new Content_Generator(
		'post',
		'gutenberg',
		'1',
		1,
		1,
		0,
		'',
		time(),
		home_url()
	);

	$updated = 0;
	$skipped = 0;

	foreach ( $post_ids as $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		// Seeded attachments share the meta tag for cleanup but aren't
		// updateable content; silently skip them without counting.
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type ) {
			continue;
		}

		$index = Content_Generator::extract_index_from_slug( $post->post_name );
		if ( null === $index ) {
			WP_CLI::warning(
				"Post {$post_id} is tagged as seeded but its slug "
				. "'{$post->post_name}' doesn't match the seeder format; skipping."
			);
			++$skipped;
			continue;
		}

		$current_rev = (int) get_post_meta(
			$post_id,
			Content_Generator::REVISION_META_KEY,
			true
		);
		$new_rev     = $revision_arg > 0 ? $revision_arg : $current_rev + 1;

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => Content_Generator::apply_revision_suffix(
					$post->post_title,
					$new_rev
				),
				'post_excerpt' => Content_Generator::apply_revision_suffix(
					$post->post_excerpt,
					$new_rev
				),
				'post_content' => Content_Generator::apply_revision_to_content(
					$post->post_content,
					$new_rev
				),
				'post_status'  => $generator->resolve_status( $index, $new_rev ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::warning(
				"Could not update post {$post_id}: "
				. $result->get_error_message()
			);
			++$skipped;
			continue;
		}

		foreach ( $generator->meta_values( $index, $new_rev ) as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		safe_publish_seeder_apply_term_assignments(
			$post_id,
			$generator->term_assignments( $index, $new_rev )
		);

		++$updated;
	}

	$summary = "Updated {$updated} post(s)";
	if ( $skipped > 0 ) {
		$summary .= " (skipped {$skipped})";
	}
	$summary .= '.';

	if ( 0 === $updated ) {
		WP_CLI::log( $summary );
	} else {
		WP_CLI::success( $summary );
	}
}

/**
 * Deletes all content previously created by the seeder.
 */
function safe_publish_seeder_delete_content(): void {
	$meta_query = array(
		array(
			'key'   => Content_Generator::SEEDER_META_KEY,
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
 * Deletes the seeder categories and tags created during seeding.
 */
function safe_publish_seeder_delete_terms(): void {
	foreach ( Content_Generator::term_config() as $taxonomy => $config ) {
		foreach ( $config['terms'] as $value ) {
			$term = get_term_by( $config['field'], $value, $taxonomy );
			if ( $term instanceof WP_Term ) {
				wp_delete_term( $term->term_id, $taxonomy );
			}
		}
	}
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
 * Creates a half-size copy of an existing attachment and imports it into the
 * media library.
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

	if ( false === $resized ) {
		WP_CLI::warning( "Could not scale image for attachment {$source_id}." );
		return 0;
	}

	$tmpfile = wp_tempnam( 'seeder_resized_' );
	imagejpeg( $resized, $tmpfile, 90 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_system_read -- imagejpeg() has no WP Filesystem equivalent.

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

	update_post_meta( $attachment_id, Content_Generator::SEEDER_META_KEY, '1' );

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

	update_post_meta( $attachment_id, Content_Generator::SEEDER_META_KEY, '1' );

	return $attachment_id;
}
