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
	 * Option key for the Basic Auth username.
	 *
	 * @var string
	 */
	const OPTION_BASIC_AUTH_USERNAME = 'safe_publish_basic_auth_username';

	/**
	 * Option key for the Basic Auth password.
	 *
	 * @var string
	 */
	const OPTION_BASIC_AUTH_PASSWORD = 'safe_publish_basic_auth_password';

	/**
	 * Option key for the sync mode of this site.
	 *
	 * @var string
	 */
	const OPTION_SYNC_MODE = 'safe_publish_sync_mode';

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
	 * Meta key storing the GMT timestamp of the most recent import.
	 *
	 * @var string
	 */
	const META_IMPORT_DATE_GMT = 'safe_publish_import_date_gmt';

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
}
