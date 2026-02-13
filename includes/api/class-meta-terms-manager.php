<?php
/**
 * Meta Terms Manager class
 *
 * Handles updating post metadata and taxonomies/terms.
 *
 * @package Safe_Publish
 */

declare( strict_types=1 );

namespace Safe_Publish\API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta_Terms_Manager Class.
 *
 * Responsible for managing post meta and taxonomy term assignments.
 */
final class Meta_Terms_Manager {

	/**
	 * Updates post meta based on provided input.
	 *
	 * Accepts array or object; keys are meta keys, values are meta values.
	 *
	 * @param int          $post_id Post ID to update meta for.
	 * @param array|object $meta    Meta to set.
	 */
	public function update_meta( int $post_id, array|object $meta ): void {
		// Update meta if provided (accept object or array).
		if ( ! empty( $meta ) && ( is_array( $meta ) || is_object( $meta ) ) ) {
			$meta_array = (array) $meta;
			foreach ( $meta_array as $meta_key => $meta_value ) {
				update_post_meta( $post_id, sanitize_text_field( (string) $meta_key ), $meta_value );
			}
		}
	}

	/**
	 * Updates post terms (taxonomies) based on provided input.
	 *
	 * Accepts array or object; supports term IDs, slugs, names, or objects
	 * with id/term_id, slug, name. Creates terms if they do not exist.
	 *
	 * @param int          $post_id Post ID to update terms for.
	 * @param array|object $terms   Terms to set, keyed by taxonomy.
	 */
	public function update_terms( int $post_id, array|object $terms ): void {
		// Update terms if provided (accept array/object; supports IDs, slugs, names, or objects).
		if ( ! empty( $terms ) && ( is_array( $terms ) || is_object( $terms ) ) ) {
			$terms_array = (array) $terms;

			foreach ( $terms_array as $raw_tax => $term_items ) {
				$tax = sanitize_key( (string) $raw_tax );

				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}

				$items    = is_array( $term_items ) ? $term_items : (array) $term_items;
				$term_ids = array();

				foreach ( $items as $item ) {
					$term_id   = 0;
					$term_name = '';
					$term_slug = '';

					if ( is_numeric( $item ) ) {
						$term_id = (int) $item;
					} elseif ( is_string( $item ) ) {
						$term_name = trim( wp_strip_all_tags( $item ) );
						$term_slug = sanitize_title( $term_name );
					} elseif ( is_array( $item ) || is_object( $item ) ) {
						$it = (array) $item;
						if ( isset( $it['term_id'] ) ) {
							$term_id = (int) $it['term_id'];
						} elseif ( isset( $it['id'] ) ) {
							$term_id = (int) $it['id'];
						}
						if ( ! $term_id ) {
							$term_slug = isset( $it['slug'] ) ? sanitize_title( (string) $it['slug'] ) : '';
							$term_name = isset( $it['name'] ) ? trim( wp_strip_all_tags( (string) $it['name'] ) ) : $term_slug;
							if ( ! $term_slug && $term_name ) {
								$term_slug = sanitize_title( $term_name );
							}
						}
					}

					// Resolve/create from slug or name if no ID yet.
					if ( ! $term_id && ( $term_slug || $term_name ) ) {
						$existing = $term_slug ? get_term_by( 'slug', $term_slug, $tax ) : false;
						if ( ! $existing && $term_name ) {
							$inserted = wp_insert_term(
								$term_name,
								$tax,
								$term_slug ? array( 'slug' => $term_slug ) : array()
							);
							if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
								$term_id = (int) $inserted['term_id'];
							}
						} elseif ( $existing && ! is_wp_error( $existing ) ) {
							$term_id = (int) $existing->term_id;
						}
					}

					if ( $term_id ) {
						$term_ids[] = $term_id;
					}
				}

				if ( ! empty( $term_ids ) ) {
					// Replace existing terms for this taxonomy.
					wp_set_post_terms( $post_id, $term_ids, $tax, false );
				}
			}
		}
	}
}
