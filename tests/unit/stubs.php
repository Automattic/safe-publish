<?php
/**
 * WordPress function stubs for testing.
 *
 * @package Safe_Publish
 */

declare(strict_types = 1);

function add_action(): void {}

function apply_filters( string $filter, mixed $thing, mixed ...$args ): mixed {
	return $thing;
}

function __( string $text ): string {
	return $text;
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof \WP_Error;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$parsed = parse_url( $url );
	if ( -1 === $component ) {
		return $parsed;
	}

	// Return specific component (PHP_URL_HOST = 1, etc.).
	if ( is_array( $parsed ) ) {
		$map = array(
			1 => 'host',
			2 => 'scheme',
			3 => 'port',
			5 => 'path',
			6 => 'query',
			7 => 'fragment',
		);

		return $parsed[ $map[ $component ] ] ?? null;
	}

	return null;
}

function wp_json_encode( mixed $data ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	$string = json_encode( $data );
	return $string ? $string : '';
}

function get_option( string $option, mixed $default = false ): mixed {
	return $default;
}

function get_bloginfo( string $key ): string {
	return 'http://localhost';
}

function get_site_url(): string {
	return 'http://localhost';
}

function attachment_url_to_postid( string $url ): int {
	return 0; // Return 0 for tests (not found).
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/' ) . '/';
}

/**
 * @psalm-suppress InvalidReturnStatement
 */
function wp_remote_get( string $url, array $args = array() ): array {
	return array();
}

function wp_remote_retrieve_response_code( array $response ): int {
	return 200;
}

function esc_url_raw( string $url ): string {
	return $url;
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

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
