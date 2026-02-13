<?php
/**
 * Safe Publish API Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\Safe_Publish_API;

/**
 * Safe Publish API Test.
 *
 * Tests the REST API endpoints and functionality.
 */
class SafePublishAPITest extends TestCase {

	/**
	 * @var Safe_Publish_API Safe Publish API instance for testing.
	 */
	private Safe_Publish_API $api;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->api = new Safe_Publish_API();
	}

	/**
	 * Verifies that the Safe Publish API initializes correctly.
	 */
	public function test_api_initializes(): void {
		$this->assertInstanceOf( Safe_Publish_API::class, $this->api );
	}
}
