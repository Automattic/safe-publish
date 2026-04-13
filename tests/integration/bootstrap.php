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
 * Set the sync mode before the plugin initializes.
 *
 * Reads WP_TEST_SYNC_MODE from the PHPUnit XML configuration so that each suite
 * boots the plugin in the correct sync mode.
 *
 * For "export"/"bidirectional" sync mode a non-empty connected-site URL is
 * required so that Plugin::init() actually instantiates Auth_Manager.
 */
tests_add_filter(
	'plugins_loaded',
	function (): void {
		$sync_mode = getenv( 'WP_TEST_SYNC_MODE' );

		if ( ! $sync_mode ) {
			throw new \RuntimeException( 'WP_TEST_SYNC_MODE is not set.' );
		}

		update_option( 'safe_publish_sync_mode', $sync_mode );

		if ( in_array( $sync_mode, array( 'export', 'bidirectional' ), true ) ) {
			update_option( 'safe_publish_connected_site_url', 'https://source.example.com' );
		}
	},
	5
);

// Block any outbound HTTP requests not explicitly handled by a test's own
// pre_http_request mock. Runs last (priority 999) so individual test mocks
// at lower priorities have the opportunity to intercept first.
tests_add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		unset( $args );
		if ( false !== $preempt ) {
			return $preempt;
		}
		return new \WP_Error(
			'http_request_not_mocked',
			'Unexpected outbound HTTP request in tests: ' . $url
		);
	},
	999,
	3
);

// Suppress cosmetic "Not running X tests" messages.
$_SERVER['argv'][] = '--group';
$_SERVER['argv'][] = 'ajax,ms-files,external-http';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
