<?php
/**
 * HTTP Client service for making external requests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Validators\URL_Validator;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP Client Class.
 *
 * Provides a centralized service for making HTTP requests with authentication
 * handling and error management.
 */
final class HTTP_Client {

	/**
	 * Caps the buffered response body so a compromised or malfunctioning source
	 * cannot exhaust the worker's memory; make_request() calls can adjust it
	 * through the safe_publish_request_args filter.
	 */
	public const MAX_RESPONSE_BYTES = 10 * MB_IN_BYTES;

	/**
	 * WP_Error code returned when a source response exceeds the size cap.
	 */
	public const ERROR_RESPONSE_TOO_LARGE = 'response_too_large';

	/**
	 * WP_Error code returned when the request never reached the source site.
	 */
	public const ERROR_REQUEST_FAILED = 'request_failed';

	/**
	 * WP_Error code returned when a media download stops at a redirect the
	 * plugin does not follow.
	 */
	public const ERROR_DOWNLOAD_REDIRECTED = 'download_redirected';

	/**
	 * Error-data key carrying structured source failure detail.
	 */
	public const ERROR_DATA_SOURCE_ERROR = 'source_error';

	/**
	 * Caps the source-supplied detail appended to a surfaced error message,
	 * keeping it a sane length for display.
	 */
	private const MAX_ERROR_DETAIL_LENGTH = 300;

	/**
	 * Redirects followed while downloading a file. A source site that
	 * upgrades the scheme and then canonicalizes the path spends two of
	 * them; past this budget the chain is treated as a redirect loop.
	 */
	private const MAX_DOWNLOAD_REDIRECTS = 3;

