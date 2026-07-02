<?php
/**
 * Source Media REST Field class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Auth\HMAC_Authenticator;
use WP_Post;
use WP_REST_Request;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the safe_publish_media REST field so the destination can bring
 * each inline image's source library metadata (alt, title, caption,
 * description) when it sideloads the image.
 *
 * Inline images are referenced by bare URL, which core REST cannot resolve to
 * an attachment; the source does it here by scanning the post content and
 * mapping each of its media URLs to the raw attachment values. Populated only
 * for HMAC-authenticated single-item requests, the same gate as the author field.
 */
class Source_Media_REST_Field {

	/**
	 * REST field name added to public post type responses.
	 *
	 * @var string
	 */
	const FIELD_NAME = 'safe_publish_media';

	/**
	 * HMAC authenticator used to gate access to the field value.
	 *
	 * @var HMAC_Authenticator
	 */
	private HMAC_Authenticator $authenticator;

	/**
	 * Constructs the Source_Media_REST_Field instance.
	 *
	 * @param HMAC_Authenticator $authenticator HMAC authenticator instance.
	 */
	public function __construct( HMAC_Authenticator $authenticator ) {
		$this->authenticator = $authenticator;
	}

	/**
	 * Registers the rest_api_init hook that adds the REST field.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_field' ) );
	}

	/**
	 * Registers the safe_publish_media field on every public, REST-exposed
	 * post type, excluding attachments (which carry no inline media of their
	 * own).
	 */
	public function register_field(): void {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		unset( $post_types['attachment'] );

		register_rest_field(
			array_values( $post_types ),
			self::FIELD_NAME,
			array(
				'get_callback' => array( $this, 'get_callback' ),
				'schema'       => null,
			)
		);
	}

	/**
	 * Returns the source URL => library metadata map for HMAC-authenticated
	 * single-item requests, and null otherwise so the field carries no data for
	 * public, cookie-authenticated, third-party, or collection consumers.
	 *
	 * @param array           $post_array Post data as built by WP_REST_Posts_Controller.
	 * @param string          $_attribute Field name (unused).
	 * @param WP_REST_Request $request    Current REST request.
	 * @return array<string, array<string, string>>|null Source URL => metadata,
	 *         or null when not HMAC-authenticated or not a single-item request.
	 */
	public function get_callback(
		array $post_array,
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		string $_attribute,
		WP_REST_Request $request
	): ?array {
		if ( ! $this->authenticator->is_authenticated() ) {
			return null;
		}

		if ( ! $this->is_single_item_request( $request ) ) {
			return null;
		}

		$post_id = isset( $post_array['id'] ) ? (int) $post_array['id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		return $this->resolve_media_metadata( (string) $post->post_content );
	}

	/**
	 * Scans content for this site's media URLs and maps each that resolves to an
	 * attachment to its raw library values. Keyed by the query-stripped URL to
	 * match the destination's lookup at sideload time.
	 *
	 * @param string $content Raw post content.
	 * @return array<string, array<string, string>> Source URL => metadata.
	 */
	private function resolve_media_metadata( string $content ): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host || '' === $content ) {
			return array();
		}

		$pattern = '#https?://' . preg_quote( $host, '#' ) . '/[^\s"\'<>()]+#i';

		if ( ! preg_match_all( $pattern, $content, $matches ) ) {
			return array();
		}

		$map = array();

		foreach ( array_unique( $matches[0] ) as $raw_url ) {
			$url = strtok( $raw_url, '?' );

			if ( false === $url || isset( $map[ $url ] ) ) {
				continue;
			}

			$attachment_id = $this->attachment_id_from_url( $url );

			if ( 0 === $attachment_id ) {
				continue;
			}

			$map[ $url ] = array(
				'alt'         => (string) get_post_meta(
					$attachment_id,
					'_wp_attachment_image_alt',
					true
				),
				'title'       => (string) get_post_field(
					'post_title',
					$attachment_id,
					'raw'
				),
				'caption'     => (string) get_post_field(
					'post_excerpt',
					$attachment_id,
					'raw'
				),
				'description' => (string) get_post_field(
					'post_content',
					$attachment_id,
					'raw'
				),
			);
		}

		return $map;
	}

	/**
	 * Resolves a URL to its attachment ID, preferring the VIP-optimized lookup,
	 * and confirms the ID belongs to an attachment.
	 *
	 * @param string $url Media URL.
	 * @return int Attachment ID, or 0 when the URL is not an attachment.
	 */
	private function attachment_id_from_url( string $url ): int {
		if ( function_exists( 'wpcom_vip_attachment_url_to_postid' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_wpcom_vip_attachment_url_to_postid
			$id = (int) wpcom_vip_attachment_url_to_postid( $url );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid
			$id = (int) attachment_url_to_postid( $url );
		}

		return 'attachment' === get_post_type( $id ) ? $id : 0;
	}

	/**
	 * Detects whether the request resolves a single post via its id route
	 * parameter, rather than a collection route carrying an id query parameter.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool True when the route bound a positive numeric id.
	 */
	private function is_single_item_request( WP_REST_Request $request ): bool {
		$request_id = $request->get_url_params()['id'] ?? null;

		return is_numeric( $request_id ) && (int) $request_id > 0;
	}
}
