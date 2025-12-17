<?php
/**
 * URL Validator class
 *
 * @package CCP
 */

namespace CCP\Validators;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL Validator Class.
 */
class URL_Validator {

	/**
	 * Validates external URLs for security compliance.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_external_url( $url ): bool {
		// Check if URL is valid.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Only allow HTTPS URLs for security.
		// if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		// 	return false;
		// }

		// Additional security: prevent localhost/private network access.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( false === $host ) {
			return false;
		}

		// Block private/local addresses for security.
		// TODO: Uncomment and implement IP validation if needed.
		// $ip = gethostbyname( $host );
		// if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
		// return false;
		// }

		return true;
	}

	/**
	 * Validates URL scheme based on environment.
	 *
	 * @param string $url    Full URL.
	 * @param string $scheme URL scheme.
	 * @return bool True if valid scheme for the environment.
	 */
	private static function is_valid_scheme( $url, $scheme ): bool {
		// Always require HTTPS in VIP production environments.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			return 'https' === $scheme;
		}

		// Parse URL to check for development indicators.
		$host = wp_parse_url( $url, PHP_URL_HOST );

		// Development domains where HTTP is allowed.
		$dev_domains = array(
			'.test',
			'.local',
			'.dev',
			'localhost',
			'127.0.0.1',
			'::1'
		);

		// Check if this is a development domain.
		foreach ( $dev_domains as $dev_domain ) {
			if ( $host === $dev_domain ||
				 ( function_exists( 'str_ends_with' ) && str_ends_with( $host, $dev_domain ) ) ||
				 ( ! function_exists( 'str_ends_with' ) && substr( $host, -strlen( $dev_domain ) ) === $dev_domain ) ) {
				// Allow both HTTP and HTTPS for development domains.
				return in_array( $scheme, array( 'http', 'https' ), true );
			}
		}

		// For production domains, require HTTPS.
		return 'https' === $scheme;
	}

	/**
	 * Validates and sanitizes a URL.
	 *
	 * @param string $url Raw URL input.
	 * @return string|false Sanitized URL or false if invalid.
	 */
	public static function sanitize_external_url( $url ): string|false {
		$sanitized_url = esc_url_raw( $url );

		if ( self::is_valid_external_url( $sanitized_url ) ) {
			return $sanitized_url;
		}

		return false;
	}

	/**
	 * Gets allowed URL schemes based on environment.
	 *
	 * @param string $url Optional. URL to check for development domains. Default ''.
	 * @return array Allowed schemes.
	 */
	public static function get_allowed_schemes( $url = '' ): array {
		// Always require HTTPS in VIP production environments.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			return array( 'https' );
		}

		// If URL is provided, check if it's a development domain.
		if ( ! empty( $url ) ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$dev_domains = array( '.test', '.local', '.dev', 'localhost', '127.0.0.1', '::1' );

			foreach ( $dev_domains as $dev_domain ) {
				if ( $host === $dev_domain ||
					 ( function_exists( 'str_ends_with' ) && str_ends_with( $host, $dev_domain ) ) ||
					 ( ! function_exists( 'str_ends_with' ) && substr( $host, -strlen( $dev_domain ) ) === $dev_domain ) ) {
					// Allow both HTTP and HTTPS for development domains.
					return array( 'http', 'https' );
				}
			}
		}

		// Default: require HTTPS for production domains.
		return array( 'https' );
	}

	/**
	 * Checks if domain is in whitelist (for future enhancement).
	 *
	 * @param string $url URL to check.
	 * @return bool Whether the domain is whitelisted.
	 */
	public static function is_domain_whitelisted( $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		/**
		 * Filters the allowed domains for external requests.
		 *
		 * @param array  $allowed_domains Allowed domain patterns.
		 * @param string $host            Hostname being checked.
		 */
		$allowed_domains = apply_filters( 'ccp_allowed_domains', array(), $host );

		if ( empty( $allowed_domains ) ) {
			return true; // No restrictions by default.
		}

		foreach ( $allowed_domains as $domain ) {
			if ( fnmatch( $domain, $host ) ) {
				return true;
			}
		}

		return false;
	}
}
