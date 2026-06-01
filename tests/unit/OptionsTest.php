<?php
/**
 * Options Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Options;

/**
 * Options Test.
 *
 * Tests option helpers for deployment-defined values.
 */
class OptionsTest extends TestCase {

	/**
	 * Resets test option overrides after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		parent::tearDown();
		reset_test_options();
	}

	/**
	 * Verifies that stored options are used when no constant is configured.
	 */
	public function test_get_value_returns_option_when_constant_is_not_configured(): void {
		// ARRANGE.
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// ACT.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, '' );

		// ASSERT.
		$this->assertSame( 'https://source.example.com', $value );
	}

	/**
	 * Verifies that the default value is used when no option or constant exists.
	 */
	public function test_get_value_returns_default_when_option_is_not_configured(): void {
		// ACT.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, 'https://default.example.com' );

		// ASSERT.
		$this->assertSame( 'https://default.example.com', $value );
	}

	/**
	 * Verifies that a constant overrides the stored option value.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_value_prefers_constant_over_option(): void {
		// ARRANGE.
		define( 'SAFE_PUBLISH_CONNECTED_SITE_URL', 'https://constant.example.com' );
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://option.example.com' );

		// ACT.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, '' );

		// ASSERT.
		$this->assertSame( 'https://constant.example.com', $value );
	}

	/**
	 * Verifies that pre-option filtering returns a configured constant value.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pre_option_value_returns_constant_when_configured(): void {
		// ARRANGE.
		define( 'SAFE_PUBLISH_SYNC_MODE', Options::SYNC_MODE_IMPORT );

		// ACT.
		$value = Options::pre_option_value( false, Options::OPTION_SYNC_MODE );

		// ASSERT.
		$this->assertSame( Options::SYNC_MODE_IMPORT, $value );
	}

	/**
	 * Verifies that pre-option filtering returns the original value when no
	 * constant is configured.
	 */
	public function test_pre_option_value_returns_original_value_without_constant(): void {
		// ACT.
		$value = Options::pre_option_value( false, Options::OPTION_SYNC_MODE );

		// ASSERT.
		$this->assertFalse( $value );
	}
}
