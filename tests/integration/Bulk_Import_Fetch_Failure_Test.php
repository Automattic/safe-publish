<?php
/**
 * Bulk import fetch-failure surfacing integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;
use WP_Error;
use WP_Post;

/**
 * Bulk Import Fetch Failure Test.
 *
 * Confirms the bulk path surfaces the specific fetch-failure reason per item,
 * matching the single import path.
 */
class Bulk_Import_Fetch_Failure_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;
	use Bulk_Import_Ajax_Trait;

	/**
	 * Source ID whose REST body omits the raw edit-context fields.
	 */
	private const RAW_MISSING_SOURCE_ID = 4242;

	/**
	 * Source ID whose REST body has an absent field with unknown support.
	 */
	private const INFERRED_MISSING_SOURCE_ID = 4243;

	/**
	 * Source ID whose endpoint returns an HTTP error.
	 */
	private const HTTP_ERROR_SOURCE_ID = 4343;

	/**
	 * Sets up the bulk-import harness.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->set_up_bulk_import_harness( 'https://source.example.com' );
	}

	/**
	 * Tears down the bulk-import harness.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->tear_down_bulk_import_harness();
		parent::tearDown();
	}

	/**
	 * Returns a source body missing the raw edit-context fields, so the fetch
	 * fails with the raw-fields-missing reason.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( self::INFERRED_MISSING_SOURCE_ID === $source_id ) {
			$user = wp_get_current_user();

			return array(
				'id'                  => $source_id,
				'type'                => 'page',
				'title'               => array( 'raw' => 'Source title' ),
				'content'             => array( 'raw' => '<p>Source content.</p>' ),
				'link'                => 'https://source.example.com/post-' . $source_id,
				'meta'                => array(),
				'safe_publish_author' => array(
					'email'        => $user->user_email,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
				),
			);
		}

		if ( self::RAW_MISSING_SOURCE_ID !== $source_id ) {
			return null;
		}

		return array(
			'id'      => $source_id,
			'title'   => array( 'rendered' => 'Rendered Title' ),
			'content' => array( 'rendered' => '<p>Rendered content.</p>' ),
			'excerpt' => array( 'rendered' => '<p>Rendered excerpt.</p>' ),
			'link'    => 'https://source.example.com/post-' . $source_id,
			'meta'    => array(),
		);
	}

	/**
	 * Verifies that bulk update rejects unavailable catalog metadata without
	 * overwriting destination content.
	 */
	public function test_bulk_update_rejects_unavailable_catalog(): void {
		// ARRANGE: An imported page has non-empty destination fields, while its
		// source response omits excerpt and its catalog is unavailable.
		$post_id         = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Destination title',
				'post_content' => '<p>Destination content.</p>',
				'post_excerpt' => 'Destination excerpt',
				'meta_input'   => array(
					Options::META_SOURCE_POST_ID  =>
						self::INFERRED_MISSING_SOURCE_ID,
					Options::META_SOURCE_SITE_URL =>
						'https://source.example.com',
				),
			)
		);
		$catalog_failure = static function (
			false|array|WP_Error $preempt,
			array $_args,
			string $url
		): false|array|WP_Error {
			if ( ! str_contains( $url, '/catalog/post-types' ) ) {
				return $preempt;
			}

			return new WP_Error( 'transport_down', 'Catalog unavailable.' );
		};
		add_filter( 'pre_http_request', $catalog_failure, 0, 3 );

		try {
			// ACT: Dispatch the bulk update.
			$data = $this->dispatch_bulk_import(
				array(
					array(
						'id'        => self::INFERRED_MISSING_SOURCE_ID,
						'title'     => 'Snapshot title',
						'link'      => 'https://source.example.com/post-'
							. self::INFERRED_MISSING_SOURCE_ID,
						'post_type' => 'pages',
					),
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $catalog_failure, 0 );
		}

		// ASSERT: The item fails and every destination field remains intact.
		$this->assertSame( 0, $data['successful'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertStringContainsString(
			'catalog could not be retrieved',
			$data['results'][0]['error']
		);
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Destination title', $post->post_title );
		$this->assertSame( '<p>Destination content.</p>', $post->post_content );
		$this->assertSame( 'Destination excerpt', $post->post_excerpt );
	}

	/**
	 * Verifies that a bulk item whose source response lacks the raw
	 * edit-context fields fails with the specific raw-fields reason, matching
	 * the single import path's error text.
	 */
	public function test_bulk_item_surfaces_raw_fields_missing_reason(): void {
		// ARRANGE: A single bulk entry whose source body omits raw fields.
		$posts_data = array(
			array(
				'id'        => self::RAW_MISSING_SOURCE_ID,
				'title'     => 'Rendered Title',
				'link'      => 'https://source.example.com/post-'
					. self::RAW_MISSING_SOURCE_ID,
				'post_type' => 'pages',
			),
		);

		// ACT: Dispatch the bulk import.
		$data = $this->dispatch_bulk_import( $posts_data );

		// ASSERT: The item failed and surfaced the specific raw-fields reason
		// rather than a generic fetch-failed message.
		$this->assertSame( 0, $data['successful'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertStringContainsString(
			'missing required raw values',
			$data['results'][0]['error']
		);
	}

	/**
	 * Verifies that a bulk item preserves structured source HTTP error data.
	 */
	public function test_bulk_item_preserves_source_http_error_data(): void {
		// ARRANGE: Intercept this post before the standard source mock.
		$upstream_message = 'The source post is private.';
		$failing_request  = static function (
			$preempt,
			$_args,
			string $url,
		) use (
			$upstream_message
		) {
			if ( ! str_contains( $url, '/' . self::HTTP_ERROR_SOURCE_ID ) ) {
				return $preempt;
			}

			return array(
				'response' => array(
					'code'    => 401,
					'message' => 'Unauthorized',
				),
				'body'     => (string) wp_json_encode(
					array( 'message' => $upstream_message )
				),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $failing_request, 0, 3 );

		$posts_data = array(
			array(
				'id'        => self::HTTP_ERROR_SOURCE_ID,
				'title'     => 'Private post',
				'link'      => 'https://source.example.com/private-post',
				'post_type' => 'posts',
			),
		);

		try {
			// ACT: Dispatch the bulk import.
			$data = $this->dispatch_bulk_import( $posts_data );

			// ASSERT: The item carries the structured detail needed by the UI.
			$this->assertFalse( $data['results'][0]['success'] );
			$this->assertSame(
				'Source site returned HTTP error 401. ' . $upstream_message,
				$data['results'][0]['error']
			);
			$this->assertSame(
				array(
					'message'  => $upstream_message,
					'template' =>
						'Source site returned HTTP error 401. <reason />',
				),
				$data['results'][0]['source_error']
			);
		} finally {
			remove_filter( 'pre_http_request', $failing_request, 0 );
		}
	}
}
