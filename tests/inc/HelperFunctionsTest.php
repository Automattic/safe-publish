<?php
declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Helper Functions Test.
 *
 * Tests utility functions and helpers.
 */
class HelperFunctionsTest extends TestCase {

	public function test_get_private_property_helper_exists(): void {
		$this->assertTrue( function_exists( 'get_private_property' ) );
	}

	public function test_get_private_method_helper_exists(): void {
		$this->assertTrue( function_exists( 'get_private_method' ) );
	}

	public function test_set_private_property_helper_exists(): void {
		$this->assertTrue( function_exists( 'set_private_property' ) );
	}

	public function test_reflection_on_simple_class(): void {
		$test_class = new class {
			private $test_property = 'initial_value';

			public function get_property() {
				return $this->test_property;
			}
		};

		$reflector = new \ReflectionClass( $test_class );
		$property = $reflector->getProperty( 'test_property' );
		$property->setAccessible( true );

		$this->assertEquals( 'initial_value', $property->getValue( $test_class ) );
	}

	public function test_set_and_get_private_property(): void {
		$test_class = new class {
			private $test_value = 'original';

			public function getValue() {
				return $this->test_value;
			}
		};

		$class_name = get_class( $test_class );
		set_private_property( $class_name, $test_class, 'test_value', 'modified' );

		$this->assertEquals( 'modified', $test_class->getValue() );
	}

	public function test_path_parsing(): void {
		$url = 'https://example.com/wp-content/uploads/2024/01/image.jpg';
		$path = wp_parse_url( $url, PHP_URL_PATH );

		$this->assertIsString( $path );
		$this->assertStringContainsString( '/wp-content/', $path );
		$this->assertEquals( '/wp-content/uploads/2024/01/image.jpg', $path );
	}

	public function test_filename_extraction(): void {
		$url = 'https://example.com/wp-content/uploads/2024/01/test-image.jpg';
		$filename = basename( $url );

		$this->assertEquals( 'test-image.jpg', $filename );
	}

	public function test_extension_extraction(): void {
		$filename = 'test-image.jpg';
		$info = pathinfo( $filename );

		$this->assertArrayHasKey( 'extension', $info );
		$this->assertEquals( 'jpg', $info['extension'] );
		$this->assertEquals( 'test-image', $info['filename'] );
	}

	public function test_sanitize_functions_exist(): void {
		$functions = array(
			'sanitize_text_field',
			'sanitize_title',
			'sanitize_email',
			'sanitize_url',
			'esc_html',
			'esc_url',
		);

		foreach ( $functions as $function ) {
			$this->assertTrue(
				function_exists( $function ),
				"Function {$function} should exist"
			);
		}
	}

	public function test_wp_parse_url_helper(): void {
		$url = 'https://example.com:8080/path?query=value#fragment';
		$parsed = wp_parse_url( $url );

		$this->assertIsArray( $parsed );
		$this->assertEquals( 'https', $parsed['scheme'] ?? null );
		$this->assertEquals( 'example.com', $parsed['host'] ?? null );
		$this->assertEquals( 8080, $parsed['port'] ?? null );
		$this->assertEquals( '/path', $parsed['path'] ?? null );
	}

	public function test_array_manipulation(): void {
		$array = array( 'a' => 1, 'b' => 2, 'c' => 3 );

		$this->assertTrue( array_key_exists( 'a', $array ) );
		$this->assertEquals( 1, $array['a'] );
		$this->assertCount( 3, $array );

		$keys = array_keys( $array );
		$this->assertEquals( array( 'a', 'b', 'c' ), $keys );
	}

	public function test_json_encode_decode(): void {
		$data = array( 'key' => 'value', 'number' => 42 );
		$json = wp_json_encode( $data );

		$this->assertIsString( $json );

		$decoded = json_decode( $json, true );
		$this->assertEquals( $data, $decoded );
	}
}
