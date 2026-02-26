<?php
/**
 * Settings Sanitizer class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Environment;
use Safe_Publish\Utils\Options;
use Safe_Publish\Validators\URL_Validator;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles registration and sanitization of plugin settings.
 */
class Settings_Sanitizer {

	/**
	 * Registers WordPress hooks for settings registration.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers all plugin settings with WordPress.
	 */
	public function register_settings(): void {
		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_EXTERNAL_SITE_URL,
			array(
				'sanitize_callback' => array( $this, 'sanitize_url' ),
				'default'           => '',
			)
		);

		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_NUMBER_OF_POSTS,
			array(
				'sanitize_callback' => array( $this, 'sanitize_number_of_posts' ),
				'default'           => 10,
			)
		);

		// Basic authentication settings (development only).
		if ( Environment::is_development() ) {
			register_setting(
				Options::SETTINGS_GROUP,
				Options::OPTION_USERNAME,
				array(
					'sanitize_callback' => array( $this, 'sanitize_username' ),
					'default'           => '',
				)
			);

			register_setting(
				Options::SETTINGS_GROUP,
				Options::OPTION_PASSWORD,
				array(
					'sanitize_callback' => array( $this, 'sanitize_password' ),
					'default'           => '',
				)
			);
		}
	}

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
				Options::OPTION_EXTERNAL_SITE_URL,
				'invalid_url',
				__( 'Please enter a valid external site URL.', 'safe-publish' )
			);
			return get_option( Options::OPTION_EXTERNAL_SITE_URL, '' );
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
		$number = absint( $value );

		if ( $number < 1 || $number > 100 ) {
			add_settings_error(
				Options::OPTION_NUMBER_OF_POSTS,
				'invalid_number',
				__( 'Number of posts must be between 1 and 100.', 'safe-publish' )
			);
			return get_option( Options::OPTION_NUMBER_OF_POSTS, 10 );
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
	 * Sanitizes the username for Basic authentication (development only).
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized username, or empty string outside development.
	 */
	public function sanitize_username( $value ): string {
		if ( ! Environment::is_development() ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Sanitizes the password for Basic authentication (development only).
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized password, or empty string outside development.
	 */
	public function sanitize_password( $value ): string {
		if ( ! Environment::is_development() ) {
			return '';
		}

		// Don't sanitize passwords beyond trimming whitespace.
		return trim( $value );
	}
}
