<?php
/**
 * Plugin Name: Safe Publish VIP Authentication Handler
 * Plugin URI: https://github.com/Automattic/safe-publish
 * Description: VIP-compatible auth handler for Safe Publish using shared secret HMAC.
 * Version: 1.0.0
 * Author: WPVIP
 * Author URI: https://wpvip.com
 * Network: true
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This mu-plugin handles authentication for Safe Publish
 * requests on WordPress VIP environments using shared secret HMAC authentication.
 *
 * The shared secret is read from the SAFE_PUBLISH_SHARED_SECRET environment variable
 * which should be configured in the VIP dashboard.
 *
 * @package Safe_Publish_Auth
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent multiple inclusions.
if ( defined( 'SAFE_PUBLISH_VIP_AUTH_LOADED' ) ) {
	return;
}
define( 'SAFE_PUBLISH_VIP_AUTH_LOADED', true );

if ( ! is_dir( __DIR__ . '/safe-publish-auth' ) ) {
	add_action(
		'admin_notices',
		function (): void {
			echo '<div class="error"><p><strong>Safe Publish Auth:</strong> Missing required folder. Please ensure the <code>safe-publish-auth</code> folder is copied to <code>wp-content/mu-plugins/</code></p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/safe-publish-auth/class-auth-logger.php';
require_once __DIR__ . '/safe-publish-auth/interface-authenticator.php';
require_once __DIR__ . '/safe-publish-auth/class-hmac-authenticator.php';
require_once __DIR__ . '/safe-publish-auth/class-permission-manager.php';
require_once __DIR__ . '/safe-publish-auth/class-dashboard-widget.php';
require_once __DIR__ . '/safe-publish-auth/class-auth-manager.php';

// Initialize authentication system at plugins_loaded (priority 1 to run early).
add_action(
	'plugins_loaded',
	function (): void {
		$auth_manager = new \Safe_Publish\Auth\Auth_Manager();
		$auth_manager->init();
	},
	1
);

/**
 * Disables redirects for development hostnames.
 */
add_filter(
	'redirect_canonical',
	function ( $redirect_url, $requested_url ): string|false {
		// Get the hostname from the request.
		$requested_host = wp_parse_url( $requested_url, PHP_URL_HOST );

		// Allow both localhost and host.docker.internal for the same site.
		$allowed_hosts = array( 'localhost', 'host.docker.internal' );

		// If the requested host is in our allowed list and matches the site host pattern, don't redirect.
		if ( in_array( $requested_host, $allowed_hosts, true ) ) {
			// Check if it's the same port and path.
			$requested_port = wp_parse_url( $requested_url, PHP_URL_PORT );
			$site_port      = wp_parse_url( home_url(), PHP_URL_PORT );

			if ( $requested_port === $site_port ) {
				return false; // Disable redirect.
			}
		}

		return $redirect_url;
	},
	10,
	2
);

/**
 * Allows WordPress to accept requests from host.docker.internal.
 */
add_filter(
	'allowed_http_origins',
	function ( $origins ): array {
		$site_url  = home_url();
		$site_port = wp_parse_url( $site_url, PHP_URL_PORT );

		// Add host.docker.internal with the same port.
		if ( $site_port ) {
			$origins[] = 'http://host.docker.internal:' . $site_port;
		} else {
			$origins[] = 'http://host.docker.internal';
		}

		return $origins;
	}
);
