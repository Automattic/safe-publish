<?php
/**
 * WordPress Test Suite Configuration
 *
 * This file is used by the integration tests to configure the test environment.
 * It should NOT be used for your actual WordPress site.
 *
 * @package Safe_Publish
 */

// Test database settings - uses environment variables with fallbacks.
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ? getenv( 'WP_DB_PASSWORD' ) : 'password' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : 'mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// Test-specific constants.
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Site' );

// Required by wp-phpunit's install step to spawn PHP for fixture setup.
define( 'WP_PHP_BINARY', 'php' );

// Filesystem method so wp_filesystem-dependent code paths work in tests.
define( 'FS_METHOD', 'direct' );

// Disable wp-cron in tests. A fresh wptests_-prefixed DB has no cached
// update-check state, so otherwise wp_version_check() fires mid-test and
// hits the bootstrap's outbound-HTTP block. Guarded because wp-env's
// bundled site config may define this already.
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

// Disable core/plugin/theme auto-updates in tests so admin flows do not
// trigger wp.org reachability checks.
if ( ! defined( 'AUTOMATIC_UPDATER_DISABLED' ) ) {
	define( 'AUTOMATIC_UPDATER_DISABLED', true );
}

// Use a unique table prefix for tests to avoid conflicts.
$table_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ? getenv( 'WP_TESTS_TABLE_PREFIX' ) : 'wptests_';

// WordPress debug settings for tests.
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );

// Authentication keys and salts (test values).
define( 'AUTH_KEY', 'put your unique phrase here' );
define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );
define( 'LOGGED_IN_KEY', 'put your unique phrase here' );
define( 'NONCE_KEY', 'put your unique phrase here' );
define( 'AUTH_SALT', 'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT', 'put your unique phrase here' );
define( 'NONCE_SALT', 'put your unique phrase here' );

// Absolute path to WordPress directory. Inside the wp-env tests-cli container
// WordPress lives at /var/www/html/; WP_CORE_DIR lets other environments
// (e.g. bin/install-wp-tests.sh based setups) override.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', getenv( 'WP_CORE_DIR' ) ? getenv( 'WP_CORE_DIR' ) : '/var/www/html/' );
}