	/**
	 * Makes an HTTP request. $action is sent as X-Safe-Publish-Action and
	 * signed into the HMAC payload by VIP_Safe_Auth::get_auth_params().
	 *
	 * @param string $url              Request URL.
	 * @param string $action           Declared request action (see Request_Actions).
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @param array  $additional_args  Optional. Additional request arguments. Default empty array.
	 * @return array|WP_Error Response or error.
	 */
	public function make_request(
		string $url,
		string $action,
		array $auth_credentials = array(),
		array $additional_args = array()
	): array|WP_Error {
		// Default request timeout in seconds (filterable).
		$timeout = apply_filters( 'safe_publish_request_timeout', 10 );

		// Determine SSL verification based on environment.
		$sslverify = $this->should_verify_ssl( $url );

		$request_args = array_merge(
			array(
				'timeout'             => $timeout,
				'user-agent'          => $this->get_user_agent(),
				'sslverify'           => $sslverify,
				'redirection'         => 0, // Prevent redirects for security.
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
			),
			$additional_args
		);

		// Apply HMAC auth headers; Basic Auth layers on top when configured.
		$body        = $request_args['body'] ?? '';
		$auth_params = VIP_Safe_Auth::get_auth_params(
			$url,
			$action,
			$auth_credentials,
			'GET',
			$body
		);

		// Add authentication headers if available.
		if ( ! empty( $auth_params['headers'] ) ) {
			$request_args['headers'] = array_merge(
				$request_args['headers'] ?? array(),
				$auth_params['headers']
			);
		}

		// Add query parameters for authentication if needed.
		if ( ! empty( $auth_params['query_args'] ) ) {
			$url = add_query_arg( $auth_params['query_args'], $url );
		}

		/**
		 * Filters request arguments.
		 *
		 * @param array  $request_args Request arguments.
		 * @param string $url          Request URL.
		 */
		$request_args = apply_filters( 'safe_publish_request_args', $request_args, $url );

		$response = $this->safe_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$source_message = $response->get_error_message();
			/* translators: %s: transport error reported by WordPress. */
			$message_template = __(
				'Failed to fetch data from source site. %s',
				'safe-publish'
			);

			return new WP_Error(
				self::ERROR_REQUEST_FAILED,
				sprintf( $message_template, $source_message ),
				array(
					self::ERROR_DATA_SOURCE_ERROR => array(
						'message'  => $source_message,
						'template' => sprintf(
							$message_template,
							'<reason />'
						),
					),
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$source_error = $this->parse_source_error(
				wp_remote_retrieve_body( $response )
			);

			$error_data = array();
			if ( isset( $source_error['message'] ) ) {
				/* translators: 1: HTTP response code, 2: source site reason. */
				$message_template                            = __(
					'Source site returned HTTP error %1$d. %2$s',
					'safe-publish'
				);
				$message                                     = sprintf(
					$message_template,
					$response_code,
					$source_error['message']
				);
				$error_data[ self::ERROR_DATA_SOURCE_ERROR ] = array(
					'message'  => $source_error['message'],
					'template' => sprintf(
						$message_template,
						$response_code,
						'<reason />'
					),
				);
			} else {
				$message = sprintf(
					/* translators: %d: HTTP response code */
					__( 'Source site returned HTTP error %d.', 'safe-publish' ),
					$response_code
				);
			}

			if ( isset( $source_error['code'] ) ) {
				$error_data['source_code'] = $source_error['code'];
			}
			if ( isset( $source_error['status'] ) ) {
				$error_data['source_status'] = $source_error['status'];
			}

			return new WP_Error(
				'http_error',
				$message,
				array() === $error_data ? null : $error_data
			);
		}

		// The transport truncates the body at limit_response_size; a body that
		// reaches the cap means the source sent an oversized response.
		$size_limit = $request_args['limit_response_size'] ?? 0;
		if (
			is_int( $size_limit ) && $size_limit > 0
			&& strlen( wp_remote_retrieve_body( $response ) ) >= $size_limit
		) {
			return new WP_Error(
				self::ERROR_RESPONSE_TOO_LARGE,
				sprintf(
					/* translators: %s: maximum response size, e.g. "10 MB". */
					__( 'The source site returned a response larger than the maximum allowed size (%s).', 'safe-publish' ),
					size_format( $size_limit )
				)
			);
		}

		return $response;
	}

	/**
	 * Extracts the message, code, and status from a WordPress REST error body.
	 *
	 * Returns an empty array unless the body decodes to a JSON object, so a
	 * non-JSON or empty body degrades to the generic HTTP-error message.
	 *
	 * @param string $body Raw response body.
	 * @return array Any of 'message', 'code', and 'status' the body carried.
	 */
	private function parse_source_error( string $body ): array {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$parsed = array();

		$message = $decoded['message'] ?? null;
		if ( is_string( $message ) ) {
			$detail = trim( $message );
			if ( '' !== $detail ) {
				$parsed['message'] = $this->truncate_error_detail( $detail );
			}
		}

		$code = $decoded['code'] ?? null;
		if ( is_string( $code ) && '' !== $code ) {
			$parsed['code'] = $code;
		}

		$data   = $decoded['data'] ?? null;
		$status = is_array( $data ) ? ( $data['status'] ?? null ) : null;
		if ( is_int( $status ) ) {
			$parsed['status'] = $status;
		}

		return $parsed;
	}

	/**
	 * Bounds a source error detail to a display-friendly length.
	 *
	 * Truncates on a UTF-8 character boundary so the cut can never leave a
	 * partial multibyte sequence, which would make the surfaced message
	 * invalid UTF-8 (dropped by esc_html, rejected by json_encode).
	 *
	 * @param string $detail Error detail to bound.
	 * @return string Detail unchanged, or truncated with an ellipsis.
	 */
	public function truncate_error_detail( string $detail ): string {
		$pattern = sprintf( '/^.{0,%d}/su', self::MAX_ERROR_DETAIL_LENGTH );
		preg_match( $pattern, $detail, $matches );
		$truncated = $matches[0] ?? substr( $detail, 0, self::MAX_ERROR_DETAIL_LENGTH );

		return $truncated === $detail ? $detail : $truncated . '…';
	}

	/**
	 * Gets user agent string.
	 *
	 * @return string User agent string.
	 */
	public function get_user_agent(): string {
		$plugin_version = defined( 'SAFE_PUBLISH_VERSION' ) ? SAFE_PUBLISH_VERSION : '0.0.1';
		$site_url       = get_bloginfo( 'url' );

		return sprintf(
			'Safe Publish/%s; %s',
			$plugin_version,
			$site_url
		);
	}

	/**
	 * Extracts the destination site URL from a Safe Publish User-Agent string.
	 *
	 * The destination sends "Safe Publish/VERSION; URL"; this returns the URL
	 * portion, falling back to the full string when the format is unexpected.
	 *
	 * @param string $user_agent Raw User-Agent value.
	 * @return string Destination URL, or '' when the header is absent.
	 */
	public static function parse_destination_site_url( string $user_agent ): string {
		if ( '' === $user_agent ) {
			return '';
		}

		$parts = explode( '; ', $user_agent, 2 );

		return isset( $parts[1] ) ? trim( $parts[1] ) : $user_agent;
	}

	/**
	 * Makes a safe remote GET request.
	 *
	 * Non-VIP environments are routed through `wp_safe_remote_get` so the
	 * core `http_request_host_is_external` chain rejects loopback,
	 * link-local, and unique-local addresses unless an integration
	 * explicitly opts in.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional. Request arguments. Default empty array.
	 * @return array|WP_Error Response or error.
	 */
	public function safe_remote_get( string $url, array $args = array() ): array|WP_Error {
		// Use VIP-optimized function when available, fallback to core function.
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, 5, 20, $args );
		}

