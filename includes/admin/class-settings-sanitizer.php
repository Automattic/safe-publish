<?php
/**
 * Settings Sanitizer class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Options;
use Safe_Publish\Validators\URL_Validator;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides stateless sanitization callbacks for plugin settings.
 */
class Settings_Sanitizer {

	/**
	 * Sanitizes the external site URL setting.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL or empty string on failure.
	 */
	public function sanitize_url( $url ): string {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return '';
		}

		if ( ! URL_Validator::is_valid_external_url( $url ) ) {
			add_settings_error(
				Options::OPTION_CONNECTED_SITE_URL,
				'invalid_url',
				__( 'Please enter a valid connected site URL.', 'safe-publish' )
			);
			return get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		}

		return $url;
	}

	/**
	 * Sanitizes the number of posts setting.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return int Sanitized number of posts, between 1 and 100.
	 */
	public function sanitize_number_of_posts( $value ): int {
		// preserve the existing value when sync mode is send-only.
		if ( null === $value ) {
			return (int) get_option( Options::OPTION_NUMBER_OF_POSTS, 10 );
		}

		$number = absint( $value );

		if ( $number < 1 || $number > 100 ) {
			add_settings_error(
				Options::OPTION_NUMBER_OF_POSTS,
				'invalid_number',
				__( 'Number of posts must be between 1 and 100.', 'safe-publish' )
			);
			return (int) get_option( Options::OPTION_NUMBER_OF_POSTS, 10 );
		}

		return $number;
	}

	/**
	 * Sanitizes a checkbox setting value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return bool Sanitized checkbox value.
	 */
	public function sanitize_checkbox( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Sanitizes the username for Basic authentication.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized username.
	 */
	public function sanitize_username( $value ): string {
		// preserve the existing value when sync mode is send-only.
		if ( null === $value ) {
			return (string) get_option( Options::OPTION_USERNAME, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Sanitizes the password for Basic authentication.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized password.
	 */
	public function sanitize_password( $value ): string {
		// preserve the existing value when sync mode is send-only.
		if ( null === $value ) {
			return (string) get_option( Options::OPTION_PASSWORD, '' );
		}

		// Don't sanitize passwords beyond trimming whitespace.
		return trim( $value );
	}

	/**
	 * Sanitizes the sync mode setting.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string One of 'send', 'receive', 'both', or '' on invalid input.
	 */
	public function sanitize_sync_mode( $value ): string {
		$allowed = array(
			Options::SYNC_MODE_SEND,
			Options::SYNC_MODE_RECEIVE,
			Options::SYNC_MODE_BOTH,
		);

		if ( '' === $value || null === $value ) {
			return '';
		}

		if ( ! in_array( $value, $allowed, true ) ) {
			add_settings_error(
				Options::OPTION_SYNC_MODE,
				'invalid_sync_mode',
				__( 'Please select a valid Sync Mode.', 'safe-publish' )
			);

			return get_option( Options::OPTION_SYNC_MODE, '' );
		}

		return $value;
	}
}
