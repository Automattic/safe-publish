<?php declare(strict_types = 1);

// Create a simple mock namespace for test compatibility.
namespace RemoteDataBlocks\Tests\Mocks {
	class MockWordPressFunctions {
		private static $options    = array();
		private static $query_vars = array();

		public static function set_mock_option( string $option, $value ): void {
			self::$options[ $option ] = $value;
		}

		public static function get_option( string $option, $default = false ) {
			return self::$options[ $option ] ?? $default;
		}

		public static function get_query_var( string $var_name, $default_value = null ) {
			return self::$query_vars[ $var_name ] ?? $default_value;
		}

		public static function do_action( string $action, ...$args ): void {
			// Mock implementation.
		}

		public static function apply_filters( string $filter, $thing, ...$args ) {
			return $thing;
		}
	}
}

namespace {
	use RemoteDataBlocks\Tests\Mocks\MockWordPressFunctions;

	function add_action(): void {}
	function add_filter(): void {}

	function do_action( string $action, mixed ...$args ): void {
		MockWordPressFunctions::do_action( $action, ...$args );
	}

	function apply_filters( string $filter, mixed $thing, mixed ...$args ): mixed {
		return MockWordPressFunctions::apply_filters( $filter, $thing, ...$args );
	}

	function esc_html( string $text ): string {
		return $text;
	}

	function esc_html__( string $text ): string {
		return apply_filters( 'esc_html__', $text );
	}

	function register_block_pattern( string $_name, array $_options ): void {
		// Do nothing.
	}

	function is_multisite(): void {
		// Do nothing.
	}

	function plugins_url( string $path ): string {
		return sprintf( 'https://example.com/%s/', $path );
	}

	function sanitize_title( string $title ): string {
		return str_replace( ' ', '-', strtolower( $title ) );
	}

	function sanitize_title_with_dashes( string $title ): string {
		return preg_replace( '/[^a-z0-9-]/', '-', sanitize_title( $title ) );
	}

	function sanitize_text_field( string $text ): string {
		// phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter
		$text = strip_tags( $text );
		$text = trim( $text );
		$text = stripslashes( $text );
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function sanitize_email( string $email ): string {
		$email = trim( $email );
		$email = strtolower( $email );
		return filter_var( $email, FILTER_SANITIZE_EMAIL );
	}

	function sanitize_url( string $url ): string {
		$url = trim( $url );
		$url = filter_var( $url, FILTER_SANITIZE_URL );
		return preg_replace( '/[^-a-zA-Z0-9:_.\/@?&=#%]/', '', $url );
	}

	function __( string $text ): string {
		return $text;
	}

	function wp_strip_all_tags( string $string ): string {
		return $string;
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$parsed = parse_url( $url );
		if ( $component === -1 ) {
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
		return $string ?: '';
	}

	function wp_cache_get(): bool {
		return false;
	}

	function wp_cache_set(): bool {
		return true;
	}

	function wp_rand( int $min = 0 /* ignored $max */ ): int {
		return $min;
	}

	function update_option( string $option, mixed $value ): bool {
		MockWordPressFunctions::set_mock_option( $option, $value );
		return true;
	}

	function get_option( string $option, mixed $default = false ): mixed {
		return MockWordPressFunctions::get_option( $option, $default );
	}

	function get_page_by_path( string $path ): string {
		return $path ?? 'fake WP_Post';
	}

	function get_query_var( string $var_name, mixed $default_value = null ): ?string {
		return MockWordPressFunctions::get_query_var( $var_name, $default_value );
	}

	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000000';
	}

	function is_email( mixed $email ): bool {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
	}

	function wp_is_uuid( mixed $uuid, ?int $version = null ): bool {
		if ( ! is_string( $uuid ) ) {
			return false;
		}

		if ( is_numeric( $version ) ) {
			if ( 4 !== (int) $version ) {
				throw new Exception( esc_html( 'Only UUID V4 is supported at this time.' ) );
			}
			$regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
		} else {
			$regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
		}

		return (bool) preg_match( $regex, $uuid );
	}

	function register_rest_route(): void {}
	function get_bloginfo( string $key ): string {
		return 'http://localhost';
	}
	function get_site_url(): string {
		return 'http://localhost';
	}

	function attachment_url_to_postid( string $url ): int {
		return 0; // Return 0 for tests (not found).
	}

	function esc_url( string $url ): string {
		return $url; // Basic stub for tests.
	}

	function trailingslashit( string $path ): string {
		return rtrim( $path, '/' ) . '/';
	}
	function wp_remote_get( string $url, array $args = array() ): array {
		return array();
	}
	function wp_remote_retrieve_response_code( array $response ): int {
		return 200;
	}
	function wp_remote_retrieve_body( array $response ): string {
		return '';
	}
	function wp_remote_retrieve_headers( array $response ): array {
		return array();
	}
	function current_user_can( string $cap ): bool {
		return true;
	}
	function get_current_user_id(): int {
		return 1;
	}
	function admin_url( string $path ): string {
		return 'http://localhost/wp-admin/' . $path;
	}
	function wp_insert_post( array $args ): int {
		return 1;
	}
	function wp_update_post( array $args ): int {
		return 1;
	}
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		return true;
	}
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return $single ? '' : array();
	}
	function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
		return true;
	}
	function get_post_thumbnail_id( int $post_id ): int {
		return 0;
	}
	function wp_get_attachment_url( int $attachment_id ): string {
		return '';
	}
	function wp_get_attachment_image_url( int $attachment_id, string $size ): string {
		return '';
	}
	function wp_set_post_terms( int $post_id, array $terms, string $taxonomy ): bool {
		return true;
	}
	function taxonomy_exists( string $taxonomy ): bool {
		return true;
	}
	function get_term_by( string $field, string $value, string $taxonomy ) {
		return false;
	}
	function wp_insert_term( string $name, string $taxonomy, array $args = array() ): array {
		return array( 'term_id' => 1 );
	}
	function get_post_taxonomies( int $post_id ): array {
		return array();
	}
	function get_the_terms( int $post_id, string $taxonomy ) {
		return false;
	}
	function post_type_exists( string $post_type ): bool {
		return true;
	}
	function get_posts( array $args ): array {
		return array();
	}
	function download_url( string $url ): string {
		return '/tmp/test-file';
	}
	function media_handle_sideload( array $file_array, int $post_id ): int {
		return 1;
	}
	function wp_check_filetype( string $filename ): array {
		return array(
			'ext'  => 'jpg',
			'type' => 'image/jpeg',
		);
	}
	function get_allowed_mime_types(): array {
		return array(
			'jpg' => 'image/jpeg',
			'png' => 'image/png',
		);
	}
	function includes_url( string $path ): string {
		return 'http://localhost/wp-includes/' . $path;
	}
	function wp_enqueue_script(): void {}
	function wp_register_script(): void {}
	function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
		return false;
	}
	function add_query_arg( array|string $args, string $url = '' ): string {
		if ( empty( $url ) ) {
			return '';
		}
		return $url . '?' . http_build_query( is_array( $args ) ? $args : array() );
	}
	function esc_url_raw( string $url ): string {
		return $url;
	}
	function absint( mixed $value ): int {
		return abs( intval( $value ) );
	}
	function current_time( string $type ): string {
		return date( 'Y-m-d H:i:s' );
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

}

// Close global namespace.
