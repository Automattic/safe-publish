<?php
/**
 * Fixture: Valid HMAC Request.
 *
 * Provides a helper function to build a complete, valid HMAC-authenticated
 * request data array for use in auth unit tests.
 *
 * @package Safe_Publish\Tests
 */

declare(strict_types=1);

/**
 * Creates a valid HMAC-authenticated request data array.
 *
 * Produces a timestamp, SHA-256 content hash, and HMAC-SHA256 signature
 * matching the algorithm expected by safe_publish_vip_authenticate_shared_secret().
 *
 * @param string $method        HTTP method (e.g. 'GET', 'POST').
 * @param string $route         REST API route (e.g. '/wp/v2/posts').
 * @param string $body          Raw request body.
 * @param string $shared_secret Shared secret for HMAC signing.
 * @return array{
 *     method: string,
 *     route: string,
 *     body: string,
 *     headers: array{
 *         X-Safe-Publish-Timestamp: string,
 *         X-Safe-Publish-Content-Hash: string,
 *         X-Safe-Publish-Signature: string,
 *     }
 * }
 */
function safe_publish_fixture_valid_hmac_request(
	string $method,
	string $route,
	string $body,
	string $shared_secret
): array {
	$timestamp      = (string) time();
	$content_hash   = hash( 'sha256', $body );
	$string_to_sign = $method . '|' . $route . '|' . $timestamp . '|' . $content_hash;
	$signature      = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

	return array(
		'method'  => $method,
		'route'   => $route,
		'body'    => $body,
		'headers' => array(
			'X-Safe-Publish-Timestamp'    => $timestamp,
			'X-Safe-Publish-Content-Hash' => $content_hash,
			'X-Safe-Publish-Signature'    => $signature,
		),
	);
}
