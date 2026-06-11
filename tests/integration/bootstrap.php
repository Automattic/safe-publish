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

// The plugin's Telemetry_Service guards on class_exists() of the VIP
// mu-plugins telemetry client. That class isn't installed locally, so
// integration tests would silently skip every emit. Load a minimal stub
// so the wrapper takes its real send path and tests can assert via an
// injected Telemetry_Event_Queue.
require_once __DIR__ . '/stubs/automattic-vip-telemetry-stub.php';

$_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
$_tests_dir    = $_wp_tests_dir ? $_wp_tests_dir : getenv( 'WP_PHPUNIT__DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Pin wp-phpunit to our config. Otherwise it falls back to a sibling
// wp-tests-config.php (e.g. the one wp-env mounts into its containers)
// and drops the source site's tables during install.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', realpath( __DIR__ . '/../wp-tests-config.php' ) );
}

// Create the integration tests database if it doesn't exist.
$_db_host = (string) getenv( 'WP_DB_HOST' );
$_db_user = (string) getenv( 'WP_DB_USER' );
$_db_pass = (string) getenv( 'WP_DB_PASSWORD' );
$_db_name = (string) getenv( 'WP_DB_NAME' );

if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $_db_name ) ) {
	echo "Invalid WP_DB_NAME: '{$_db_name}'." . PHP_EOL;
	exit( 1 );
}

$_mysqli = new mysqli( $_db_host, $_db_user, $_db_pass );
if ( $_mysqli->connect_errno ) {
	echo "Cannot connect to {$_db_host}: {$_mysqli->connect_error}" . PHP_EOL;
	exit( 1 );
}

if ( ! $_mysqli->query( "CREATE DATABASE IF NOT EXISTS `{$_db_name}`" ) ) {
	echo "Failed to create DB `{$_db_name}`: {$_mysqli->error}" . PHP_EOL;
	$_mysqli->close();
	exit( 1 );
}

$_mysqli->close();
unset( $_db_host, $_db_user, $_db_pass, $_db_name, $_mysqli );

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;
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

// Suppress WordPress core update checks. On a freshly-installed test DB
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
