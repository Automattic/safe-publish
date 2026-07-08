<?php
/**
 * Shared mock for the single-post REST endpoint
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Provides a reusable mock for the WordPress REST API single-post endpoint.
 *
 * Used across test classes to simulate fetch_fresh_post() responses without
 * making real HTTP requests.
 */
trait Mock_Post_API_Trait {

	/**
	 * Per-test overrides for the mocked single-post API response.
	 *
	 * Keys: title, featured_media, content, excerpt, meta, terms, slug,
	 *       comment_status, ping_status, menu_order, password, parent,
	 *       safe_publish_author, acf.
	 * Terms: array keyed by taxonomy slug with arrays of term names as values.
	 * safe_publish_author: array {email, login, display_name}.
	 *
	 * @var array<string, mixed>
	 */
	protected array $mock_post_overrides = array();

	/**
	 * Builds a mock HTTP response for the single-post REST endpoint.
	 *
	 * Applies $this->mock_post_overrides on top of sensible defaults. By
	 * default emits a safe_publish_author payload with the test admin's
	 * credentials so the destination's author resolution can run; tests can
	 * override the payload or omit the field entirely.
	 *
	 * @return array Mock HTTP response array compatible with pre_http_request.
	 */
	protected function build_mock_post_response(): array {
		$body = array(
			'id'             => 1,
			'title'          => array( 'raw' => $this->mock_post_overrides['title'] ?? 'Test Post' ),
			'featured_media' => $this->mock_post_overrides['featured_media'] ?? 0,
			'content'        => array( 'raw' => $this->mock_post_overrides['content'] ?? '<p>Test content.</p>' ),
			'excerpt'        => array( 'raw' => $this->mock_post_overrides['excerpt'] ?? '' ),
			'link'           => 'https://source.example.com/test-post',
			'slug'           => $this->mock_post_overrides['slug'] ?? '',
			'comment_status' => $this->mock_post_overrides['comment_status'] ?? '',
			'ping_status'    => $this->mock_post_overrides['ping_status'] ?? '',
			'menu_order'     => $this->mock_post_overrides['menu_order'] ?? 0,
			'password'       => $this->mock_post_overrides['password'] ?? '',
			'parent'         => $this->mock_post_overrides['parent'] ?? 0,
			'meta'           => $this->mock_post_overrides['meta'] ?? array(),
		);

		$body['safe_publish_author'] = $this->mock_post_overrides['safe_publish_author']
			?? $this->default_safe_publish_author();

		// Top-level acf object, mirroring ACF/SCF's Show in REST API output.
		if ( isset( $this->mock_post_overrides['acf'] ) ) {
			$body['acf'] = $this->mock_post_overrides['acf'];
		}

		if ( ! empty( $this->mock_post_overrides['terms'] ) ) {
			$term_groups = array();
			foreach ( $this->mock_post_overrides['terms'] as $taxonomy => $term_names ) {
				$group = array();
				foreach ( $term_names as $name ) {
					$group[] = array(
						'taxonomy' => $taxonomy,
						'name'     => $name,
					);
				}
				$term_groups[] = $group;
			}
			$body['_embedded'] = array( 'wp:term' => $term_groups );
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Returns the default safe_publish_author payload used when a test does
	 * not provide one.
	 *
	 * Matches the email of the current user (administrator created by
	 * Integration_Test_Case::setUp) so the destination resolver matches by
	 * default and tests that don't care about author resolution don't have to
	 * stage a destination user.
	 *
	 * @return array{email: string, login: string, display_name: string} Default author payload.
	 */
	private function default_safe_publish_author(): array {
		$user = wp_get_current_user();

		return array(
			'email'        => $user instanceof \WP_User ? (string) $user->user_email : '',
			'login'        => $user instanceof \WP_User ? (string) $user->user_login : '',
			'display_name' => $user instanceof \WP_User ? (string) $user->display_name : '',
		);
	}
}
