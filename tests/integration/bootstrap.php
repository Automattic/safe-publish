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

// Force the wp-phpunit bootstrap to load our config file (tests/wp-tests-config.php)
// rather than a bundled wp-tests-config.php that may ship alongside $_tests_dir
// inside wp-env containers. Without this, the bundled config's $table_prefix = 'wp_'
// against the source site's DB causes wp-phpunit's install step to drop the source
// site's tables every test run, resetting the source site. See wp-phpunit's
// includes/bootstrap.php, which honors this constant before falling back to the
// sibling wp-tests-config.php.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', realpath( __DIR__ . '/../wp-tests-config.php' ) );
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

// Suppress WordPress core update checks. On a fresh wptests_-prefixed DB
// the update_core/update_plugins/update_themes transients are empty, so
// _maybe_update_core() (and siblings) fire wp_version_check() on admin_init.
// That hits the outbound HTTP block above and surfaces as a test error.
tests_add_filter(
	'muplugins_loaded',
	function (): void {
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		remove_action( 'wp_version_check', 'wp_version_check' );
		remove_action( 'wp_update_plugins', 'wp_update_plugins' );
		remove_action( 'wp_update_themes', 'wp_update_themes' );
	}
);

// Suppress cosmetic "Not running X tests" messages.
$_SERVER['argv'][] = '--group';
$_SERVER['argv'][] = 'ajax,ms-files,external-http';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
