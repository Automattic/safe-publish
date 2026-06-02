<?php
/**
 * PHPUnit bootstrap file for unit tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! defined( 'SAFE_PUBLISH__PLUGIN_DIRECTORY' ) ) {
	define( 'SAFE_PUBLISH__PLUGIN_DIRECTORY', __DIR__ . '/../../' );
}

if ( ! defined( 'SAFE_PUBLISH__UNIT_TEST' ) ) {
	define( 'SAFE_PUBLISH__UNIT_TEST', true );
}

if ( ! defined( 'SAFE_PUBLISH_VERSION' ) ) {
	define( 'SAFE_PUBLISH_VERSION', '1.1.0' );
}

if ( ! defined( 'SAFE_PUBLISH_PLUGIN_FILE' ) ) {
	define( 'SAFE_PUBLISH_PLUGIN_FILE', __DIR__ . '/../../safe-publish.php' );
}

if ( ! defined( 'SAFE_PUBLISH_PLUGIN_DIR' ) ) {
	define( 'SAFE_PUBLISH_PLUGIN_DIR', __DIR__ . '/../../' );
}

if ( ! defined( 'SAFE_PUBLISH_PLUGIN_URL' ) ) {
	define( 'SAFE_PUBLISH_PLUGIN_URL', 'http://localhost/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}

// Load Composer autoloader.
require_once __DIR__ . '/../../vendor/autoload.php';

// Load test utilities and stubs.
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/test-utils.php';
