<?php
/**
 * Tests that the destination rejects an oversized source response with a
 * clear, size-specific error instead of a generic failure.
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
 * Oversized Response Test.
 *
 * The limit_response_size arg caps the body the transport buffers;
 * make_request then reports a size-specific WP_Error so each flow can explain
 * the failure instead of surfacing a generic invalid-response message. The
 * tests lower the cap through the safe_publish_request_args filter so a small
 * body exercises the path without allocating megabytes.
 */
class Oversized_Response_Test extends Integration_Test_Case {

	/**
	 * Source URL the mocked endpoints are rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Response cap the safe_publish_request_args filter installs for the test.
	 */
	private const TEST_CAP = 256;

	/**
	 * Body returned by the mocked response, set per test.
	 *
	 * @var string
	 */
	private string $mock_body = '';

	/**
	 * Sets the connected URL and registers the HTTP mock and cap filter.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE_SITE_URL );
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5, 3 );
		add_filter( 'safe_publish_request_args', array( $this, 'lower_response_cap' ) );
	}

	/**
	 * Removes the filters and the connected-URL option.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'safe_publish_request_args', array( $this, 'lower_response_cap' ) );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the mocked response carrying the per-test body.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
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
	 * Lowers the response cap so a small body reaches it.
	 *
	 * @param array $args Request arguments.
	 * @return array Request arguments with the cap lowered.
	 */
	public function lower_response_cap( array $args ): array {
		$args['limit_response_size'] = self::TEST_CAP;
		return $args;
	}

	/**
	 * Verifies that a catalog response at the size cap yields the size-specific
	 * error code and a message naming the limit.
	 */
	public function test_oversized_catalog_response_returns_too_large_error(): void {
		// ARRANGE: Source returns a body that reaches the cap.
		$this->mock_body = str_repeat( 'a', self::TEST_CAP + 1 );

		// ACT: Fetch the catalog page.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( self::SOURCE_SITE_URL );

		// ASSERT: Size-specific error, message names the limit.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			HTTP_Client::ERROR_RESPONSE_TOO_LARGE,
			$result->get_error_code()
		);
		$this->assertStringContainsString(
			size_format( self::TEST_CAP ),
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that a response under the cap is processed normally, so the
	 * size check does not fire on legitimate payloads.
	 */
	public function test_response_under_cap_is_processed(): void {
		// ARRANGE: A valid envelope comfortably under the cap.
		$this->mock_body = (string) wp_json_encode(
			array(
				'items'    => array(
					array(
						'id'    => 1,
						'title' => 'Under cap',
					),
				),
				'has_more' => false,
			)
		);
		$this->assertLessThan( self::TEST_CAP, strlen( $this->mock_body ) );

		// ACT: Fetch the catalog page.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( self::SOURCE_SITE_URL );

		// ASSERT: Normal envelope processing, no size error.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
	}

	/**
	 * Verifies that the import path surfaces the size-specific error instead
	 * of flattening it to the generic fresh-content failure.
	 */
	public function test_oversized_import_response_surfaces_size_error(): void {
		// ARRANGE: Source returns a body that reaches the cap.
		$this->mock_body = str_repeat( 'a', self::TEST_CAP + 1 );

		// ACT: Fetch fresh post content for import.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_fresh_post( 123, 'post' );

		// ASSERT: The size-specific code propagates, not a generic fetch error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			HTTP_Client::ERROR_RESPONSE_TOO_LARGE,
			$result->get_error_code()
		);
	}
}
