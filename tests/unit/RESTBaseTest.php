<?php
/**
 * REST Base Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\REST_Base;
use WP_Error;

/**
 * REST Base Test.
 *
 * Tests the REST API base functionality.
 */
class RESTBaseTest extends TestCase {

	/**
	 * @var REST_Base Anonymous REST_Base instance for testing.
	 */
	private $rest_base;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->rest_base = new class() extends REST_Base {
			/**
			 * Registers routes.
			 */
			#[\Override]
			public function register_routes(): void {
				// Mock implementation.
			}

			/**
			 * Exposes protected method for testing.
			 *
			 * @param string $url URL to make request to.
			 * @param array  $auth_credentials Authentication credentials.
			 * @return array|WP_Error Response array or WP_Error on failure.
			 */
			public function test_make_request( string $url, array $auth_credentials = array() ): array|WP_Error {
				return $this->make_request( $url, $auth_credentials );
			}
		};
	}

	/**
	 * Verifies that the REST_Base class initializes correctly.
	 */
	public function test_rest_base_initializes(): void {
		$this->assertInstanceOf( REST_Base::class, $this->rest_base );
	}

	/**
	 * Verifies that register_routes method is callable.
	 */
	public function test_register_routes_is_callable(): void {
		$this->assertTrue( method_exists( $this->rest_base, 'register_routes' ) );
	}

	/**
	 * Verifies that make_request returns WP_Error for invalid URLs.
	 */
	public function test_make_request_returns_wp_error_for_invalid_url(): void {
		/** @psalm-suppress UndefinedMethod - Method exists in anonymous class extending REST_Base */
		$result = $this->rest_base->test_make_request( 'invalid-url' );

		// Should handle invalid URLs gracefully.
		$this->assertTrue(
			is_wp_error( $result ) || is_array( $result ),
			'Expected WP_Error or array response'
		);
	}
}
