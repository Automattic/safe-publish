<?php
/**
 * Plugin options and meta key constants
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
 * Centralizes all WordPress option names and post meta key strings.
 */
class Options {

	// -------------------------------------------------------------------------
	// Option keys (stored via get_option / update_option).
	// -------------------------------------------------------------------------

	/**
	 * Option key for the connected site URL.
	 *
	 * @var string
	 */
	const OPTION_CONNECTED_SITE_URL = 'safe_publish_connected_site_url';

	/**
	 * Constant name for the connected site URL.
	 *
	 * @var string
	 */
	const CONSTANT_CONNECTED_SITE_URL = 'SAFE_PUBLISH_CONNECTED_SITE_URL';

	/**
	 * Option key for the Basic Auth username.
	 *
	 * @var string
	 */
	const OPTION_BASIC_AUTH_USERNAME = 'safe_publish_basic_auth_username';

	/**
	 * Constant name for the Basic Auth username.
	 *
	 * @var string
	 */
	const CONSTANT_BASIC_AUTH_USERNAME = 'SAFE_PUBLISH_BASIC_AUTH_USERNAME';

	/**
	 * Option key for the Basic Auth password.
	 *
	 * @var string
	 */
	const OPTION_BASIC_AUTH_PASSWORD = 'safe_publish_basic_auth_password';

	/**
	 * Constant name for the Basic Auth password.
	 *
	 * @var string
	 */
	const CONSTANT_BASIC_AUTH_PASSWORD = 'SAFE_PUBLISH_BASIC_AUTH_PASSWORD';

	/**
	 * Option key for the sync mode of this site.
	 *
	 * @var string
	 */
	const OPTION_SYNC_MODE = 'safe_publish_sync_mode';

	/**
	 * Constant name for the sync mode.
	 *
	 * @var string
	 */
	const CONSTANT_SYNC_MODE = 'SAFE_PUBLISH_SYNC_MODE';

	/**
	 * Sync mode value: this site exports content.
	 *
	 * @var string
	 */
	const SYNC_MODE_EXPORT = 'export';

	/**
	 * Sync mode value: this site imports content.
	 *
	 * @var string
	 */
	const SYNC_MODE_IMPORT = 'import';

	/**
	 * Sync mode value: this site can export and import content.
	 *
	 * @var string
	 */
	const SYNC_MODE_BIDIRECTIONAL = 'bidirectional';

	// -------------------------------------------------------------------------
	// Post meta keys (stored via get_post_meta / update_post_meta).
	// -------------------------------------------------------------------------

	/**
	 * Meta key storing the source post ID.
	 *
	 * @var string
	 */
	const META_SOURCE_POST_ID = 'safe_publish_source_post_id';

	/**
	 * Meta key storing the source post permalink.
	 *
	 * @var string
	 */
	const META_SOURCE_LINK = 'safe_publish_source_link';

	/**
	 * Meta key identifying the source of an imported post or attachment.
	 *
	 * @var string
	 */
	const META_IMPORTED_FROM = 'safe_publish_imported_from';

	/**
	 * Meta key storing the original source URL of an imported media attachment.
	 *
	 * @var string
	 */
	const META_ORIGINAL_URL = 'safe_publish_original_url';

	/**
	 * Meta key storing the source featured media ID on an imported attachment.
	 *
	 * @var string
	 */
	const META_FEATURED_MEDIA_ID = 'safe_publish_featured_media_id';

	/**
	 * Meta key classifying the type of imported media (e.g. featured_image).
	 *
	 * @var string
	 */
	const META_MEDIA_TYPE = 'safe_publish_media_type';

	/**
	 * Private meta key storing the source author's email at import time.
	 *
	 * @var string
	 */
	const META_SOURCE_AUTHOR_EMAIL = '_safe_publish_source_author_email';

	/**
	 * Private meta key storing the source author's login at import time.
	 *
	 * @var string
	 */
	const META_SOURCE_AUTHOR_LOGIN = '_safe_publish_source_author_login';

	/**
	 * Private meta key storing the source post's parent ID at import time.
	 *
	 * @var string
	 */
	const META_SOURCE_POST_PARENT_ID = '_safe_publish_source_post_parent_id';

	/**
	 * WordPress settings-API group slug shared by all plugin options.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'safe_publish_settings';

	// -------------------------------------------------------------------------
	// Meta values.
	// -------------------------------------------------------------------------

	/**
	 * Value stored in META_IMPORTED_FROM to identify posts imported by this plugin.
	 *
	 * @var string
	 */
	const META_IMPORTED_FROM_VALUE = 'safe-publish';

	/**
	 * Registers filters that allow deployment-defined constants to override
	 * stored option values.
	 */
	public static function register_constant_filters(): void {
		foreach ( array_keys( self::get_constant_option_map() ) as $option ) {
			add_filter(
				'pre_option_' . $option,
				static function ( mixed $pre_option ) use ( $option ): mixed {
					return self::pre_option_value( $pre_option, $option );
				}
			);
		}
	}

	/**
	 * Returns an option value, preferring a deployment-defined constant when set.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default_value Default value.
	 * @return mixed Option value.
	 */
	public static function get_value( string $option, mixed $default_value = false ): mixed {
		$constant_value = self::get_constant_value_for_option( $option );

		if ( null !== $constant_value ) {
			return $constant_value;
		}

		return get_option( $option, $default_value );
	}

	/**
	 * Filters a WordPress option preflight value.
	 *
	 * @param mixed  $pre_option Existing preflight option value.
	 * @param string $option     Option name.
	 * @return mixed Constant value when configured; otherwise the original value.
	 */
	public static function pre_option_value( mixed $pre_option, string $option ): mixed {
		$constant_value = self::get_constant_value_for_option( $option );

		if ( null !== $constant_value ) {
			return $constant_value;
		}

		return $pre_option;
	}

	/**
	 * Returns the configured constant value for an option.
	 *
	 * @param string $option Option name.
	 * @return string|null Constant value, or null when no constant is configured.
	 */
	private static function get_constant_value_for_option( string $option ): ?string {
		$map = self::get_constant_option_map();

		if ( empty( $map[ $option ] ) || ! defined( $map[ $option ] ) ) {
			return null;
		}

		return (string) constant( $map[ $option ] );
	}

	/**
	 * Returns the option-to-constant map.
	 *
	 * @return array<string,string>
	 */
	private static function get_constant_option_map(): array {
		return array(
			self::OPTION_CONNECTED_SITE_URL  => self::CONSTANT_CONNECTED_SITE_URL,
			self::OPTION_BASIC_AUTH_USERNAME => self::CONSTANT_BASIC_AUTH_USERNAME,
			self::OPTION_BASIC_AUTH_PASSWORD => self::CONSTANT_BASIC_AUTH_PASSWORD,
			self::OPTION_SYNC_MODE           => self::CONSTANT_SYNC_MODE,
		);
	}
}
