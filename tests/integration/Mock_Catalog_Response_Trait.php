<?php
/**
 * Shared HTTP mock for the source catalog endpoint in integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Mock Catalog Response Trait.
 *
 * Points the connected-site option at a mock source and intercepts the
 * outbound catalog request with an envelope set per test, so consumers can
 * assert on the destination's normalized fetch_posts() output.
 */
trait Mock_Catalog_Response_Trait {

	/**
	 * Source URL the mocked catalog endpoint is rooted at.
	 *
	 * @var string
	 */
	protected string $source_site_url = 'https://source.example.com';

	/**
	 * Body returned by the mocked catalog response, set per test.
	 *
	 * @var string
	 */
	private string $mock_body = '';

	/**
	 * Points the connected site at the mock and registers the HTTP filter.
	 */
	protected function register_catalog_mock(): void {
		update_option( Options::OPTION_CONNECTED_SITE_URL, $this->source_site_url );
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5, 3 );
	}

	/**
	 * Removes the HTTP filter and the connected-URL option.
	 */
	protected function unregister_catalog_mock(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
	}

	/**
	 * Returns the mocked catalog response.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value (unused).
	 * @param array                $args    HTTP request arguments (unused).
	 * @param string               $url     Request URL (unused).
	 * @return array Mock HTTP response.
	 */
	public function intercept_http_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): array {
		unset( $preempt, $args, $url );

		return array(
			'headers'  => array(),
			'body'     => $this->mock_body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Builds the mocked envelope body around a single item.
	 *
	 * @param array $item Listing item payload.
	 * @return string Encoded envelope.
	 */
	protected function envelope_with( array $item ): string {
		return (string) wp_json_encode(
			array(
				'items'    => array( $item ),
				'has_more' => false,
			)
		);
	}
}
