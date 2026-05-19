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
 * would otherwise be blocked. We opt that one host back in via the
 * documented filter so cross-container media downloads succeed. The
 * allowlist is intentionally a single value: with `WP_HOME` set to
 * `host.docker.internal`, nothing legitimate in this stack should be
 * issuing safe HTTP calls against `localhost` or `127.0.0.1`. If one
 * shows up, that's a misconfiguration to surface rather than paper over.
 *
 * @param bool   $external Whether the request host is considered external.
 * @param string $host     The host being requested.
 * @return bool True for the wp-env dev host, otherwise the original
 *              decision.
 */
function safe_publish_dev_allow_docker_host( bool $external, string $host ): bool {
	if ( 'host.docker.internal' === $host ) {
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
 * Allows the source's wp-env port through WP's safe-port allowlist.
 *
 * `wp_http_validate_url()` has a second gate after the host check: an
 * allowlist of ports (default 80, 443, 8080) applied via
 * `http_allowed_safe_ports`. The source's port — 8889 — isn't in that
 * list, so `download_url()` rejects source media URLs with "A valid URL
 * was not provided" even when the host is whitelisted. Append it so
 * destination-side cross-container downloads pass both gates.
 *
 * Only 8889 is listed: the destination's own port (8888) isn't fetched
 * via the safe HTTP API anywhere in this codebase — both connectivity
 * probes go through `vip_safe_wp_remote_get()`/`wp_remote_get()`, which
 * skip `wp_http_validate_url()` entirely. If 8888 ever needs to be
 * here, that's a signal a new safe-API call site appeared, not
 * something to paper over.
 *
 * @param int[] $ports Currently allowed ports.
 * @return int[] Ports with the source's wp-env port appended.
 */
function safe_publish_dev_allow_wp_env_ports( array $ports ): array {
	if ( ! in_array( 8889, $ports, true ) ) {
		$ports[] = 8889;
	}

	return $ports;
}
add_filter(
	'http_allowed_safe_ports',
	'safe_publish_dev_allow_wp_env_ports'
);
