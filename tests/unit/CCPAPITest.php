<?php
/**
 * CCP API Test.
 *
 * @package Compliant_Content_Publisher
 */

declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\API\CCP_API;

/**
 * CCP API Test.
 *
 * Tests the REST API endpoints and functionality.
 */
class CCPAPITest extends TestCase {

	/**
	 * @var CCP_API CCP API instance for testing.
	 */
	private CCP_API $api;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->api = new CCP_API();
	}

	/**
	 * Verifies that the CCP API initializes correctly.
	 */
	public function test_api_initializes(): void {
		$this->assertInstanceOf( CCP_API::class, $this->api );
	}

	/**
	 * Verifies that safe_unserialize handles boolean false correctly.
	 */
	public function test_safe_unserialize_handles_false(): void {
		$serialized = 'b:0;';
		$result     = $this->api->safe_unserialize( $serialized );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that safe_unserialize handles strings correctly.
	 */
	public function test_safe_unserialize_handles_string(): void {
		$serialized = 's:11:"test string";';
		$result     = $this->api->safe_unserialize( $serialized );

		$this->assertEquals( 'test string', $result );
	}

	/**
	 * Verifies that safe_unserialize handles arrays correctly.
	 */
	public function test_safe_unserialize_handles_array(): void {
		$data       = array( 'key' => 'value' );
		$serialized = 'a:1:{s:3:"key";s:5:"value";}';
		$result     = $this->api->safe_unserialize( $serialized );

		$this->assertEquals( $data, $result );
	}

	/**
	 * Verifies that safe_unserialize throws an exception for invalid data.
	 */
	public function test_safe_unserialize_throws_on_invalid_data(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->api->safe_unserialize( 'invalid serialized data' );
	}

	/**
	 * Verifies that normalize handles scalar values correctly.
	 */
	public function test_normalize_handles_scalar_values(): void {
		$this->assertEquals( 42, $this->api->normalize( 42 ) );
		$this->assertEquals( 'string', $this->api->normalize( 'string' ) );
		$this->assertEquals( true, $this->api->normalize( true ) );
		$this->assertNull( $this->api->normalize( null ) );
	}

	/**
	 * Verifies that normalize handles sequential arrays correctly.
	 */
	public function test_normalize_handles_sequential_arrays(): void {
		$array  = array( 1, 2, 3 );
		$result = $this->api->normalize( $array );

		$this->assertEquals( $array, $result );
	}

	/**
	 * Verifies that normalize sorts associative arrays.
	 */
	public function test_normalize_sorts_associative_arrays(): void {
		$array  = array(
			'z' => 1,
			'a' => 2,
			'm' => 3,
		);
		$result = $this->api->normalize( $array );

		$keys = array_keys( $result );
		$this->assertEquals( array( 'a', 'm', 'z' ), $keys );
	}

	/**
	 * Verifies that normalize handles nested arrays correctly.
	 */
	public function test_normalize_handles_nested_arrays(): void {
		$array  = array(
			'z' => array( 'nested' => 'value' ),
			'a' => array( 'other' => 'data' ),
		);
		$result = $this->api->normalize( $array );

		$keys = array_keys( $result );
		$this->assertEquals( array( 'a', 'z' ), $keys );
	}

	/**
	 * Verifies that serialized_equals returns true for identical data.
	 */
	public function test_serialized_equals_returns_true_for_same_data(): void {
		$serialized1 = 'a:1:{s:3:"key";s:5:"value";}';
		$serialized2 = 'a:1:{s:3:"key";s:5:"value";}';

		$result = $this->api->serialized_equals( $serialized1, $serialized2 );

		$this->assertTrue( $result );
	}

	/**
	 * Verifies that serialized_equals returns false for different data.
	 */
	public function test_serialized_equals_returns_false_for_different_data(): void {
		$serialized1 = 'a:1:{s:3:"key";s:6:"value1";}';
		$serialized2 = 'a:1:{s:3:"key";s:6:"value2";}';

		$result = $this->api->serialized_equals( $serialized1, $serialized2 );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that serialized_equals ignores key order.
	 */
	public function test_serialized_equals_ignores_key_order(): void {
		$serialized1 = 'a:2:{s:1:"a";i:1;s:1:"b";i:2;}';
		$serialized2 = 'a:2:{s:1:"b";i:2;s:1:"a";i:1;}';

		$result = $this->api->serialized_equals( $serialized1, $serialized2 );

		$this->assertTrue( $result );
	}

	/**
	 * Verifies that deep_diff returns empty array for identical values.
	 */
	public function test_deep_diff_returns_empty_for_identical_values(): void {
		$left  = array( 'key' => 'value' );
		$right = array( 'key' => 'value' );

		$diffs = $this->api->deep_diff( $left, $right );

		$this->assertEmpty( $diffs );
	}

	/**
	 * Verifies that deep_diff detects value changes.
	 */
	public function test_deep_diff_detects_value_changes(): void {
		$left  = array( 'key' => 'value1' );
		$right = array( 'key' => 'value2' );

		$diffs = $this->api->deep_diff( $left, $right );

		$this->assertNotEmpty( $diffs );
		$this->assertCount( 1, $diffs );
		$this->assertEquals( 'value1', $diffs[0]['left'] );
		$this->assertEquals( 'value2', $diffs[0]['right'] );
	}

	/**
	 * Verifies that deep_diff detects added keys.
	 */
	public function test_deep_diff_detects_added_keys(): void {
		$left  = array( 'key1' => 'value1' );
		$right = array(
			'key1' => 'value1',
			'key2' => 'value2',
		);

		$diffs = $this->api->deep_diff( $left, $right );

		$this->assertNotEmpty( $diffs );
		$this->assertCount( 1, $diffs );
		$this->assertEquals( 'added', $diffs[0]['note'] );
	}

	/**
	 * Verifies that deep_diff detects removed keys.
	 */
	public function test_deep_diff_detects_removed_keys(): void {
		$left  = array(
			'key1' => 'value1',
			'key2' => 'value2',
		);
		$right = array( 'key1' => 'value1' );

		$diffs = $this->api->deep_diff( $left, $right );

		$this->assertNotEmpty( $diffs );
		$this->assertCount( 1, $diffs );
		$this->assertEquals( 'removed', $diffs[0]['note'] );
	}

	/**
	 * Verifies that deep_diff detects type mismatches.
	 */
	public function test_deep_diff_detects_type_mismatch(): void {
		$left  = 'string';
		$right = array( 'key' => 'value' );

		$diffs = $this->api->deep_diff( $left, $right );

		$this->assertNotEmpty( $diffs );
		$this->assertCount( 1, $diffs );
		$this->assertEquals( 'type mismatch', $diffs[0]['note'] );
	}

	/**
	 * Verifies that serialized_diff returns has_diff flag correctly.
	 */
	public function test_serialized_diff_returns_has_diff_flag(): void {
		$serialized1 = 'a:1:{s:3:"key";s:6:"value1";}';
		$serialized2 = 'a:1:{s:3:"key";s:6:"value2";}';

		list( $has_diff, $diffs ) = $this->api->serialized_diff(
			$serialized1,
			$serialized2
		);

		$this->assertTrue( $has_diff );
		$this->assertNotEmpty( $diffs );
	}

	/**
	 * Verifies that serialized_diff returns no differences for identical data.
	 */
	public function test_serialized_diff_returns_no_diff_for_identical(): void {
		$serialized = 'a:1:{s:3:"key";s:5:"value";}';

		list( $has_diff, $diffs ) = $this->api->serialized_diff(
			$serialized,
			$serialized
		);

		$this->assertFalse( $has_diff );
		$this->assertEmpty( $diffs );
	}
}
