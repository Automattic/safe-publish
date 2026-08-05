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
		// ARRANGE: Store a connected-site URL without defining a constant.
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// ACT: Read the connected-site URL through the option helper.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, '' );

		// ASSERT: The stored option value is returned.
		$this->assertSame( 'https://source.example.com', $value );
	}

	/**
	 * Verifies that the default value is used when no option or constant exists.
	 */
	public function test_get_value_returns_default_when_option_is_not_configured(): void {
		// ACT: Read the connected-site URL with only a default available.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, 'https://default.example.com' );

		// ASSERT: The supplied default value is returned.
		$this->assertSame( 'https://default.example.com', $value );
	}

	/**
	 * Verifies that a constant overrides the stored option value.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_value_prefers_constant_over_option(): void {
		// ARRANGE: Define a constant and a different stored option value.
		define( 'SAFE_PUBLISH_CONNECTED_SITE_URL', 'https://constant.example.com' );
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://option.example.com' );

		// ACT: Read the connected-site URL through the option helper.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, '' );

		// ASSERT: The constant value wins over the stored option.
		$this->assertSame( 'https://constant.example.com', $value );
	}

	/**
	 * Verifies that non-string constants fall back to stored option values.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_value_ignores_non_string_constant(): void {
		// ARRANGE: Define a non-string constant and a stored option fallback.
		define( 'SAFE_PUBLISH_CONNECTED_SITE_URL', array( 'https://constant.example.com' ) );
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://option.example.com' );

		// ACT: Read the connected-site URL through the option helper.
		$value = Options::get_value( Options::OPTION_CONNECTED_SITE_URL, '' );

		// ASSERT: The stored option is used and the invalid constant is reported.
		$this->assertSame( 'https://option.example.com', $value );
		$this->assertSame(
			'SAFE_PUBLISH_CONNECTED_SITE_URL',
			get_test_doing_it_wrong_calls()[0]['function_name']
		);
		$this->assertStringContainsString(
			'must be strings',
			get_test_doing_it_wrong_calls()[0]['message']
		);
	}

	/**
	 * Verifies that constant-backed options report external configuration.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_constant_configured_returns_true_for_defined_constant(): void {
		// ARRANGE: Define a Basic Auth password constant.
		define( 'SAFE_PUBLISH_BASIC_AUTH_PASSWORD', 'constant-password' );

		// ACT: Check whether the password option is externally configured.
		$is_configured = Options::is_constant_configured(
			Options::OPTION_BASIC_AUTH_PASSWORD
		);

		// ASSERT: The option reports that a constant is configured.
		$this->assertTrue( $is_configured );
	}

	/**
	 * Verifies that options without constants do not report external
	 * configuration.
	 */
	public function test_is_constant_configured_returns_false_without_constant(): void {
		// ACT: Check whether the password option is externally configured.
		$is_configured = Options::is_constant_configured(
			Options::OPTION_BASIC_AUTH_PASSWORD
		);

		// ASSERT: The option reports no configured constant.
		$this->assertFalse( $is_configured );
	}

	/**
	 * Verifies that pre-option filtering returns a configured constant value.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pre_option_value_returns_constant_when_configured(): void {
		// ARRANGE: Define a sync-mode constant.
		define( 'SAFE_PUBLISH_SYNC_MODE', Options::SYNC_MODE_IMPORT );

		// ACT: Filter the sync-mode pre-option value.
		$value = Options::pre_option_value( false, Options::OPTION_SYNC_MODE );

		// ASSERT: The constant value replaces the original pre-option value.
		$this->assertSame( Options::SYNC_MODE_IMPORT, $value );
	}

	/**
	 * Verifies that invalid sync mode constants keep the original pre-option.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pre_option_value_ignores_invalid_sync_mode_constant(): void {
		// ARRANGE: Define a sync-mode constant outside the allowed values.
		define( 'SAFE_PUBLISH_SYNC_MODE', 'invalid-mode' );

		// ACT: Filter the sync-mode pre-option value.
		$value = Options::pre_option_value(
			Options::SYNC_MODE_EXPORT,
			Options::OPTION_SYNC_MODE
		);

		// ASSERT: The original value passes through and the constant is reported.
		$this->assertSame( Options::SYNC_MODE_EXPORT, $value );
		$this->assertSame(
			'SAFE_PUBLISH_SYNC_MODE',
			get_test_doing_it_wrong_calls()[0]['function_name']
		);
		$this->assertStringContainsString(
			'export, import, bidirectional',
			get_test_doing_it_wrong_calls()[0]['message']
		);
	}

	/**
	 * Verifies that pre-option filtering returns the original value when no
	 * constant is configured.
	 */
	public function test_pre_option_value_returns_original_value_without_constant(): void {
		// ACT: Filter the sync-mode pre-option value without a constant.
		$value = Options::pre_option_value( false, Options::OPTION_SYNC_MODE );

		// ASSERT: The original pre-option value passes through unchanged.
		$this->assertFalse( $value );
	}
}
