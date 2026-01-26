<?php
/**
 * Environment utility class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Utils;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Environment utility class.
 */
class Environment {

	/**
	 * Checks if the current environment is a development environment.
	 *
	 * @return bool True if development environment.
	 */
	public static function is_development(): bool {
		// Never allow Basic auth in VIP production environments.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && constant( 'WPCOM_IS_VIP_ENV' ) ) {
			return false;
		}

		// Check for common development indicators.
		if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
			return true;
		}

		if ( defined( 'WP_LOCAL_DEV' ) && constant( 'WP_LOCAL_DEV' ) ) {
			return true;
		}

		// Check for development domains.
		$site_url = get_site_url();
		$host     = wp_parse_url( $site_url, PHP_URL_HOST );

		$dev_domains = array( '.test', '.local', '.dev', 'localhost', '127.0.0.1', '::1' );

		foreach ( $dev_domains as $dev_domain ) {
			if ( $host === $dev_domain ||
				( function_exists( 'str_ends_with' ) && str_ends_with( $host, $dev_domain ) ) ||
				( ! function_exists( 'str_ends_with' ) && substr( $host, -strlen( $dev_domain ) ) === $dev_domain ) ) {
				return true;
			}
		}

		return false;
	}
}
