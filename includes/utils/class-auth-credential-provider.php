<?php
/**
 * Auth Credential Provider utility
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for reading authentication credentials from plugin
 * settings.
 *
 * Always returns the shared secret when configured (required). Optionally
 * includes Basic Auth credentials when a username and password are saved.
 */
class Auth_Credential_Provider {

	/**
	 * Returns authentication credentials from plugin settings.
	 *
	 * Shared Secret is always included when the SAFE_PUBLISH_SHARED_SECRET
	 * constant is defined. Basic Auth credentials are included when configured.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	public static function get_credentials(): array {
		$credentials = array();

		// Shared secret is required - read from constant defined in wp-config.php.
		if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( constant( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
			$credentials['shared_secret'] = constant( 'SAFE_PUBLISH_SHARED_SECRET' );
		}

		// Basic auth is optional and can be layered on top of shared secret auth.
		$username = get_option( Options::OPTION_BASIC_AUTH_USERNAME, '' );
		$password = get_option( Options::OPTION_BASIC_AUTH_PASSWORD, '' );

		if ( ! empty( $username ) && ! empty( $password ) ) {
			$credentials['username'] = $username;
			$credentials['password'] = $password;
		}

		return $credentials;
	}
}
