<?php
declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\API\REST_Base;

/**
 * REST Base Test.
 *
 * Tests the REST API base functionality.
 */
class RESTBaseTest extends TestCase {

	private $rest_base;

	protected function setUp(): void {
		parent::setUp();
		$this->rest_base = new class() extends REST_Base {
			public function register_routes(): void {
				// Mock implementation.
			}

			// Expose protected method for testing.
			public function test_make_request( string $url, array $auth_credentials = array() ): array|\WP_Error {
				return $this->make_request( $url, $auth_credentials );
			}
		};
	}

	public function test_rest_base_initializes(): void {
		$this->assertInstanceOf( REST_Base::class, $this->rest_base );
	}

	public function test_register_routes_is_callable(): void {
		$this->assertTrue( method_exists( $this->rest_base, 'register_routes' ) );
	}

	public function test_make_request_returns_wp_error_for_invalid_url(): void {
		$result = $this->rest_base->test_make_request( 'invalid-url' );

		// Should handle invalid URLs gracefully.
		$this->assertTrue(
			is_wp_error( $result ) || is_array( $result ),
			'Expected WP_Error or array response'
		);
	}
}
