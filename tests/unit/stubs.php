<?php
/**
 * WordPress function stubs for testing.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

function add_action(): void {}

function apply_filters( string $filter, mixed $thing, mixed ...$args ): mixed {
	return $thing;
}

function __( string $text ): string {
	return $text;
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$parsed = parse_url( $url );
	if ( -1 === $component ) {
		return $parsed;
	}

	// Return specific component. Indexes correspond to PHP's PHP_URL_*
	// constants: SCHEME=0, HOST=1, PORT=2, USER=3, PASS=4, PATH=5,
	// QUERY=6, FRAGMENT=7.
	if ( is_array( $parsed ) ) {
		$map = array(
			0 => 'scheme',
			1 => 'host',
			2 => 'port',
			3 => 'user',
			4 => 'pass',
			5 => 'path',
			6 => 'query',
			7 => 'fragment',
		);

		return $parsed[ $map[ $component ] ?? '' ] ?? null;
	}

	return null;
}

function wp_json_encode( mixed $data ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	$string = json_encode( $data );
	return $string ? $string : '';
}

function get_option( string $option, mixed $default = false ): mixed {
	if ( isset( $GLOBALS['_test_options'][ $option ] ) ) {
		return $GLOBALS['_test_options'][ $option ];
	}

	return $default;
}

function set_test_option( string $option, mixed $value ): void {
	$GLOBALS['_test_options'][ $option ] = $value;
}

function reset_test_options(): void {
	$GLOBALS['_test_options'] = array();
}

/**
 * Sets or clears an environment variable for a test.
 *
 * @param string      $name  Environment variable name.
 * @param string|null $value Value to set, or null to unset the variable.
 */
function set_test_env( string $name, ?string $value ): void {
	$assignment = null === $value ? $name : "{$name}={$value}";
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
	putenv( $assignment );
}

function get_bloginfo( string $key ): string {
	return 'http://localhost';
}

function get_site_url(): string {
	return 'http://localhost';
}

function home_url( string $path = '' ): string {
	return 'http://localhost' . $path;
}

function attachment_url_to_postid( string $url ): int {
	return 0; // Return 0 for tests (not found).
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/' ) . '/';
}

function untrailingslashit( string $path ): string {
	return rtrim( $path, '/\\' );
}

function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
	$GLOBALS['_test_http_last_url']  = $url;
	$GLOBALS['_test_http_last_args'] = $args;

	$default = array( 'response' => array( 'code' => 200 ) );
	return $GLOBALS['_test_http_response'] ?? $default;
}

function wp_remote_retrieve_response_code( array|WP_Error $response ): int {
	if ( $response instanceof WP_Error ) {
		return 0;
	}

	return (int) ( $response['response']['code'] ?? 0 );
}

function set_test_http_response( array|WP_Error $response ): void {
	$GLOBALS['_test_http_response'] = $response;
}

function reset_test_http_response(): void {
	unset(
		$GLOBALS['_test_http_response'],
		$GLOBALS['_test_http_last_url'],
		$GLOBALS['_test_http_last_args']
	);
}

function add_query_arg( array $args, string $url ): string {
	$separator = ( strpos( $url, '?' ) === false ) ? '?' : '&';
	return $url . $separator . http_build_query( $args );
}

function esc_url_raw( string $url ): string {
	return $url;
}

function esc_url( string $url ): string {
	return $url;
}

function esc_html( string $text ): string {
	return $text;
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private mixed $data = null
	) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}
