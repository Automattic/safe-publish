<?php
/**
 * Auth Credential Provider Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;

/**
 * Auth Credential Provider Test.
 *
 * Tests that Auth_Credential_Provider assembles credentials correctly.
 * Shared Secret is read from the SAFE_PUBLISH_SHARED_SECRET constant or the
 * matching environment variable; Basic Auth credentials are read from
 * WordPress options.
 */
class AuthCredentialProviderTest extends TestCase {

	/**
	 * Clears the shared-secret environment variable before each test so a
	 * value exported in the surrounding shell cannot leak into assertions.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		set_test_env( 'SAFE_PUBLISH_SHARED_SECRET', null );
	}

	/**
	 * Resets test option overrides after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		parent::tearDown();
		reset_test_options();
	}

	/**
	 * Verifies that an empty array is returned when the constant is not defined
	 * and no Basic Auth options are configured.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_returns_empty_array_when_no_credentials_configured(): void {
		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertSame( array(), $credentials );
	}

	/**
	 * Verifies that the shared secret is included when the constant is defined.
	 */
	public function test_includes_shared_secret_when_constant_is_defined(): void {
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', 'test-shared-secret-value-for-unit-tests' );
		}

		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertArrayHasKey( 'shared_secret', $credentials );
		$this->assertSame( 'test-shared-secret-value-for-unit-tests', $credentials['shared_secret'] );
	}

	/**
	 * Verifies that Basic Auth credentials are absent from the result when
	 * options are empty (the default stub state).
	 */
	public function test_omits_basic_auth_when_options_are_empty(): void {
		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertArrayNotHasKey( 'username', $credentials );
		$this->assertArrayNotHasKey( 'password', $credentials );
	}

	/**
	 * Verifies that Basic Auth credentials are included when both username and
	 * password options are set.
	 */
	public function test_includes_basic_auth_when_both_credentials_configured(): void {
		// ARRANGE: store both Basic Auth option values.
		set_test_option( Options::OPTION_BASIC_AUTH_USERNAME, 'editor' );
		set_test_option( Options::OPTION_BASIC_AUTH_PASSWORD, 's3cr3t!' );

		// ACT: assemble credentials from the configured options.
		$credentials = Auth_Credential_Provider::get_credentials();

		// ASSERT: the stored username and password are included.
		$this->assertArrayHasKey( 'username', $credentials );
		$this->assertArrayHasKey( 'password', $credentials );
		$this->assertSame( 'editor', $credentials['username'] );
		$this->assertSame( 's3cr3t!', $credentials['password'] );
	}

	/**
	 * Verifies that Basic Auth credentials are included when both username and
	 * password constants are configured.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_includes_basic_auth_when_both_constants_configured(): void {
		// ARRANGE: define both Basic Auth constants.
		define( 'SAFE_PUBLISH_BASIC_AUTH_USERNAME', 'publisher' );
		define( 'SAFE_PUBLISH_BASIC_AUTH_PASSWORD', 'constant-password' );

		// ACT: assemble credentials from the configured constants.
		$credentials = Auth_Credential_Provider::get_credentials();

		// ASSERT: the constant username and password are included.
		$this->assertArrayHasKey( 'username', $credentials );
		$this->assertArrayHasKey( 'password', $credentials );
		$this->assertSame( 'publisher', $credentials['username'] );
		$this->assertSame( 'constant-password', $credentials['password'] );
	}

	/**
	 * Verifies that Basic Auth is omitted when only a username is configured
	 * (password is absent).
	 */
	public function test_omits_basic_auth_when_only_username_configured(): void {
		set_test_option( Options::OPTION_BASIC_AUTH_USERNAME, 'editor' );
		// No password option set.

		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertArrayNotHasKey( 'username', $credentials );
		$this->assertArrayNotHasKey( 'password', $credentials );
	}

	/**
	 * Verifies that Basic Auth is omitted when only a password is configured
	 * (username is absent).
	 */
	public function test_omits_basic_auth_when_only_password_configured(): void {
		set_test_option( Options::OPTION_BASIC_AUTH_PASSWORD, 's3cr3t!' );
		// No username option set.

		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertArrayNotHasKey( 'username', $credentials );
		$this->assertArrayNotHasKey( 'password', $credentials );
	}

	/**
	 * Verifies that both shared secret and Basic Auth keys are present when the
	 * constant is defined and both credentials are configured.
	 */
	public function test_includes_shared_secret_and_basic_auth_together(): void {
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', 'test-shared-secret-value-for-unit-tests' );
		}

		set_test_option( Options::OPTION_BASIC_AUTH_USERNAME, 'editor' );
		set_test_option( Options::OPTION_BASIC_AUTH_PASSWORD, 's3cr3t!' );

		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertArrayHasKey( 'shared_secret', $credentials );
		$this->assertArrayHasKey( 'username', $credentials );
		$this->assertArrayHasKey( 'password', $credentials );
	}

	/**
	 * Verifies that the shared secret is read from the environment variable
	 * when the constant is not defined.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reads_shared_secret_from_env_var(): void {
		// ARRANGE: provide the secret only through the environment variable.
		set_test_env( 'SAFE_PUBLISH_SHARED_SECRET', 'env-shared-secret-value-1234' );

		// ACT: assemble the credentials.
		$credentials = Auth_Credential_Provider::get_credentials();

		// ASSERT: the environment value is used as the shared secret.
		$this->assertArrayHasKey( 'shared_secret', $credentials );
		$this->assertSame( 'env-shared-secret-value-1234', $credentials['shared_secret'] );
	}

	/**
	 * Verifies that the constant takes precedence over the environment variable
	 * when both are set.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_takes_precedence_over_env_var(): void {
		// ARRANGE: set the constant and environment variable to different values.
		set_test_env( 'SAFE_PUBLISH_SHARED_SECRET', 'env-shared-secret-value-1234' );
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', 'constant-shared-secret-value' );
		}

		// ACT: assemble the credentials.
		$credentials = Auth_Credential_Provider::get_credentials();

		// ASSERT: the constant wins over the environment variable.
		$this->assertSame( 'constant-shared-secret-value', $credentials['shared_secret'] );
	}
}
