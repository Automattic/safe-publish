<?php
/**
 * Per-source-id mock for the WordPress single-media REST endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_Error;

/**
 * Routes wp/v2/media/{id} REST requests to a per-source-id mock body.
 *
 * Mirrors Per_Source_Id_Post_Api_Mock_Trait but for the media endpoint that
 * Media_Importer::import_featured_image() hits when resolving a source
 * featured_media ID to a downloadable source_url. Tests implement
 * mock_body_for_source_media_id() to return the body for a given media ID, or
 * null to signal "not mocked"; the trait handles filter registration, URL
 * parsing, and the 200/OK JSON wrap. Unregistered IDs yield a WP_Error so the
 * failure surfaces clearly instead of degrading to a silent default response.
 */
trait Per_Source_Id_Media_Api_Mock_Trait {

	/**
	 * Returns the REST body to mock for the given source media ID, or null when
	 * the test has not registered a mock for that ID.
	 *
	 * @param int $source_media_id Source media ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	abstract protected function mock_body_for_source_media_id(
		int $source_media_id
	): ?array;

	/**
	 * Registers the pre_http_request filter that serves the per-source-id mock.
	 */
	protected function add_per_source_id_media_api_mock(): void {
		add_filter(
			'pre_http_request',
			array( $this, 'mock_per_source_id_media_api' ),
			1,
			3
		);
	}

	/**
	 * Removes the pre_http_request filter.
	 */
	protected function remove_per_source_id_media_api_mock(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_per_source_id_media_api' ),
			1
		);
	}

	/**
	 * Intercepts single-media REST requests and serves the body registered for
	 * the source media ID embedded in the URL. Returns the prior $preempt for
	 * URLs outside the wp/v2/media/{id} pattern, and a WP_Error when no body
	 * has been registered for the matched ID.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function mock_per_source_id_media_api(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( ! preg_match( '#/wp-json/wp/v2/media/(\d+)#', $url, $matches ) ) {
			return $preempt;
		}

		$source_media_id = (int) $matches[1];
		$body            = $this->mock_body_for_source_media_id( $source_media_id );

		if ( null === $body ) {
			return new WP_Error(
				'safe_publish_test_no_media_mock_body',
				"No mock body registered for source media ID {$source_media_id}"
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
