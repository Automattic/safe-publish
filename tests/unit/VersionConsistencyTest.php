<?php
/**
 * Version consistency tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that every file carrying the plugin version agrees with the plugin
 * header, so a partial release bump cannot reach trunk.
 */
class VersionConsistencyTest extends TestCase {

	/**
	 * Reads a file relative to the repository root.
	 *
	 * @param string $file Repository-relative path.
	 */
	private function read( string $file ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		$this->assertIsString( $contents, "Could not read $file" );

		return $contents;
	}

	/**
	 * Extracts a version, failing when the pattern does not match.
	 *
	 * @param string $contents Text to search.
	 * @param string $pattern  Pattern whose first group is the version.
	 */
	private function extract( string $contents, string $pattern ): string {
		$matches = array();
		$this->assertSame(
			1,
			preg_match( $pattern, $contents, $matches ),
			"No match for $pattern"
		);

		return $matches[1];
	}

	/**
	 * Decodes a JSON file relative to the repository root.
	 *
	 * @param string $file Repository-relative path.
	 */
	private function read_json( string $file ): array {
		$decoded = json_decode( $this->read( $file ), true );
		$this->assertIsArray( $decoded, "Could not decode $file" );

		return $decoded;
	}

	/**
	 * Verifies that the version matches across all files that duplicate it.
	 */
	public function test_version_is_identical_everywhere(): void {
		// ARRANGE: The plugin header is the source of truth for the version.
		$plugin   = $this->read( 'safe-publish.php' );
		$expected = $this->extract(
			$plugin,
			'/^ \* Version: (\d+\.\d+\.\d+)$/m'
		);

		// ACT: Collect the version from every other place it is duplicated.
		$lock   = $this->read_json( 'package-lock.json' );
		$actual = array(
			'constant'     => $this->extract(
				$plugin,
				"/SAFE_PUBLISH_VERSION', '(\d+\.\d+\.\d+)'/"
			),
			'mu_plugin'    => $this->extract(
				$this->read( 'mu-plugins/safe-publish-local-dev.php' ),
				'/^ \* Version: +(\d+\.\d+\.\d+)$/m'
			),
			'package_json' => $this->read_json( 'package.json' )['version'],
			'lock_root'    => $lock['version'],
			'lock_package' => $lock['packages']['']['version'],
		);

		// ASSERT: Every copy matches the plugin header.
		$this->assertSame(
			array(
				'constant'     => $expected,
				'mu_plugin'    => $expected,
				'package_json' => $expected,
				'lock_root'    => $expected,
				'lock_package' => $expected,
			),
			$actual
		);
	}
}
