<?php declare( strict_types=1 );
/**
 * CCP API class
 *
 * @package CCP
 */

namespace CCP\API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External Posts API Class.
 */
class CCP_API extends REST_Base {

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	const REST_BASE = 'ccp/v1';

	/**
	 * Registers REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route( self::REST_BASE, 'diff-preview', [
			'methods' => 'POST',
			'args' => [
				'postId' => [
					'required' => true,
					'type' => 'integer',
				],
				'postType' => [
					'required' => false,
					'type' => 'string',
					'default' => 'post',
				],
				'content' => [
					'required' => true,
					'type' => 'string',
				],
				'mode' => [
					'required' => false,
					'type' => 'string',
					'enum' => [ 'split', 'inline' ],
					'default' => 'split',
				],
				'cleanup' => [
					'required' => false,
					'type' => 'boolean',
					'default' => true,
				],
			],
			'callback' => [ $this, 'render_diff' ],
		] );

		register_rest_route( self::REST_BASE, 'update-post', [
			'methods' => 'POST',
			'permission_callback' => function ( \WP_REST_Request $request ) {
				$post_id = (int) $request->get_param( 'postId' );
				if ( ! $post_id ) {
					return new \WP_Error( 'rest_missing_param', __( 'postId is required', 'ccp' ), array( 'status' => 400 ) );
				}

				return current_user_can( 'edit_post', $post_id );
			},
			'args' => [
				'postId' => [
					'required' => true,
					'type' => 'integer',
				],
				'content' => [
					'required' => true,
					'type' => 'string',
				],
				'title' => [
					'required' => false,
					'type' => 'string',
				],
				'excerpt' => [
					'required' => false,
					'type' => 'string',
				],
				'meta' => [
					'required' => false,
					'type' => 'object',
				],
				'terms' => [
					'required' => false,
					'type' => 'object',
				],
				'featuredMediaId' => [
					'required' => false,
					'type' => 'integer',
				],
			],
			'callback' => [ $this, 'update_post_content' ],
		] );
	}

	/**
	 * Updates the content of a post.
	 *
	 * @param \WP_REST_Request $req REST request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function update_post_content( \WP_REST_Request $req ): \WP_REST_Response {
		global $ccp_plugin;

		$post_id = (int) $req->get_param( 'postId' );
		$content = $req->get_param( 'content' );
		$title = $req->get_param( 'title' );
		$excerpt = $req->get_param( 'excerpt' );
		$meta = $req->get_param( 'meta' );
		$terms = $req->get_param( 'terms' );
		$featured_media_id = (int) $req->get_param( 'featuredMediaId' );

		if ( ! $post_id ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error' => __( 'Missing postId', 'ccp' ),
			], 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error' => __( 'Insufficient permissions', 'ccp' ),
			], 403 );
		}

		$postarr = [ 'ID' => $post_id ];

		if ( $req->has_param( 'title' ) && isset( $title ) ) {
			$postarr['post_title'] = sanitize_text_field( $title );
		}

		if ( $req->has_param( 'excerpt' ) && isset( $excerpt ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( $excerpt );
		}

		if ( isset( $content ) ) {
			// Process content to import media and fix links
			$processed_content = $content;
			if ( ! empty( $content ) ) {
				// Extract the site URL from the external link
				$site_url = get_option( 'ccp_external_site_url', '' );

				$admin_handler = $ccp_plugin->get_admin_handler();
				$api = $ccp_plugin->get_api();

				// Check if content contains Gutenberg blocks
				if ( $admin_handler->is_gutenberg_content( $content ) ) {
					$processed_content = $admin_handler->process_gutenberg_blocks( $content, $site_url );
				} else {
					// Fallback to traditional content processing
					$processed_content = $api->process_and_import_media( $content, $site_url );
					// Process oEmbeds using WordPress functionality
					$processed_content = $admin_handler->process_oembed_content( $processed_content );
				}

				// Replace external URLs with current site URLs
				$processed_content = $admin_handler->replace_external_urls( $processed_content, $site_url );
			}

			$postarr['post_content'] = $processed_content;
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error' => $result->get_error_message(),
			], 500 );
		}

		// Import/set featured image if provided
		if ( $req->has_param( 'featuredMediaId' ) && $featured_media_id > 0 ) {
			$api = $ccp_plugin->get_api();
			$site_url = get_option( 'ccp_external_site_url', '' );
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

		return new \WP_REST_Response( [
			'success' => true,
			'post_id' => $result,
		], 200 );
	}

	/**
	 * Gets authentication credentials from settings.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	private function get_auth_credentials(): array {
		// Try VIP-safe authentication first
		$shared_secret = get_option( 'ccp_shared_secret', '' );

		if ( ! empty( $shared_secret ) ) {
			return array(
				'shared_secret' => $shared_secret,
			);
		}

		// Fallback to Basic auth in development environments only
		if ( $this->is_development_environment() ) {
			$username = get_option( 'ccp_username', '' );
			$password = get_option( 'ccp_password', '' );

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
	 * @param \WP_REST_Request $req REST request object.
	 *
	 * @return array|\WP_REST_Response
	 * @throws \WP_Error   If the local post cannot be found.
	 * @throws \Exception If the external post cannot be fetched or processed.
	 */
	public function render_diff( \WP_REST_Request $req ): array|\WP_REST_Response {
		$external_post_id = (int) $req->get_param( 'postId' );
		$post_type = (string) $req->get_param( 'postType' );
		$mode = (string) $req->get_param( 'mode' );
		$cleanup = (bool) $req->get_param( 'cleanup' );
		$site_url = get_option( 'ccp_external_site_url', '' );

		// Convert plural post types to singular for WordPress compatibility
		$post_type_mapping = array(
			'posts' => 'post',
			'pages' => 'page',
			'media' => 'media',
			'navigation' => 'navigation',
		);

		$mapped_post_type = isset( $post_type_mapping[ $post_type ] ) ? $post_type_mapping[ $post_type ] : $post_type;

		// Find local post by external post ID
		$_query = new \WP_Query( [
			'meta_key' => 'ccp_external_post_id',
			'meta_value' => $external_post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'post_type' => $mapped_post_type,
			'post_status' => [ 'draft', 'publish', 'pending' ],
			'numberposts' => 1,
		] );

		if ( ! $_query->have_posts() ) {
			return new \WP_REST_Response( [ 'error' => 'No matching post found in current site.' ], 404 );
		}

		$local_post = $_query->posts[0];

		// Requested context always edit.
		$requested_context = 'edit';

		// Build external API URL with conditional context param
		$endpoint = $post_type;
		$api_base = trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $endpoint . '/' . $external_post_id;
		$query_args = array(
			'context' => $requested_context,
			'_embed' => '1',
		);
		$external_api_url = add_query_arg( $query_args, $api_base );

		$response = $this->make_request( $external_api_url, $this->get_auth_credentials() );

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'error' => 'Failed to fetch external post.' ], 500 );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Build incoming structured pieces
		$incoming_title = isset( $data['title']['rendered'] ) ? $data['title']['rendered'] : '';
		$incoming_content = isset( $data['content']['raw'] ) ? $data['content']['raw'] : ( isset( $data['content']['rendered'] ) ? $data['content']['rendered'] : '' );
		$incoming_excerpt = isset( $data['excerpt']['rendered'] ) ? $data['excerpt']['rendered'] : '';
		$incoming_meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		// Extract terms from embedded response if available
		$incoming_terms = array();
		if ( ! empty( $data['_embedded']['wp:term'] ) && is_array( $data['_embedded']['wp:term'] ) ) {
			foreach ( $data['_embedded']['wp:term'] as $term_group ) {
				foreach ( $term_group as $term ) {
					$tax = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'term';
					if ( ! isset( $incoming_terms[ $tax ] ) ) {
						$incoming_terms[ $tax ] = array();
					}
					$incoming_terms[ $tax ][] = isset( $term['name'] ) ? $term['name'] : '';
				}
			}
		}

		// Build local (current) structured pieces to compare
		$current_title = get_the_title( $local_post->ID );
		$current_content = $local_post->post_content;
		$current_excerpt = $local_post->post_excerpt;

		$current_terms = array();
		$taxonomies = get_post_taxonomies( $local_post->ID );
		if ( is_array( $taxonomies ) ) {
			foreach ( $taxonomies as $tax ) {
				$terms = get_the_terms( $local_post->ID, $tax );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					$names = array();
					foreach ( $terms as $t ) {
						$names[] = $t->name;
					}
					$current_terms[ $tax ] = $names;
				}
			}
		}

		$current_meta = get_post_meta( $local_post->ID );

		// Optional: normalize whitespace on content only to reduce noisy diffs.
		if ( $cleanup ) {
			$current_content = $this->normalize_for_diff( $current_content );
			$incoming_content = $this->normalize_for_diff( $incoming_content );

			// Titles/excerpts are simpler, keep light normalization.
			$light = static function ( $text ) {
				$text = str_replace( [ "\r\n", "\r" ], "\n", (string) $text );
				$text = preg_replace( "/[ \t]+/", ' ', $text );
				$text = preg_replace( "/\n{3,}/", "\n\n", $text );

				return trim( (string) $text );
			};
			$current_title = $light( $current_title );
			$incoming_title = $light( $incoming_title );
			$current_excerpt = $light( $current_excerpt );
			$incoming_excerpt = $light( $incoming_excerpt );
		}

		// Ensure diff renderer classes are loaded
		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table' ) ) {
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}

		$renderer = ( 'inline' === $mode ) ? new \WP_Text_Diff_Renderer_Inline() : new \WP_Text_Diff_Renderer_Table();

		// Generate content-only diff HTML
		$content_diff = wp_text_diff(
			$current_content,
			$incoming_content,
			[
				'title_left' => __( 'Current Content', 'ccp' ),
				'title_right' => __( 'Incoming Content', 'ccp' ),
				'wrap' => '<div class="incoming-content-diff">%s</div>',
				'show_split_view' => ( 'split' === $mode ),
				'renderer' => $renderer,
			]
		);

		if ( '' === $content_diff ) {
			$content_diff = '<div class="incoming-diff no-changes"><p>' . esc_html__( 'No content changes detected.', 'ccp' ) . '</p></div>';
		}

		// --- Non-content diffs (title, excerpt, taxonomies, meta) ---
		// Helper: build plain text rep for taxonomies and meta for diffing
		$build_terms_text = function ( $terms_arr ) {
			if ( empty( $terms_arr ) || ! is_array( $terms_arr ) ) {
				return '';
			}
			$lines = array();
			foreach ( $terms_arr as $tax => $names ) {
				$lines[] = $tax . ': ' . implode( ', ', (array) $names );
			}

			return implode( "\n", $lines );
		};

		$build_meta_text = function ( $meta_arr ) {
			if ( empty( $meta_arr ) || ! is_array( $meta_arr ) ) {
				return '';
			}
			$lines = array();
			foreach ( $meta_arr as $k => $v ) {
				// Skip protected meta (leading underscore) and plugin internal meta (ccp_ prefix)
				if ( 0 === strpos( $k, '_' ) || 0 === strpos( $k, 'ccp_' ) ) {
					continue;
				}
				$val = is_array( $v ) ? ( isset( $v[0] ) ? $v[0] : wp_json_encode( $v ) ) : $v;
				if ( is_array( $val ) || is_object( $val ) ) {
					$val = wp_json_encode( $val );
				}
				$lines[] = $k . ': ' . (string) $val;
			}
			return implode( "\n", $lines );
		};

		// Title diff
		$title_diff = wp_text_diff(
			$current_title,
			$incoming_title,
			[
				'title_left' => __( 'Current Title', 'ccp' ),
				'title_right' => __( 'Incoming Title', 'ccp' ),
				'wrap' => '<div class="incoming-title-diff">%s</div>',
				'renderer' => $renderer,
			]
		);
		if ( '' === $title_diff ) {
			$title_diff = '<div class="incoming-diff no-changes"><p>' . esc_html__( 'No title changes detected.', 'ccp' ) . '</p></div>';
		}

		// Normalize excerpts so a simple wrapping <p>...</p> does not create a false diff.
		$prepare_excerpt_for_diff = static function ( $excerpt ) {
			$excerpt = trim( (string) $excerpt );

			// If entire excerpt is wrapped in a single <p>...</p>, strip that outer pair only.
			if ( preg_match( '#^<p>(.*)</p>$#si', $excerpt, $m ) ) {
				// Ensure inner content does not itself contain another top-level <p> (simple heuristic).
				$excerpt_inner = $m[1];
				$excerpt = $excerpt_inner;
			}

			// Normalize whitespace: collapse internal newlines/spaces to single spaces.
			$excerpt = str_replace( [ "\r\n", "\r" ], "\n", $excerpt );
			$excerpt = preg_replace( '/\s+/', ' ', $excerpt );

			// Trim again.
			return trim( $excerpt );
		};

		$current_excerpt_for_diff = $prepare_excerpt_for_diff( $current_excerpt );
		$incoming_excerpt_for_diff = $prepare_excerpt_for_diff( $incoming_excerpt );

		// Excerpt diff (uses normalized versions to avoid false positives from wrapping <p>)
		$excerpt_diff = wp_text_diff(
			$current_excerpt_for_diff,
			$incoming_excerpt_for_diff,
			[
				'title_left' => __( 'Current Excerpt', 'ccp' ),
				'title_right' => __( 'Incoming Excerpt', 'ccp' ),
				'wrap' => '<div class="incoming-excerpt-diff">%s</div>',
				'renderer' => $renderer,
			]
		);
		if ( '' === $excerpt_diff ) {
			$excerpt_diff = '<div class="incoming-diff no-changes"><p>' . esc_html__( 'No excerpt changes detected.', 'ccp' ) . '</p></div>';
		}

		// Taxonomies diff (text representation)
		$current_terms_text = $build_terms_text( $current_terms );
		$incoming_terms_text = $build_terms_text( $incoming_terms );
		$tax_diff = wp_text_diff(
			$current_terms_text,
			$incoming_terms_text,
			[
				'title_left' => __( 'Current Taxonomies', 'ccp' ),
				'title_right' => __( 'Incoming Taxonomies', 'ccp' ),
				'wrap' => '<div class="incoming-taxonomies-diff">%s</div>',
				'renderer' => $renderer,
			]
		);
		if ( '' === $tax_diff ) {
			$tax_diff = '<div class="incoming-diff no-changes"><p>' . esc_html__( 'No taxonomy changes detected.', 'ccp' ) . '</p></div>';
		}

		// Meta diff (text representation)
		$current_meta_text = $build_meta_text( $current_meta );
		$incoming_meta_text = $build_meta_text( $incoming_meta );
		$meta_diff = wp_text_diff(
			$current_meta_text,
			$incoming_meta_text,
			[
				'title_left' => __( 'Current Meta', 'ccp' ),
				'title_right' => __( 'Incoming Meta', 'ccp' ),
				'wrap' => '<div class="incoming-meta-diff">%s</div>',
				'renderer' => $renderer,
			]
		);
		if ( '' === $meta_diff ) {
			$meta_diff = '<div class="incoming-diff no-changes"><p>' . esc_html__( 'No meta changes detected.', 'ccp' ) . '</p></div>';
		}

		// Featured media diff (with previews)
		$incoming_featured_id = isset( $data['featured_media'] ) ? absint( $data['featured_media'] ) : 0;
		$incoming_featured_url = '';
		if ( $incoming_featured_id && ! empty( $site_url ) ) {
			$media_api_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/media/' . $incoming_featured_id;
			$media_response = $this->make_request( $media_api_url, $this->get_auth_credentials() );
			if ( ! is_wp_error( $media_response ) ) {
				$media_body = wp_remote_retrieve_body( $media_response );
				$media_json = json_decode( $media_body, true );
				if ( is_array( $media_json ) && ! empty( $media_json['source_url'] ) ) {
					$incoming_featured_url = (string) $media_json['source_url'];
				}
			}
		}

		$current_featured_id = get_post_thumbnail_id( $local_post->ID );
		$current_featured_url = $current_featured_id ? wp_get_attachment_image_url( $current_featured_id, 'full' ) : '';

		$current_featured_text = $current_featured_url ? basename( (string) ( wp_parse_url( $current_featured_url, PHP_URL_PATH ) ?? $current_featured_url ) ) : 'None';
		$incoming_featured_text = $incoming_featured_url ? basename( (string) ( wp_parse_url( $incoming_featured_url, PHP_URL_PATH ) ?? $incoming_featured_url ) ) : 'None';

		$featured_media_diff = wp_text_diff(
			$current_featured_text,
			$incoming_featured_text,
			[
				'title_left' => __( 'Current Featured Image', 'ccp' ),
				'title_right' => __( 'Incoming Featured Image', 'ccp' ),
				'wrap' => '<div class="incoming-featured-media-diff">%s</div>',
				'renderer' => $renderer,
			]
		);
		if ( '' === $featured_media_diff ) {
			$featured_media_diff = '<div class="incoming-diff no-changes"><p>' . esc_html__( 'No featured image changes detected.', 'ccp' ) . '</p></div>';
		}

		$featured_media_preview = sprintf(
			'<div class="incoming-featured-media-preview" style="display:flex;gap:16px;align-items:flex-start;margin-top:8px;">
                <div style="flex:1;">
                    <strong>%1$s</strong>
                    <div style="margin-top:8px;">%2$s</div>
                </div>
                <div style="flex:1;">
                    <strong>%3$s</strong>
                    <div style="margin-top:8px;">%4$s</div>
                </div>
            </div>',
			esc_html__( 'Current', 'ccp' ),
			$current_featured_url ? '<img alt="" src="' . esc_url( $current_featured_url ) . '" style="max-width:100%;height:auto;" />' : '<em>' . esc_html__( 'None', 'ccp' ) . '</em>',
			esc_html__( 'Incoming', 'ccp' ),
			$incoming_featured_url ? '<img alt="" src="' . esc_url( $incoming_featured_url ) . '" style="max-width:100%;height:auto;" />' : '<em>' . esc_html__( 'None', 'ccp' ) . '</em>'
		);

		$featured_media_html = $featured_media_diff . $featured_media_preview;

		// Build rendered (processed) previews of current & incoming content.
		// These are separate from the diff so editors can see the final render.
		$current_rendered = $current_content;
		$incoming_rendered = $incoming_content;

		// Render blocks if present
		if ( function_exists( 'has_blocks' ) && function_exists( 'do_blocks' ) ) {
			if ( has_blocks( $current_rendered ) ) {
				$current_rendered = do_blocks( $current_rendered );
			}
			if ( has_blocks( $incoming_rendered ) ) {
				$incoming_rendered = do_blocks( $incoming_rendered );
			}
		}

		// Apply standard content filters (shortcodes, embeds, formatting)
		if ( function_exists( 'apply_filters' ) ) {
			$current_rendered = apply_filters( 'the_content', $current_rendered );
			$incoming_rendered = apply_filters( 'the_content', $incoming_rendered );
		}

		$block_diffs = [];
		if ( function_exists( 'parse_blocks' ) && function_exists( 'render_block' ) ) {

			$normalize_block_html = static function ( string $html ): string {
				// Remove leading/trailing whitespace
				$html = trim( $html );

				// Remove lazy-loading & decoding attrs that WP may add automatically
				$html = preg_replace( '/\sloading=("|\')lazy\1/i', '', $html );
				$html = preg_replace( '/\sdecoding=("|\')async\1/i', '', $html );
				$html = preg_replace( '/\sfetchpriority=("|\')high\1/i', '', $html );

				// Collapse multiple spaces / newlines
				$html = preg_replace( '/\s+/', ' ', $html );

				// Normalize self-closing tags spacing
				$html = preg_replace( '/\s+\/>/', '/>', $html );

				// Normalize wp-image-* numeric class volatility (retain class marker)
				$html = preg_replace( '/wp-image-\d+/', 'wp-image-XXX', $html );

				// Trim again
				return trim( $html );
			};

			$current_blocks = parse_blocks( $current_content ?? '' );
			$incoming_blocks = parse_blocks( $incoming_content ?? '' );
			$max = max( count( $current_blocks ), count( $incoming_blocks ) );

			for ( $i = 0; $i < $max; $i++ ) {
				$cur = $current_blocks[ $i ] ?? null;
				$inc = $incoming_blocks[ $i ] ?? null;

				if ( ! $cur && ! $inc ) {
					continue;
				}

				$cur_name = $cur['blockName'] ?? null;
				$inc_name = $inc['blockName'] ?? null;

				$cur_rendered = $cur ? render_block( $cur ) : '';
				$inc_rendered = $inc ? render_block( $inc ) : '';

				$norm_cur = $cur ? $normalize_block_html( wp_kses_post( $cur_rendered ) ) : '';
				$norm_inc = $inc ? $normalize_block_html( wp_kses_post( $inc_rendered ) ) : '';

				$status = 'unchanged';
				if ( $cur && ! $inc ) {
					$status = 'removed';
				} elseif ( ! $cur && $inc ) {
					$status = 'added';
				} elseif ( $cur_name !== $inc_name ) {
					$status = 'modified';
				} elseif ( $cur && $inc && $norm_cur !== $norm_inc ) {
					$status = 'modified';
				}

				$block_diffs[] = [
					'index' => $i,
					'status' => $status,
					'current' => $cur ? [
						'name' => $cur_name,
						'attrs' => $cur['attrs'] ?? new \stdClass(),
						'rendered' => wp_kses_post( $cur_rendered ),
						'normalized' => $norm_cur,
					] : null,
					'incoming' => $inc ? [
						'name' => $inc_name,
						'attrs' => $inc['attrs'] ?? new \stdClass(),
						'rendered' => wp_kses_post( $inc_rendered ),
						'normalized' => $norm_inc,
					] : null,
				];
			}
		}

		// Prepare structured response: content diff + structured incoming/current metadata + non-content diffs
		return [
			'contentDiffHtml' => $content_diff,
			'renderedContentDiffHtml' => $rendered_content_diff ?? null,
			'blockDiffs' => $block_diffs,
			'nonContentDiffs' => [
				'title' => $title_diff ?? null,
				'excerpt' => $excerpt_diff ?? null,
				'taxonomies' => $tax_diff ?? null,
				'meta' => $meta_diff ?? null,
				'featuredMedia' => $featured_media_html ?? null,
			],
			'incoming' => [
				'title' => $incoming_title ?? null,
				'excerpt' => $incoming_excerpt ?? null,
				'meta' => $incoming_meta ?? null,
				'terms' => $incoming_terms ?? null,
			],
			'current' => [
				'title' => $current_title ?? null,
				'excerpt' => $current_excerpt ?? null,
				'meta' => $current_meta ?? null,
				'terms' => $current_terms ?? null,
			],
			'incomingRenderedHtml' => $incoming_rendered ?? null,
			'currentRenderedHtml' => $current_rendered ?? null,
			'localPostId' => $local_post->ID,
		];
	}

	/**
	 * Safely unserializes data.
	 *
	 * Disallows classes (security) and distinguishes between invalid input
	 * and the serialized false ('b:0;').
	 *
	 * @param string $s Serialized string.
	 * @return mixed Unserialized data.
	 */
	public function safe_unserialize( string $s ): mixed {
		if ( 'b:0;' === $s ) {
			return false; // legitimate serialized false
		}

		// Suppress warnings and detect failure reliably
		$prev = set_error_handler( function (): void {
			/* swallow unserialize warnings */
		} );
		$result = unserialize( $s, [ 'allowed_classes' => false ] );
		restore_error_handler();

					if ( false === $result && 'b:0;' !== $s ) {
			throw new \InvalidArgumentException( 'Invalid serialized string.' );
		}		return $result;
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
				return array_map( [ $this, 'normalize' ], $v );
			} else {
				$out = [];
				foreach ( $v as $k => $val ) {
					$out[ $k ] = $this->normalize( $val );
				}
				ksort( $out );

				return $out;
			}
		} elseif ( is_object( $v ) ) {
			// Only public props are visible via get_object_vars()
			$props = [];
			foreach ( get_object_vars( $v ) as $k => $val ) {
				$props[ $k ] = $this->normalize( $val );
			}
			ksort( $props );

			return array_merge( [ '__class__' => get_class( $v ) ], $props );
		} else {
			// scalars / null stay as-is
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

		// Compare via stable JSON encoding to account for key order (safer than serialize)
		return wp_json_encode( $va ) === wp_json_encode( $vb );
	}

	/**
	 * Produces a human-readable deep diff between two normalized values.
	 *
	 * Each difference item has path, left, and right keys.
	 *
	 * @param mixed  $left  Left value to compare.
	 * @param mixed  $right Right value to compare.
	 * @param string $path  Optional. Current path. Default '$'.
	 * @return array Differences found between values.
	 */
	public function deep_diff( $left, $right, string $path = '$' ): array {
		if ( gettype( $left ) !== gettype( $right ) ) {
			return [
				[
					'path' => $path,
					'left' => $left,
					'right' => $right,
					'note' => 'type mismatch',
				],
			];
		}

		if ( is_array( $left ) ) {
			$diffs = [];
			$allKeys = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );
			// Keep order stable for readability
			sort( $allKeys );
			foreach ( $allKeys as $k ) {
				$lHas = array_key_exists( $k, $left );
				$rHas = array_key_exists( $k, $right );
				$p = $path . ( is_int( $k ) ? "[$k]" : "['$k']" );
				if ( ! $lHas ) {
					$diffs[] = [
						'path' => $p,
						'left' => null,
						'right' => $right[ $k ],
						'note' => 'added',
					];
				} elseif ( ! $rHas ) {
					$diffs[] = [
						'path' => $p,
						'left' => $left[ $k ],
						'right' => null,
						'note' => 'removed',
					];
				} else {
					$sub = $this->deep_diff( $left[ $k ], $right[ $k ], $p );
					$diffs = array_merge( $diffs, $sub );
				}
			}

			return $diffs;
		}

		if ( $left !== $right ) {
			return [
				[
					'path' => $path,
					'left' => $left,
					'right' => $right,
					'note' => 'value mismatch',
				],
			];
		}

		return [];
	}

	/**
	 * Returns whether serialized strings differ and provides the differences.
	 *
	 * @param string $a First serialized string.
	 * @param string $b Second serialized string.
	 * @return array Array with [hasDiff, diffs[]].
	 */
	public function serialized_diff( string $a, string $b ): array {
		$va = $this->normalize( $this->safe_unserialize( $a ) );
		$vb = $this->normalize( $this->safe_unserialize( $b ) );
		$diffs = $this->deep_diff( $va, $vb );

		return [ ! empty( $diffs ), $diffs ];
	}

	/**
	 * Normalizes HTML/content for wp_text_diff to reduce noise.
	 *
	 * Canonicalizes Gutenberg blocks via parse_blocks/serialize_blocks,
	 * ensures block comments and tags break onto their own lines,
	 * and collapses excessive whitespace without altering content meaning.
	 *
	 * This runs only for diff visualization and does not affect saved content.
	 *
	 * @param string $html Raw content HTML.
	 * @return string Normalized content suitable for line-based diffing.
	 */
	private function normalize_for_diff( string $html ): string {
		// Standardize newlines early.
		$html = str_replace( [ "\r\n", "\r" ], "\n", $html );

		// Canonicalize Gutenberg block formatting if present.
		if ( false !== strpos( $html, '<!-- wp:' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = \parse_blocks( $html );
			if ( is_array( $blocks ) && ! empty( $blocks ) ) {
				$html = \serialize_blocks( $blocks );
			}
		}

		// Insert consistent line breaks to help the line-based diff:
		$html = $this->add_line_breaks_for_diff( $html );

		// Normalize trivial whitespace:
		// - collapse runs of spaces/tabs
		// - collapse many blank lines to max 1 blank line
		$html = preg_replace( "/[ \t]+/", ' ', $html );
		$html = preg_replace( "/\n{3,}/", "\n\n", $html );

		// Trim edges for diff neatness.
		return trim( $html );
	}

	/**
	 * Adds predictable line breaks to improve alignment in diff.
	 *
	 * Puts Gutenberg block comments on their own lines,
	 * breaks between HTML tags (`><` -> `>\n<`),
	 * and normalizes self-closing spacing.
	 *
	 * @param string $html HTML content.
	 * @return string HTML with line breaks added.
	 */
	private function add_line_breaks_for_diff( string $html ): string {
		// Ensure each block comment is on its own line.
		$html = preg_replace( '/\s*(<!--\s*\/?wp:[^>]+-->)\s*/', "\n$1\n", $html );

		// Break between adjacent tags.
		$html = preg_replace( '/>\s*</', ">\n<", $html );

		// Normalize self-closing tag spacing.
		$html = preg_replace( '/\s+\/>/', '/>', $html );

		// Remove duplicate empty lines introduced by inserts.
		$html = preg_replace( "/\n{3,}/", "\n\n", $html );

		return $html;
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
		// Update meta if provided (accept object or array)
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
		// Update terms if provided (accept array/object; supports IDs, slugs, names, or objects)
		if ( ! empty( $terms ) && ( is_array( $terms ) || is_object( $terms ) ) ) {
			$terms_array = (array) $terms;

			foreach ( $terms_array as $raw_tax => $term_items ) {
				$tax = sanitize_key( (string) $raw_tax );

				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}

				$items = is_array( $term_items ) ? $term_items : (array) $term_items;
				$term_ids = [];

				foreach ( $items as $item ) {
					$term_id = 0;
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
								$term_slug ? [ 'slug' => $term_slug ] : []
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
