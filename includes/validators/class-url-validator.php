<?php
/**
 * URL Validator class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Validators;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL Validator Class.
 */
class URL_Validator {

	/**
	 * Validates the format of an external URL.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_external_url( string $url ): bool {
		// Check if URL is valid.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( false === $host ) {
			return false;
		}

		return true;
	}

	/**
	 * Validates and sanitizes a URL.
	 *
	 * @param string $url Raw URL input.
	 * @return string|false Sanitized URL or false if invalid.
	 */
	public static function sanitize_external_url( string $url ): string|false {
		$sanitized_url = esc_url_raw( $url );

		if ( self::is_valid_external_url( $sanitized_url ) ) {
			return $sanitized_url;
		}

		return false;
	}
}
