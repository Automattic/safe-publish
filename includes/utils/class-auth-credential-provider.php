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
 */
class Auth_Credential_Provider {

	/**
	 * Returns authentication credentials from plugin settings.
	 *
	 * Shared Secret is always included when the SAFE_PUBLISH_SHARED_SECRET
	 * constant is defined.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	public static function get_credentials(): array {
		$credentials = array();

		if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( constant( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
			$credentials['shared_secret'] = constant( 'SAFE_PUBLISH_SHARED_SECRET' );
		}

		return $credentials;
	}
}
