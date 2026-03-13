<?php
/**
 * Settings Registrar class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers plugin settings with WordPress.
 */
class Settings_Registrar {

	/**
	 * Whether to register receive-only settings.
	 *
	 * @var bool
	 */
	private bool $register_receive_settings;

	/**
	 * Sanitizer instance used as sanitize callbacks.
	 *
	 * @var Settings_Sanitizer
	 */
	private Settings_Sanitizer $sanitizer;

	/**
	 * Constructs the Settings_Registrar instance.
	 *
	 * @param bool $register_receive_settings Whether to register receive-only settings.
	 */
	public function __construct( bool $register_receive_settings ) {
		$this->register_receive_settings = $register_receive_settings;
		$this->sanitizer                 = new Settings_Sanitizer();
	}

	/**
	 * Registers WordPress hooks for settings registration.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers plugin settings with WordPress.
	 */
	public function register_settings(): void {
		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_CONNECTED_SITE_URL,
			array(
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_url' ),
				'default'           => '',
			)
		);

		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_SYNC_DIRECTION,
			array(
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_sync_direction' ),
				'default'           => '',
			)
		);

		if ( ! $this->register_receive_settings ) {
			return;
		}

		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_NUMBER_OF_POSTS,
			array(
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_number_of_posts' ),
				'default'           => 10,
			)
		);

		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_USERNAME,
			array(
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_username' ),
				'default'           => '',
			)
		);

		register_setting(
			Options::SETTINGS_GROUP,
			Options::OPTION_PASSWORD,
			array(
				'sanitize_callback' => array( $this->sanitizer, 'sanitize_password' ),
				'default'           => '',
			)
		);
	}
}
