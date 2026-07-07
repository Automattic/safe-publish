<?php
/**
 * Tests that fetch_fresh_post_content returns a distinct WP_Error per cause
 * instead of collapsing every failure into a single generic return.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Fetch Fresh Content Error Test.
 *
 * Drives the source single-post fetch through a mocked HTTP response so each
 * distinct failure surfaces its own error code. The invalid-URL short-circuit,
 * which needs no HTTP call, is covered by the unit suite.
 */
class Fetch_Fresh_Content_Error_Test extends Integration_Test_Case {

	/**
	 * Source URL the mocked endpoint is rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Body returned by the mocked response, set per test.
	 *
	 * @var string
	 */
	private string $mock_body = '';

	/**
	 * Sets the connected URL and registers the HTTP mock.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE_SITE_URL );
		add_filter(
			'pre_http_request',
			array( $this, 'intercept_http_request' ),
			5,
			3
		);
	}

	/**
	 * Removes the HTTP mock and the connected-URL option.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'intercept_http_request' ),
			5
		);
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the mocked 200 response carrying the per-test body.
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
	 * Verifies that a non-array response body yields the invalid-response
	 * error code rather than a bare false.
	 */
	public function test_non_array_body_returns_invalid_response_error(): void {
		// ARRANGE: The source returns a body that is not a JSON object.
		$this->mock_body = 'unexpected non-JSON body';

		// ACT: Fetch fresh content for import.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_fresh_post_content( 123, self::SOURCE_SITE_URL );

		// ASSERT: The distinct invalid-response code surfaces.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_invalid_response',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a response lacking the raw edit-context fields yields the
	 * raw-fields-missing error code rather than a bare false.
	 */
	public function test_missing_raw_fields_returns_raw_fields_error(): void {
		// ARRANGE: The source returns rendered fields but no raw ones.
		$this->mock_body = (string) wp_json_encode(
			array(
				'id'      => 123,
				'title'   => array( 'rendered' => 'Rendered Title' ),
				'content' => array( 'rendered' => '<p>Rendered content.</p>' ),
				'excerpt' => array( 'rendered' => '<p>Rendered excerpt.</p>' ),
			)
		);

		// ACT: Fetch fresh content for import.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_fresh_post_content( 123, self::SOURCE_SITE_URL );

		// ASSERT: The distinct raw-fields-missing code surfaces.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_raw_fields_missing',
			$result->get_error_code()
		);
	}
}
