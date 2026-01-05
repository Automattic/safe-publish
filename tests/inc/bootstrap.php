<?php
/**
 * PHPUnit bootstrap file for unit tests.
 *
 * @package Compliant_Content_Publisher
 */

declare(strict_types = 1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! defined( 'COMPLIANT_CONTENT_PUBLISHER__PLUGIN_DIRECTORY' ) ) {
	define( 'COMPLIANT_CONTENT_PUBLISHER__PLUGIN_DIRECTORY', __DIR__ . '/../../' );
}

if ( ! defined( 'COMPLIANT_CONTENT_PUBLISHER__UNIT_TEST' ) ) {
	define( 'COMPLIANT_CONTENT_PUBLISHER__UNIT_TEST', true );
}

if ( ! defined( 'CCP_VERSION' ) ) {
	define( 'CCP_VERSION', '1.1.0' );
}

if ( ! defined( 'CCP_PLUGIN_FILE' ) ) {
	define( 'CCP_PLUGIN_FILE', __DIR__ . '/../../compliant-content-publisher.php' );
}

if ( ! defined( 'CCP_PLUGIN_DIR' ) ) {
	define( 'CCP_PLUGIN_DIR', __DIR__ . '/../../' );
}

if ( ! defined( 'CCP_PLUGIN_URL' ) ) {
	define( 'CCP_PLUGIN_URL', 'http://localhost/' );
}

// Load Composer autoloader.
require_once __DIR__ . '/../../vendor/autoload.php';

// Load test utilities and stubs.
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/test-utils.php';
