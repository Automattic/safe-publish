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
	 *       comment_status, ping_status, menu_order, password.
	 * Terms: array keyed by taxonomy slug with arrays of term names as values.
	 *
	 * @var array<string, mixed>
	 */
	protected array $mock_post_overrides = array();

	/**
	 * Builds a mock HTTP response for the single-post REST endpoint.
	 *
	 * Applies $this->mock_post_overrides on top of sensible defaults.
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
			'meta'           => $this->mock_post_overrides['meta'] ?? array(),
		);

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
}
