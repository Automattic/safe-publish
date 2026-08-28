<?php
/**
 * Term assignment state used when reverting post updates.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures and restores the term assignments an update can replace.
 */
final class Term_Assignment_State {

	/**
	 * Captures assignments for the taxonomies present in an update.
	 *
	 * @param int          $post_id Post ID.
	 * @param array|object $terms   Incoming terms keyed by taxonomy.
	 * @return array<string, int[]> Term IDs keyed by taxonomy.
	 */
	public static function capture( int $post_id, array|object $terms ): array {
		$assignments = array();

		foreach ( (array) $terms as $taxonomy => $_ ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = wp_get_object_terms(
				$post_id,
				$taxonomy,
				array( 'fields' => 'ids' )
			);

			if ( ! is_wp_error( $term_ids ) ) {
				$assignments[ $taxonomy ] = array_map( 'intval', $term_ids );
			}
		}

		return $assignments;
	}

	/**
	 * Validates captured assignments before a delayed rollback changes a post.
	 *
	 * @param array $assignments Term IDs keyed by taxonomy.
	 * @return true|WP_Error True when every recorded assignment is available.
	 */
	public static function validate( array $assignments ): true|WP_Error {
		foreach ( $assignments as $taxonomy => $term_ids ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $term_ids ) ) {
				return new WP_Error(
					'invalid_term_assignment_snapshot',
					__( 'The saved term assignments are invalid.', 'safe-publish' )
				);
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'rollback_taxonomy_unavailable',
					__( 'A taxonomy needed for this rollback is unavailable.', 'safe-publish' )
				);
			}

			foreach ( $term_ids as $term_id ) {
				if (
					! is_int( $term_id )
					|| $term_id <= 0
					|| null === term_exists( $term_id, $taxonomy )
				) {
					return new WP_Error(
						'rollback_term_unavailable',
						__( 'A term needed for this rollback is unavailable.', 'safe-publish' )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Restores captured assignments.
	 *
	 * @param int                  $post_id     Post ID.
	 * @param array<string, int[]> $assignments Term IDs keyed by taxonomy.
	 * @return true|WP_Error True on success, or an error from WordPress.
	 */
	public static function restore(
		int $post_id,
		array $assignments
	): true|WP_Error {
		foreach ( $assignments as $taxonomy => $term_ids ) {
			$result = wp_set_object_terms(
				$post_id,
				array_map( 'intval', $term_ids ),
				(string) $taxonomy,
				false
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}
}
