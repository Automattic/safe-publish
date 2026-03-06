<?php
/**
 * Fixture: Invalid HMAC Requests.
 *
 * Provides helper functions to build request data arrays that should fail
 * HMAC authentication, covering each distinct failure case.
 *
 * @package Safe_Publish\Tests
 */

declare(strict_types=1);

/**
 * Creates request data with an expired timestamp (more than 5 minutes ago).
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
function safe_publish_fixture_expired_timestamp_request(
	string $method,
	string $route,
	string $body,
	string $shared_secret
): array {
	$timestamp      = (string) ( time() - 301 ); // 5 minutes + 1 second ago.
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

/**
 * Creates request data with an invalid HMAC signature.
 *
 * The timestamp and content hash are valid, but the signature is garbage.
 *
 * @param string $method HTTP method (e.g. 'GET', 'POST').
 * @param string $route  REST API route (e.g. '/wp/v2/posts').
 * @param string $body   Raw request body.
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
function safe_publish_fixture_invalid_signature_request(
	string $method,
	string $route,
	string $body
): array {
	$timestamp    = (string) time();
	$content_hash = hash( 'sha256', $body );

	return array(
		'method'  => $method,
		'route'   => $route,
		'body'    => $body,
		'headers' => array(
			'X-Safe-Publish-Timestamp'    => $timestamp,
			'X-Safe-Publish-Content-Hash' => $content_hash,
			'X-Safe-Publish-Signature'    => 'invalid_signature_that_will_never_match',
		),
	);
}

/**
 * Creates request data with no Safe Publish authentication headers at all.
 *
 * @param string $method HTTP method (e.g. 'GET', 'POST').
 * @param string $route  REST API route (e.g. '/wp/v2/posts').
 * @param string $body   Raw request body.
 * @return array{
 *     method: string,
 *     route: string,
 *     body: string,
 *     headers: array{}
 * }
 */
function safe_publish_fixture_missing_headers_request(
	string $method,
	string $route,
	string $body
): array {
	return array(
		'method'  => $method,
		'route'   => $route,
		'body'    => $body,
		'headers' => array(),
	);
}

/**
 * Creates request data that is missing the X-Safe-Publish-Content-Hash header.
 *
 * The timestamp and signature are otherwise valid.
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
 *         X-Safe-Publish-Signature: string,
 *     }
 * }
 */
function safe_publish_fixture_missing_content_hash_request(
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
			'X-Safe-Publish-Timestamp' => $timestamp,
			'X-Safe-Publish-Signature' => $signature,
			// X-Safe-Publish-Content-Hash intentionally omitted.
		),
	);
}

/**
 * Creates request data where the actual body doesn't match the declared content
 * hash.
 *
 * The content hash header is computed from a tampered version of the body,
 * so the server's recomputed hash will not match.
 *
 * @param string $method        HTTP method (e.g. 'GET', 'POST').
 * @param string $route         REST API route (e.g. '/wp/v2/posts').
 * @param string $body          Raw request body (actual body sent).
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
function safe_publish_fixture_body_mismatch_request(
	string $method,
	string $route,
	string $body,
	string $shared_secret
): array {
	$timestamp      = (string) time();
	$tampered_body  = $body . '_tampered';
	$content_hash   = hash( 'sha256', $tampered_body ); // Hash of different content.
	$string_to_sign = $method . '|' . $route . '|' . $timestamp . '|' . $content_hash;
	$signature      = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

	return array(
		'method'  => $method,
		'route'   => $route,
		'body'    => $body, // Original body — will not match $content_hash.
		'headers' => array(
			'X-Safe-Publish-Timestamp'    => $timestamp,
			'X-Safe-Publish-Content-Hash' => $content_hash,
			'X-Safe-Publish-Signature'    => $signature,
		),
	);
}