		return wp_safe_remote_get( $url, $args );
	}

	/**
	 * Determines whether to verify SSL certificates based on environment and URL.
	 *
	 * @param string $url URL being requested.
	 * @return bool Whether to verify SSL certificates.
	 */
	public function should_verify_ssl( string $url ): bool {
		// Always verify SSL in VIP production environments.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && constant( 'WPCOM_IS_VIP_ENV' ) ) {
			return true;
		}

		// Parse URL to check for development indicators.
		$parsed_url = wp_parse_url( $url );
		$host       = $parsed_url['host'] ?? '';

		// Development domains where SSL verification can be disabled.
		$dev_domains = array(
			'.test',
			'.local',
			'.dev',
			'localhost',
			'127.0.0.1',
			'::1',
		);

		// Check if this is a development domain.
		foreach ( $dev_domains as $dev_domain ) {
			if ( $host === $dev_domain ||
				( function_exists( 'str_ends_with' ) && str_ends_with( $host, $dev_domain ) ) ||
				( ! function_exists( 'str_ends_with' ) && substr( $host, -strlen( $dev_domain ) ) === $dev_domain ) ) {
				// Allow filtering for specific development needs.
				return apply_filters( 'safe_publish_dev_ssl_verify', false, $url );
			}
		}

		// For production domains, always verify SSL.
		return true;
	}

	/**
	 * Cleans up a temporary file.
	 *
	 * @param string $temp_file Path to temporary file.
	 */
	public function cleanup_temp_file( string $temp_file ): void {
		if ( empty( $temp_file ) || is_wp_error( $temp_file ) ) {
			return;
		}

		// Only attempt cleanup if file exists and is a temp file.
		if ( file_exists( $temp_file ) && strpos( $temp_file, '/tmp/' ) !== false ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Temp file cleanup after media import, file is in /tmp/ directory
			unlink( $temp_file );
		}
	}

	/**
	 * Downloads a file, following only redirects that stay on the host and
	 * port its URL names.
	 *
	 * The transport is told not to follow redirects at all, matching the
	 * policy make_request() applies to REST traffic, and each redirect is
	 * resolved here instead. Core revalidates a redirect target but accepts
	 * any public host, whereas callers only ever approve a media URL by its
	 * host, so the bytes have to come from the host that URL names. An http
	 * URL upgraded to https is followed; the reverse is not.
	 *
	 * @param string $url File URL.
	 * @return string|WP_Error Path to the downloaded file, or WP_Error on
	 *                         failure.
	 */
	public function download_file( string $url ): string|WP_Error {
		$target = $url;

		// One pass for the download itself, plus one per redirect followed.
		for ( $pass = 0; $pass <= self::MAX_DOWNLOAD_REDIRECTS; $pass++ ) {
			$location = null;
			$result   = $this->download_without_redirects( $target, $location );

			if ( ! is_wp_error( $result ) || null === $location ) {
				return $result;
			}

			$next = $this->resolve_pinned_redirect( $target, $location );

			if ( null === $next ) {
				return new WP_Error(
					self::ERROR_DOWNLOAD_REDIRECTED,
					__(
						'This file redirects off the host its URL names.',
						'safe-publish'
					)
				);
			}

			$target = $next;
		}

		return new WP_Error(
			self::ERROR_DOWNLOAD_REDIRECTED,
			__(
				'The source site redirected this file too many times.',
				'safe-publish'
			)
		);
	}

	/**
	 * Downloads a URL with redirects turned off, reporting the redirect the
	 * source answered with.
	 *
	 * Core's download_url() takes no redirection argument, so the policy is
	 * applied through an http_request_args filter matched to this URL, and a
	 * companion http_response filter reads the Location off the redirect core
	 * discards. Both are removed before returning, so no other request can
	 * pick them up.
	 *
	 * @param string      $url      File URL.
	 * @param string|null $location Set, by reference, to the Location the
	 *                              source answered with, or null when the
	 *                              response was not a redirect.
	 * @return string|WP_Error Path to the downloaded file, or WP_Error on
	 *                         failure.
	 */
	private function download_without_redirects(
		string $url,
		?string &$location = null
	): string|WP_Error {
		$location = null;

		$refuse_redirects = static function (
			mixed $args,
			string $request_url
		) use ( $url ): mixed {
			if ( is_array( $args ) && $request_url === $url ) {
				$args['redirection'] = 0;
			}

			return $args;
		};

		$read_location = static function (
			mixed $response,
			mixed $_args,
			string $request_url
		) use (
			$url,
			&$location
		): mixed {
			if ( ! is_array( $response ) || $request_url !== $url ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code > 399 ) {
				return $response;
			}

			$header = wp_remote_retrieve_header( $response, 'location' );
			if ( is_string( $header ) && '' !== $header ) {
				$location = $header;
			}

			return $response;
		};

		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Matched to this URL, removed below, and sets redirection only.
		add_filter( 'http_request_args', $refuse_redirects, 10, 2 );
		add_filter( 'http_response', $read_location, 10, 3 );

		try {
			// Core's download_url() streams the body to a temp file.
			return download_url( $url );
		} finally {
			remove_filter( 'http_request_args', $refuse_redirects, 10 );
			remove_filter( 'http_response', $read_location, 10 );
		}
	}

	/**
	 * Resolves a redirect Location against the URL it came from, keeping only
	 * a target the same host and port serve.
	 *
	 * A relative Location cannot leave the host, so it resolves against the
	 * requested URL's scheme, host, and port. An absolute Location has to
	 * name that same host and port, and an https URL is never followed down
	 * to plain http.
	 *
	 * @param string $url      URL that was requested.
	 * @param string $location Location header the source answered with.
	 * @return string|null Absolute URL on the same host and port, or null when
	 *                     the redirect leaves either.
	 */
	private function resolve_pinned_redirect(
		string $url,
		string $location
	): ?string {
		$origin = URL_Validator::normalize_site_url( $url );
		if ( '' === $origin ) {
			return null;
		}

		$target = URL_Validator::resolve_relative_url( $location, $origin );
		if ( ! URL_Validator::is_absolute_http_url( $target ) ) {
			return null;
		}

		$authority        = $this->url_authority( $url );
		$target_authority = $this->url_authority( $target );

		if ( $target_authority !== $authority ) {
			return null;
		}

		// A scheme upgrade is the common redirect; a downgrade is not.
		$scheme        = $this->url_scheme( $url );
		$target_scheme = $this->url_scheme( $target );

		if ( 'https' === $scheme && 'https' !== $target_scheme ) {
			return null;
		}

		return $target;
	}

	/**
	 * Reads a URL's host and explicit port, lower cased for comparison.
	 *
	 * @param string $url URL to read.
	 * @return string Host, with ":port" appended when the URL names one.
	 */
	private function url_authority( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port = wp_parse_url( $url, PHP_URL_PORT );

		return is_int( $port ) ? $host . ':' . $port : $host;
	}

	/**
	 * Reads a URL's scheme, lower cased for comparison.
	 *
	 * @param string $url URL to read.
	 * @return string Scheme in lower case, or '' when the URL carries none.
	 */
	private function url_scheme( string $url ): string {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	}
}
