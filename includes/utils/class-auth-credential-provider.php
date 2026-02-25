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
 * Returns HMAC shared secret credentials when configured, falling back to
 * Basic Auth credentials in development environments only.
 */
class Auth_Credential_Provider {

	/**
	 * Returns authentication credentials from plugin settings.
	 *
	 * Prefers the HMAC shared secret (VIP-safe). Falls back to Basic Auth
	 * username/password only when running in a development environment.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	public static function get_credentials(): array {
		$shared_secret = get_option( Options::OPTION_SHARED_SECRET, '' );

		if ( ! empty( $shared_secret ) ) {
			return array( 'shared_secret' => $shared_secret );
		}

		if ( Environment::is_development() ) {
			$username = get_option( Options::OPTION_USERNAME, '' );
			$password = get_option( Options::OPTION_PASSWORD, '' );

			if ( ! empty( $username ) && ! empty( $password ) ) {
				return array(
					'username' => $username,
					'password' => $password,
				);
			}
		}

		return array();
	}
}
