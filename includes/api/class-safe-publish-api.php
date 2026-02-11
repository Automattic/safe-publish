<?php
/**
 * Safe Publish API class
 *
 * @package Safe_Publish
 */

declare( strict_types=1 );

namespace Safe_Publish\API;

use Exception;
use stdClass;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe_Publish API Class.
 */
final class Safe_Publish_API extends REST_Base {

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	const REST_BASE = 'safe-publish/v1';

	/**
	 * Diff renderer instance.
	 *
	 * @var Diff_Renderer
	 */
	private Diff_Renderer $diff_renderer;

	/**
	 * Constructor.
	 *
	 * @param Diff_Renderer|null $diff_renderer Optional. Diff renderer instance.
	 */
	public function __construct( ?Diff_Renderer $diff_renderer = null ) {
		parent::__construct();
		$this->diff_renderer = $diff_renderer ?? new Diff_Renderer();
	}

	/**
	 * Registers REST API routes.
	 */
	#[\Override]
	public function register_routes(): void {
		register_rest_route(
			self::REST_BASE,
			'diff-preview',
			array(
				'methods'  => 'POST',
				'args'     => array(
					'postId'   => array(
						'required' => true,
						'type'     => 'integer',
					),
					'postType' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'post',
					),
					'content'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'mode'     => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'split', 'inline' ),
						'default'  => 'split',
					),
					'cleanup'  => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => true,
					),
				),
				'callback' => array( $this, 'render_diff' ),
			)
		);

		register_rest_route(
			self::REST_BASE,
			'update-post',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( WP_REST_Request $request ) {
					$post_id = (int) $request->get_param( 'postId' );
					if ( ! $post_id ) {
						return new WP_Error( 'rest_missing_param', __( 'postId is required', 'safe-publish' ), array( 'status' => 400 ) );
					}

					return current_user_can( 'edit_post', $post_id );
				},
				'args'                => array(
					'postId'          => array(
						'required' => true,
						'type'     => 'integer',
					),
					'content'         => array(
						'required' => true,
						'type'     => 'string',
					),
					'title'           => array(
						'required' => false,
						'type'     => 'string',
					),
					'excerpt'         => array(
						'required' => false,
						'type'     => 'string',
					),
					'meta'            => array(
						'required' => false,
						'type'     => 'object',
					),
					'terms'           => array(
						'required' => false,
						'type'     => 'object',
					),
					'featuredMediaId' => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
				'callback'            => array( $this, 'update_post_content' ),
			)
		);
	}

	/**
	 * Updates the content of a post.
	 *
	 * @param WP_REST_Request $req REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_post_content( WP_REST_Request $req ): WP_REST_Response {
		global $safe_publish_plugin;

		$post_id           = (int) $req->get_param( 'postId' );
		$content           = $req->get_param( 'content' );
		$title             = $req->get_param( 'title' );
		$excerpt           = $req->get_param( 'excerpt' );
		$meta              = $req->get_param( 'meta' );
		$terms             = $req->get_param( 'terms' );
		$featured_media_id = (int) $req->get_param( 'featuredMediaId' );

		if ( ! $post_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Missing postId', 'safe-publish' ),
				),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => __( 'Insufficient permissions', 'safe-publish' ),
				),
				403
			);
		}

		$postarr = array( 'ID' => $post_id );

		if ( $req->has_param( 'title' ) && isset( $title ) ) {
			$postarr['post_title'] = sanitize_text_field( $title );
		}

		if ( $req->has_param( 'excerpt' ) && isset( $excerpt ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( $excerpt );
		}

		if ( isset( $content ) ) {
			// Process content to import media and fix links.
			$processed_content = $content;
			if ( ! empty( $content ) ) {
				// Extract the site URL from the external link.
				$site_url = get_option( 'safe_publish_external_site_url', '' );

				$admin_handler = $safe_publish_plugin->get_admin_handler();
				$api           = $safe_publish_plugin->get_api();

				// Check if content contains Gutenberg blocks.
				if ( $admin_handler->is_gutenberg_content( $content ) ) {
					$processed_content = $admin_handler->process_gutenberg_blocks( $content, $site_url );
				} else {
					// Fallback to traditional content processing.
					$processed_content = $api->process_and_import_media( $content, $site_url );
					// Process oEmbeds using WordPress functionality.
					$processed_content = $admin_handler->process_oembed_content( $processed_content );
				}

				// Replace external URLs with current site URLs.
				$processed_content = $admin_handler->replace_external_urls( $processed_content, $site_url );
			}

			$postarr['post_content'] = $processed_content;
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				),
				500
			);
		}

		// Import/set featured image if provided.
		if ( $req->has_param( 'featuredMediaId' ) && $featured_media_id > 0 ) {
			$api      = $safe_publish_plugin->get_api();
			$site_url = get_option( 'safe_publish_external_site_url', '' );
			if ( $api && ! empty( $site_url ) ) {
				$attachment_id = $api->import_featured_image( $featured_media_id, $site_url );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			}
		}

		// Update meta only if supplied.
		if ( $req->has_param( 'meta' ) && ! empty( $meta ) && ( is_array( $meta ) || is_object( $meta ) ) ) {
			$this->update_meta( $post_id, $meta );
		}

		// Update terms only if supplied.
		if ( $req->has_param( 'terms' ) && ! empty( $terms ) && ( is_array( $terms ) || is_object( $terms ) ) ) {
			$this->update_terms( $post_id, $terms );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'post_id' => $result,
			),
			200
		);
	}

	/**
	 * Gets authentication credentials from settings.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	private function get_auth_credentials(): array {
		// Try VIP-safe authentication first.
		$shared_secret = get_option( 'safe_publish_shared_secret', '' );

		if ( ! empty( $shared_secret ) ) {
			return array(
				'shared_secret' => $shared_secret,
			);
		}

		// Fallback to Basic auth in development environments only.
		if ( $this->is_development_environment() ) {
			$username = get_option( 'safe_publish_username', '' );
			$password = get_option( 'safe_publish_password', '' );

			if ( ! empty( $username ) && ! empty( $password ) ) {
				return array(
					'username' => $username,
					'password' => $password,
				);
			}
		}

		return array();
	}

	/**
	 * Renders the diff preview for an external post.
	 *
	 * @param WP_REST_Request $req REST request object.
	 *
	 * @return array|WP_REST_Response|WP_Error Array on success, WP_Error if post not found.
	 * @throws Exception If the external post cannot be fetched or processed.
	 */
	public function render_diff( WP_REST_Request $req ): array|WP_REST_Response {
		$result = $this->diff_renderer->render_diff(
			$req,
			array( $this, 'make_request' ),
			$this->get_auth_credentials()
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'error' => $result->get_error_message() ),
				$result->get_error_data()['status'] ?? 500
			);
		}

		return $result;
	}

	/**
	 * Safely unserializes data.
	 *
	 * Disallows classes (security) and distinguishes between invalid input
	 * and the serialized false ('b:0;').
	 *
	 * @param string $s Serialized string.
	 * @return mixed Unserialized data.
	 * @throws \InvalidArgumentException If the serialized string is invalid.
	 */
	public function safe_unserialize( string $s ): mixed {
		if ( 'b:0;' === $s ) {
			return false; // Legitimate serialized false.
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$result = @unserialize( $s, array( 'allowed_classes' => false ) );

		if ( false === $result && 'b:0;' !== $s ) {
			throw new \InvalidArgumentException( 'Invalid serialized string.' );
		}

		return $result;
	}

	/**
	 * Normalizes data for stable comparison.
	 *
	 * Sorts associative array keys, preserves order for indexed arrays,
	 * and converts objects to ['__class__' => ClassName, ...props...].
	 *
	 * @param mixed $v Value to normalize.
	 * @return mixed Normalized value.
	 */
	public function normalize( $v ): mixed {
		if ( is_array( $v ) ) {
			$is_sequential = array_keys( $v ) === range( 0, count( $v ) - 1 );
			if ( $is_sequential ) {
				return array_map( array( $this, 'normalize' ), $v );
			} else {
				$out = array();
				foreach ( $v as $k => $val ) {
					$out[ $k ] = $this->normalize( $val );
				}
				ksort( $out );

				return $out;
			}
		} elseif ( is_object( $v ) ) {
			// Only public props are visible via get_object_vars().
			$props = array();
			foreach ( get_object_vars( $v ) as $k => $val ) {
				$props[ $k ] = $this->normalize( $val );
			}
			ksort( $props );

			return array_merge( array( '__class__' => get_class( $v ) ), $props );
		} else {
			// scalars / null stay as-is.
			return $v;
		}
	}

	/**
	 * Checks if two serialized payloads represent the same data.
	 *
	 * Order of associative arrays is ignored; index order is preserved.
	 *
	 * @param string $a First serialized string.
	 * @param string $b Second serialized string.
	 * @return bool True if equal, false otherwise.
	 */
	public function serialized_equals( string $a, string $b ): bool {
		$va = $this->normalize( $this->safe_unserialize( $a ) );
		$vb = $this->normalize( $this->safe_unserialize( $b ) );

		// Compare via stable JSON encoding to account for key order (safer than serialize).
		return wp_json_encode( $va ) === wp_json_encode( $vb );
	}

	/**
	 * Produces a human-readable deep diff between two normalized values.
	 *
	 * @param mixed  $left  Left value to compare.
	 * @param mixed  $right Right value to compare.
	 * @param string $path  Optional. Current path. Default '$'.
	 * @return array Differences found between values.
	 */
	public function deep_diff( $left, $right, string $path = '$' ): array {
		if ( gettype( $left ) !== gettype( $right ) ) {
			return array(
				array(
					'path'  => $path,
					'left'  => $left,
					'right' => $right,
					'note'  => 'type mismatch',
				),
			);
		}

		if ( is_array( $left ) ) {
			$diffs    = array();
			$all_keys = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );
			// Keep order stable for readability.
			sort( $all_keys );
			foreach ( $all_keys as $k ) {
				$l_has = array_key_exists( $k, $left );
				$r_has = array_key_exists( $k, $right );
				$p     = $path . ( is_int( $k ) ? "[$k]" : "['$k']" );
				if ( ! $l_has ) {
					$diffs[] = array(
						'path'  => $p,
						'left'  => null,
						'right' => $right[ $k ],
						'note'  => 'added',
					);
				} elseif ( ! $r_has ) {
					$diffs[] = array(
						'path'  => $p,
						'left'  => $left[ $k ],
						'right' => null,
						'note'  => 'removed',
					);
				} else {
					$sub   = $this->deep_diff( $left[ $k ], $right[ $k ], $p );
					$diffs = array_merge( $diffs, $sub );
				}
			}

			return $diffs;
		}

		if ( $left !== $right ) {
			return array(
				array(
					'path'  => $path,
					'left'  => $left,
					'right' => $right,
					'note'  => 'value mismatch',
				),
			);
		}

		return array();
	}

	/**
	 * Returns whether serialized strings differ and provides the differences.
	 *
	 * @param string $a First serialized string.
	 * @param string $b Second serialized string.
	 * @return array Array with [hasDiff, diffs[]].
	 */
	public function serialized_diff( string $a, string $b ): array {
		$va    = $this->normalize( $this->safe_unserialize( $a ) );
		$vb    = $this->normalize( $this->safe_unserialize( $b ) );
		$diffs = $this->deep_diff( $va, $vb );

		return array( ! empty( $diffs ), $diffs );
	}

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
