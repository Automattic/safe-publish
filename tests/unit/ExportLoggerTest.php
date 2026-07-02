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
}
