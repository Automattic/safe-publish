<?php
/**
 * Plugin Name: Safe Publish — Local Dev Helpers
 * Description: Lets WP's safe HTTP API reach the sibling wp-env container
 *              so the destination can fetch source media across Docker.
 *              Active only when WP_ENVIRONMENT_TYPE is 'development'. Not
 *              packaged in the release zip (see `files` in package.json).
 * Author:      Local development
 * Version:     1.0.0
 *
 * @package SafePublish\LocalDev
 */

declare( strict_types = 1 );

// Belt-and-braces: bail out anywhere this file ever ends up that isn't a
// development environment. The repository excludes this file from the
// release zip, but a defensive guard keeps the behavior local even if it
// gets dropped into a non-dev install by hand.
if (
	! defined( 'WP_ENVIRONMENT_TYPE' )
	|| 'development' !== WP_ENVIRONMENT_TYPE
) {
	return;
}

/**
 * Allows WordPress's safe HTTP API to reach the sibling wp-env container.
 *
 * `download_url()` — used by Media_Importer to fetch images — routes
 * through `wp_safe_remote_get()` → `wp_http_validate_url()`, which
 * rejects URLs whose host resolves to a private/loopback IP. On Docker
 * Desktop, `host.docker.internal` resolves into the 192.168.x range and
 * would otherwise be blocked. We opt the dev hosts back in via the
 * documented filter so cross-container media downloads succeed.
 *
 * @param bool   $external Whether the request host is considered external.
 * @param string $host     The host being requested.
 * @return bool True for the wp-env dev hosts, otherwise the original
 *              decision.
 */
function safe_publish_dev_allow_docker_host( $external, $host ) {
	$allowed = array(
		'host.docker.internal',
		'localhost',
		'127.0.0.1',
	);

	if ( in_array( $host, $allowed, true ) ) {
		return true;
	}

	return $external;
}
add_filter(
	'http_request_host_is_external',
	'safe_publish_dev_allow_docker_host',
	10,
	2
);

/**
 * Allows the wp-env dev ports through WP's safe-port allowlist.
 *
 * `wp_http_validate_url()` has a second gate after the host check: an
 * allowlist of ports (default 80, 443, 8080) applied via
 * `http_allowed_safe_ports`. The wp-env default and tests ports — 8888
 * and 8889 — aren't in that list, so `download_url()` rejects source
 * media URLs with "A valid URL was not provided" even when the host is
 * whitelisted. Append the dev ports so cross-container downloads pass
 * both gates.
 *
 * @param int[] $ports Currently allowed ports.
 * @return int[] Ports with the wp-env dev ports appended.
 */
function safe_publish_dev_allow_wp_env_ports( $ports ) {
	$dev_ports = array( 8888, 8889 );

	foreach ( $dev_ports as $port ) {
		if ( ! in_array( $port, $ports, true ) ) {
			$ports[] = $port;
		}
	}

	return $ports;
}
add_filter(
	'http_allowed_safe_ports',
	'safe_publish_dev_allow_wp_env_ports'
);
