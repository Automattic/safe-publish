<?php
/**
 * PHPUnit bootstrap file for integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

// Do not require Redis running for integration tests.
define( 'WP_REDIS_DISABLED', true );

// Require composer dependencies.
require_once __DIR__ . '/../../vendor/autoload.php';

$_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
$_tests_dir    = $_wp_tests_dir ? $_wp_tests_dir : getenv( 'WP_PHPUNIT__DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin(): void {
	require __DIR__ . '/../../safe-publish.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Set the sync direction before the plugin initializes.
 *
 * Reads WP_TEST_SYNC_DIRECTION from the PHPUnit XML configuration so that each
 * suite boots the plugin in the correct sync direction.
 *
 * For "send"/"both" sync direction a non-empty connected-site URL is required
 * so that Plugin::init() actually instantiates Auth_Manager.
 */
tests_add_filter(
	'plugins_loaded',
	function (): void {
		$sync_direction = getenv( 'WP_TEST_SYNC_DIRECTION' );

		if ( ! $sync_direction ) {
			throw new \RuntimeException( 'WP_TEST_SYNC_DIRECTION is not set.' );
		}

		update_option( 'safe_publish_sync_direction', $sync_direction );

		if ( in_array( $sync_direction, array( 'send', 'both' ), true ) ) {
			update_option( 'safe_publish_connected_site_url', 'https://source.example.com' );
		}
	},
	5
);

// Suppress cosmetic "Not running X tests" messages.
$_SERVER['argv'][] = '--group';
$_SERVER['argv'][] = 'ajax,ms-files,external-http';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
