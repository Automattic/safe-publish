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
 * Shared Secret is read from the SAFE_PUBLISH_SHARED_SECRET constant;
 * Basic Auth credentials are read from WordPress options.
 */
class AuthCredentialProviderTest extends TestCase {

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
	 * Verifies that the credentials array contains only the shared secret key
	 * when the constant is defined.
	 */
	public function test_returns_only_shared_secret_when_constant_defined(): void {
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', 'test-shared-secret-value-for-unit-tests' );
		}

		$credentials = Auth_Credential_Provider::get_credentials();

		$this->assertArrayHasKey( 'shared_secret', $credentials );
		$this->assertArrayNotHasKey( 'username', $credentials );
		$this->assertArrayNotHasKey( 'password', $credentials );
	}
}
