<?php
/**
 * Export Logger Test
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\Auth\Auth_Logger;

/**
 * Export Logger Test.
 */
class ExportLoggerTest extends TestCase {

	/**
	 * Verifies that the export logger uses the 'export' channel.
	 */
	public function test_export_logger_uses_export_channel(): void {
		$logger   = new Export_Logger();
		$property = get_private_property( Export_Logger::class, 'channel' );

		$this->assertSame( 'export', $property->getValue( $logger ) );
	}

	/**
	 * Verifies that parse_destination_site_url extracts the URL from a standard
	 * Safe Publish User-Agent string.
	 */
	public function test_parse_destination_site_url_extracts_url_from_user_agent(): void {
		$manager = new Permission_Manager( new Auth_Logger(), new Export_Logger() );
		$method  = get_private_method( Permission_Manager::class, 'parse_destination_site_url' );

		$result = $method->invoke( $manager, 'Safe Publish/1.2.3; https://dest.example.com' );

		$this->assertSame( 'https://dest.example.com', $result );
	}

	/**
	 * Verifies that parse_destination_site_url returns an empty string for an absent
	 * User-Agent header.
	 */
	public function test_parse_destination_site_url_returns_empty_string_for_missing_header(): void {
		$manager = new Permission_Manager( new Auth_Logger(), new Export_Logger() );
		$method  = get_private_method( Permission_Manager::class, 'parse_destination_site_url' );

		$this->assertSame( '', $method->invoke( $manager, '' ) );
	}

	/**
	 * Verifies that parse_destination_site_url returns the raw value when the
	 * User-Agent does not match the expected format.
	 */
	public function test_parse_destination_site_url_returns_raw_value_for_unknown_format(): void {
		$manager = new Permission_Manager( new Auth_Logger(), new Export_Logger() );
		$method  = get_private_method( Permission_Manager::class, 'parse_destination_site_url' );

		$result = $method->invoke( $manager, 'curl/7.88.0' );

		$this->assertSame( 'curl/7.88.0', $result );
	}
}
