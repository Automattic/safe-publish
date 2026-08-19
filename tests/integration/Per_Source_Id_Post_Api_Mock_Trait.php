<?php
/**
 * Per-source-id mock for the WordPress single-post REST endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Source_Post_Type_Resolver;
use WP_Error;

/**
 * Routes wp/v2/{type}/{id} REST requests to a per-source-id mock body.
 *
 * Tests implement mock_body_for_source_id() to return the body for a given
 * source ID, or null to signal "not mocked"; the trait handles filter
 * registration, URL parsing, and the 200/OK JSON wrap. Unregistered IDs yield
 * a WP_Error so the failure surfaces clearly instead of degrading to a silent
 * default response.
 *
 * The URL pattern excludes /media/ so a sibling
 * Per_Source_Id_Media_Api_Mock_Trait can serve featured-media metadata
 * requests without colliding with this trait.
 *
 * Use the sibling Mock_Post_API_Trait for tests that mock a single fixed post
 * via $mock_post_overrides. This trait is for batches where the response body
 * varies by source ID.
 */
trait Per_Source_Id_Post_Api_Mock_Trait {

	/**
	 * Returns the REST body to mock for the given source post ID, or null when
	 * the test has not registered a mock for that ID.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	abstract protected function mock_body_for_source_id( int $source_id ): ?array;

	/**
	 * Registers the pre_http_request filter that serves the per-source-id mock.
	 */
	protected function add_per_source_id_post_api_mock(): void {
		Source_Post_Type_Resolver::reset_cache();
		add_filter(
			'pre_http_request',
			array( $this, 'mock_per_source_id_post_api' ),
			1,
			3
		);
	}

	/**
	 * Removes the pre_http_request filter.
	 */
	protected function remove_per_source_id_post_api_mock(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_per_source_id_post_api' ),
			1
		);
	}

	/**
	 * Intercepts single-post REST requests and serves the body registered for
	 * the source ID embedded in the URL. Returns the prior $preempt for URLs
	 * outside the wp/v2/{type}/{id} pattern and for wp/v2/media/{id}
	 * (which Per_Source_Id_Media_Api_Mock_Trait handles), and a WP_Error
	 * when no body has been registered for the matched ID.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function mock_per_source_id_post_api(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( preg_match( '#/wp-json/wp/v2/types/([a-z0-9_-]+)#', $url, $matches ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => (string) wp_json_encode(
					array( 'supports' => get_all_post_type_supports( $matches[1] ) )
				),
				'headers'  => array(),
			);
		}

		// Exclude /media/ so Per_Source_Id_Media_Api_Mock_Trait can serve
		// featured-media metadata requests without colliding here.
		if ( ! preg_match(
			'#/wp-json/wp/v2/(?!media/)[a-z0-9_-]+/(\d+)#',
			$url,
			$matches
		) ) {
			return $preempt;
		}

		$source_id = (int) $matches[1];
		$body      = $this->mock_body_for_source_id( $source_id );

		if ( null === $body ) {
			return new WP_Error(
				'safe_publish_test_no_mock_body',
				"No mock body registered for source ID {$source_id}"
			);
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}
}
