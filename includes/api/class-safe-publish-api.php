<?php
/**
 * Safe Publish API class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use WP_Error;
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
	 * Meta terms manager instance.
	 *
	 * @var Meta_Terms_Manager
	 */
	private Meta_Terms_Manager $meta_terms_manager;

	/**
	 * Media Importer instance.
	 *
	 * @var Media_Importer|null
	 */
	private ?Media_Importer $media_importer;

	/**
	 * Content Processor instance.
	 *
	 * @var Content_Processor|null
	 */
	private ?Content_Processor $content_processor;

	/**
	 * Constructor.
	 *
	 * @param Diff_Renderer|null      $diff_renderer      Optional. Diff Renderer instance.
	 * @param Meta_Terms_Manager|null $meta_terms_manager Optional. Meta Terms Manager instance.
	 * @param Content_Processor|null  $content_processor  Optional. Content Processor instance.
	 * @param Media_Importer|null     $media_importer     Optional. Media Importer instance.
	 */
	public function __construct(
		?Diff_Renderer $diff_renderer = null,
		?Meta_Terms_Manager $meta_terms_manager = null,
		?Content_Processor $content_processor = null,
		?Media_Importer $media_importer = null
	) {
		parent::__construct();
		$this->diff_renderer      = $diff_renderer ?? new Diff_Renderer();
		$this->meta_terms_manager = $meta_terms_manager ?? new Meta_Terms_Manager();
		$this->content_processor  = $content_processor;
		$this->media_importer     = $media_importer;
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
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_post_permission' ),
				'args'                => array(
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
				'callback'            => array( $this, 'render_diff' ),
			)
		);

		register_rest_route(
			self::REST_BASE,
			'update-post',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'check_edit_post_permission' ),
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
	 * Checks if the current user can edit the specified post.
	 *
	 * @param WP_REST_Request $request REST request object.
	 *
	 * @return bool|WP_Error Whether the current user can edit the post,
	 *                       WP_Error if post ID is invalid or post not found.
	 */
	public function check_edit_post_permission( WP_REST_Request $request ): bool|WP_Error {
		$post_id = (int) $request->get_param( 'postId' );

		if ( $post_id < 1 ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Invalid post ID. Must be a positive integer.', 'safe-publish' ),
				array( 'status' => 400 )
			);
		}

		if ( null === get_post( $post_id ) ) {
			// Return 404 for users with enough capabilities.
			if ( current_user_can( 'edit_others_posts' ) ) {
				return new WP_Error(
					'rest_post_not_found',
					__( 'Post not found.', 'safe-publish' ),
					array( 'status' => 404 )
				);
			}

			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Updates the content of a post.
	 *
	 * @param WP_REST_Request $req REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_post_content( WP_REST_Request $req ): WP_REST_Response {
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
			$postarr['post_content'] = $this->process_content( $content );
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
			$this->import_and_set_featured_image( $post_id, $featured_media_id );
		}

		// Update meta only if supplied.
		if ( $req->has_param( 'meta' ) && ! empty( $meta ) && ( is_array( $meta ) || is_object( $meta ) ) ) {
			$this->meta_terms_manager->update_meta( $post_id, $meta );
		}

		// Update terms only if supplied.
		if ( $req->has_param( 'terms' ) && ! empty( $terms ) && ( is_array( $terms ) || is_object( $terms ) ) ) {
			$this->meta_terms_manager->update_terms( $post_id, $terms );
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
	 * Imports and sets featured image for a post.
	 *
	 * @param int $post_id           Post ID to set featured image for.
	 * @param int $featured_media_id External featured media ID to import.
	 */
	private function import_and_set_featured_image( int $post_id, int $featured_media_id ): void {
		$site_url = get_option( Options::OPTION_EXTERNAL_SITE_URL, '' );

		if ( null === $this->media_importer || empty( $site_url ) ) {
			return;
		}

		$attachment_id = $this->media_importer->import_featured_image(
			$featured_media_id,
			$site_url
		);

		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/**
	 * Processes post content by importing media and fixing links.
	 *
	 * @param string $content Raw post content.
	 *
	 * @return string Processed content.
	 */
	private function process_content( string $content ): string {
		if ( empty( $content ) || null === $this->content_processor ) {
			return $content;
		}

		$site_url = get_option( Options::OPTION_EXTERNAL_SITE_URL, '' );

		return $this->content_processor->process_content( $content, $site_url );
	}

	/**
	 * Renders the diff preview for an external post.
	 *
	 * @param WP_REST_Request $req REST request object.
	 *
	 * @return array|WP_REST_Response Array on success, WP_REST_Response with error on failure.
	 */
	public function render_diff( WP_REST_Request $req ): array|WP_REST_Response {
		$result = $this->diff_renderer->render_diff(
			$req,
			array( $this, 'make_request' ),
			Auth_Credential_Provider::get_credentials()
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'error' => $result->get_error_message() ),
				$result->get_error_data()['status'] ?? 500
			);
		}

		return $result;
	}
}
