<?php
/**
 * WordPress function stubs for testing.
 *
 * @package Safe_Publish
 */

declare(strict_types = 1);

/**
 * Global storage for mock posts and meta (used by Import_History tests).
 *
 * @psalm-suppress InvalidGlobal
 */
global $safe_publish_test_posts, $safe_publish_test_post_meta, $safe_publish_test_post_id_counter;
$safe_publish_test_posts           = array();
$safe_publish_test_post_meta       = array();
$safe_publish_test_post_id_counter = 1;

if ( ! function_exists( 'add_action' ) ) {
	function add_action(): void {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $filter, mixed $thing, mixed ...$args ): mixed {
		return $thing;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
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
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$string = json_encode( $data );
		return $string ? $string : false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $default;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $key ): string {
		return 'http://localhost';
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url(): string {
		return 'http://localhost';
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( string $url ): int {
		return 0; // Return 0 for tests (not found).
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/' ) . '/';
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @psalm-suppress InvalidReturnStatement
	 */
	function wp_remote_get( string $url, array $args = array() ): array {
		return array();
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return 200;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

// =========================================================================
// Import_History test stubs
// =========================================================================

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $args ) {
		global $safe_publish_test_posts, $safe_publish_test_post_meta, $safe_publish_test_post_id_counter;

		$post_id = $safe_publish_test_post_id_counter++;

		$safe_publish_test_posts[ $post_id ] = (object) array_merge(
			array(
				'ID'           => $post_id,
				'post_title'   => '',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_parent'  => 0,
				'post_author'  => 1,
				'post_excerpt' => '',
			),
			$args,
			array( 'ID' => $post_id )
		);

		// Handle meta_input.
		if ( isset( $args['meta_input'] ) && is_array( $args['meta_input'] ) ) {
			if ( ! isset( $safe_publish_test_post_meta[ $post_id ] ) ) {
				$safe_publish_test_post_meta[ $post_id ] = array();
			}
			foreach ( $args['meta_input'] as $key => $value ) {
				$safe_publish_test_post_meta[ $post_id ][ $key ] = $value;
			}
		}

		return $post_id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $args, bool $wp_error = false ) {
		global $safe_publish_test_posts;

		$post_id = $args['ID'] ?? 0;
		if ( ! isset( $safe_publish_test_posts[ $post_id ] ) ) {
			return $wp_error ? new \WP_Error( 'invalid_post', 'Post not found' ) : 0;
		}

		foreach ( $args as $key => $value ) {
			if ( 'ID' !== $key ) {
				$safe_publish_test_posts[ $post_id ]->$key = $value;
			}
		}

		return $post_id;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id = null ) {
		global $safe_publish_test_posts;

		if ( null === $post_id || 0 === $post_id ) {
			return null;
		}

		/** @psalm-suppress EmptyArrayAccess */
		return $safe_publish_test_posts[ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force_delete = false ) {
		global $safe_publish_test_posts, $safe_publish_test_post_meta;

		if ( ! isset( $safe_publish_test_posts[ $post_id ] ) ) {
			return false;
		}

		/** @psalm-suppress EmptyArrayAccess */
		$post = $safe_publish_test_posts[ $post_id ];
		unset( $safe_publish_test_posts[ $post_id ] );
		/** @psalm-suppress EmptyArrayAccess */
		unset( $safe_publish_test_post_meta[ $post_id ] );

		return $post;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $meta_key = '', bool $single = true ) {
		global $safe_publish_test_post_meta;

		if ( isset( $safe_publish_test_post_meta[ $post_id ][ $meta_key ] ) ) {
			return $safe_publish_test_post_meta[ $post_id ][ $meta_key ];
		}

		return $single ? '' : array();
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, $meta_value ) {
		global $safe_publish_test_post_meta;

		if ( ! isset( $safe_publish_test_post_meta[ $post_id ] ) ) {
			$safe_publish_test_post_meta[ $post_id ] = array();
		}
		$safe_publish_test_post_meta[ $post_id ][ $meta_key ] = $meta_value;

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $meta_key ): bool {
		global $safe_publish_test_post_meta;

		if ( isset( $safe_publish_test_post_meta[ $post_id ][ $meta_key ] ) ) {
			unset( $safe_publish_test_post_meta[ $post_id ][ $meta_key ] );
		}

		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return gmdate( $type );
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( string $post_type, array $args = array() ) {
		return (object) array_merge( array( 'name' => $post_type ), $args );
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null ) {
		return $menu_slug;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action, string $query_arg = 'nonce', bool $die = true ) {
		return 1;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, int $status_code = 200 ): void {
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, int $status_code = 200 ): void {
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = array() ): array {
		global $safe_publish_test_posts, $safe_publish_test_post_meta;

		$posts          = array();
		$post_type      = $args['post_type'] ?? 'post';
		$post_parent    = $args['post_parent'] ?? null;
		$posts_per_page = $args['posts_per_page'] ?? -1;

		foreach ( $safe_publish_test_posts as $post ) {
			if ( $post->post_type !== $post_type ) {
				continue;
			}

			if ( null !== $post_parent && $post->post_parent !== $post_parent ) {
				continue;
			}

			if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) && ! empty( $args['meta_query'] ) ) {
				$matches = true;
				foreach ( $args['meta_query'] as $meta_query ) {
					if ( ! is_array( $meta_query ) || ! isset( $meta_query['key'] ) ) {
						continue;
					}

					$meta_key   = $meta_query['key'];
					$meta_value = $meta_query['value'] ?? null;
					$compare    = $meta_query['compare'] ?? '=';

					$actual_value = $safe_publish_test_post_meta[ $post->ID ][ $meta_key ] ?? '';

					if ( 'IN' === $compare && is_array( $meta_value ) ) {
						if ( ! in_array( $actual_value, $meta_value, true ) ) {
							$matches = false;
							break;
						}
					} elseif ( $actual_value !== $meta_value ) {
						$matches = false;
						break;
					}
				}

				if ( ! $matches ) {
					continue;
				}
			}

			$posts[] = $post;

			if ( -1 !== $posts_per_page && count( $posts ) >= $posts_per_page ) {
				break;
			}
		}

		return $posts;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( string $format = '', $post = null ): string {
		$date_format = '' !== $format ? $format : 'Y-m-d';
		return gmdate( $date_format );
	}
}

if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( string $field, int $user_id = 0 ): string {
		if ( 'display_name' === $field ) {
			return 'Test User';
		}
		return '';
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ) {
		return update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
	}
}

if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int $post_id ): bool {
		return delete_post_meta( $post_id, '_thumbnail_id' );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string $message = '' ): void {
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle ): void {
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), string $ver = '', string $media = 'all' ): void {
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'test_nonce_' . md5( $action );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'http://localhost/wp-content/plugins/safe-publish/';
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_data() {
			return $this->data;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Query {
		public array $posts       = array();
		private array $query_vars = array();

		public function __construct( array $args = array() ) {
			$this->query_vars = $args;
			$this->posts      = get_posts( $args );
		}

		public function have_posts(): bool {
			return ! empty( $this->posts );
		}

		public function the_post(): void {
		}
	}
}
