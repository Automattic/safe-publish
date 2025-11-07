<?php declare(strict_types = 1);

/**
 * Plugin Name: Compliant Content Publisher
 * Plugin URI: https://github.com/wpcomvip/x-team-sandbox
 * Description: A WordPress plugin allowing editors to promote content from non-production to production.
 * Author: WPVIP
 * Author URI: https://wpvip.com
 * Text Domain: ccp
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'CCP_VERSION', '1.1.0' );
define( 'CCP_PLUGIN_FILE', __FILE__ );
define( 'CCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load plugin utility functions
require_once CCP_PLUGIN_DIR . 'functions.php';

// Autoloader for classes
spl_autoload_register(
	function ( $class_name ): void {
		// Only autoload CCP classes
		if ( 0 !== strpos( $class_name, 'CCP\\' ) ) {
				return;
		}

		// Convert namespace to file path
		$class_path = str_replace( 'CCP\\', '', $class_name );
		$class_path = str_replace( '\\', '/', $class_path );
		$class_path = strtolower( $class_path );
		$class_path = str_replace( '_', '-', $class_path );

		// Map class names to file paths
		$file_path = CCP_PLUGIN_DIR . 'includes/';

		// Handle specific namespace mappings
		if ( 0 === strpos( $class_path, 'admin/' ) ) {
			$file_path .= 'admin/class-' . str_replace( 'admin/', '', $class_path ) . '.php';
		} elseif ( 0 === strpos( $class_path, 'api/' ) ) {
			$file_path .= 'api/class-' . str_replace( 'api/', '', $class_path ) . '.php';
		} elseif ( 0 === strpos( $class_path, 'auth/' ) ) {
			$file_path .= 'auth/class-' . str_replace( 'auth/', '', $class_path ) . '.php';
		} elseif ( 0 === strpos( $class_path, 'validators/' ) ) {
			$file_path .= 'validators/class-' . str_replace( 'validators/', '', $class_path ) . '.php';
		} else {
			$file_path .= 'class-' . $class_path . '.php';
		}

		// VIP-safe file inclusion with proper validation
		// Ensure the file path is within the plugin directory for security
		$real_plugin_dir = realpath( CCP_PLUGIN_DIR );
		$real_file_path = realpath( $file_path );

		// Validate that the file exists and is within the plugin directory
		if ( $real_file_path &&
		file_exists( $file_path ) &&
		0 === strpos( $real_file_path, $real_plugin_dir ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Safe file inclusion within plugin directory with validation
			require_once $file_path;
		}
	}
);

// Initialize the plugin
add_action( 'plugins_loaded', 'ccp_init_plugin' );

/**
 * Initialize the plugin
 */
function ccp_init_plugin(): void {
	global $ccp_plugin;

	// Load text domain
	load_plugin_textdomain( 'ccp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Initialize the main plugin class
	$ccp_plugin = new \CCP\Plugin();
	$ccp_plugin->init();
}

/**
 * Plugin activation hook
 */
register_activation_hook( __FILE__, 'ccp_activation' );

function ccp_activation(): void {
	// Set default options
	if ( false === get_option( 'ccp_external_site_url' ) ) {
		update_option( 'ccp_external_site_url', '' );
	}

	if ( false === get_option( 'ccp_number_of_posts' ) ) {
		update_option( 'ccp_number_of_posts', 10 );
	}

	// Flush rewrite rules if needed (only in non-VIP environments)
	if ( ! defined( 'WPCOM_IS_VIP_ENV' ) || ! WPCOM_IS_VIP_ENV ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Only executed in non-VIP environments
		flush_rewrite_rules();
	}
}

/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, 'ccp_deactivation' );

function ccp_deactivation(): void {
	// Flush rewrite rules (only in non-VIP environments)
	if ( ! defined( 'WPCOM_IS_VIP_ENV' ) || ! WPCOM_IS_VIP_ENV ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Only executed in non-VIP environments
		flush_rewrite_rules();
	}
}
