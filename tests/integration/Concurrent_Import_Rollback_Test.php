<?php
/**
 * Concurrent import and rollback integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Post_Lock;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Concurrent Import Rollback Test Class.
 *
 * Simulates another request on the same post: A rival operation runs from a
 * hook inside the first one.
 */
class Concurrent_Import_Rollback_Test extends Integration_Test_Case {

	use Mock_Post_API_Trait;

	/**
	 * Source post ID every import here uses.
	 */
	private const SOURCE_ID = 8300;

	/**
	 * Post import service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Rollback service instance.
	 *
	 * @var Session_Rollback_Service
	 */
	private Session_Rollback_Service $rollback_service;

	/**
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository       = new History_Repository();
		$this->rollback_service = new Session_Rollback_Service(
			$this->repository
		);

		$http_client       = new HTTP_Client();
		$media_importer    = new Media_Importer( $http_client );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( $http_client ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);

		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source.example.com'
		);

		add_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1, 3 );
	}

	/**
	 * Tears down test dependencies.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Intercepts HTTP requests to the single-post REST endpoint.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Mocked response or prior value.
	 */
	public function mock_post_api(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}
		if ( str_contains( $url, '/catalog/post-types' ) ) {
			return \_safe_publish_test_catalog_response();
		}
		if ( ! preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return $preempt;
		}

		return $this->build_mock_post_response();
	}

	/**
	 * Provides points between an update's first read of the post and its
	 * history write.
	 *
	 * @return array<string, array{string, callable}> Hook and a matcher for
	 *                                                the update's own call.
	 */
	public static function update_pause_point_provider(): array {
		return array(
			'claims read'  => array(
				'query',
				static fn ( array $args ): bool => str_contains(
					(string) $args[0],
					Options::META_SOURCE_POST_ID
				),
			),
			'source fetch' => array(
				'pre_http_request',
				static fn ( array $args ): bool => 1 === preg_match(
					'#/wp-json/wp/v2/posts/\d+#',
					(string) $args[2]
				),
			),
			'post written' => array(
				'update_post_metadata',
				static fn ( array $args ): bool =>
					Options::META_SOURCE_LINK === $args[2],
			),
		);
	}

	/**
	 * Verifies that a rollback started while an update is in flight is
	 * refused, so the update lands whole and its history describes the post.
	 *
	 * @dataProvider update_pause_point_provider
	 *
	 * @param string   $hook    Hook the update fires at the pause point.
	 * @param callable $matches Picks the update's own call of the hook.
	 */
	public function test_rollback_during_an_update_is_refused(
		string $hook,
		callable $matches
	): void {
		// ARRANGE: A created post, updated once.
		$created = $this->import_version( 'V1' );
		$second  = $this->import_version( 'V2' );
		$post_id = (int) $created['post_id'];

		$rollback = null;
		$this->on_first(
			$hook,
			$matches,
			function () use ( &$rollback, $second ): void {
				$rollback = $this->rollback_service->rollback_item(
					(int) $second['id']
				);
			}
		);

		// ACT: Update again, rolling back the first update midway.
		$third = $this->import_version( 'V3' );

		// ASSERT: The rollback was refused, and the update completed.
		$this->assertInstanceOf( WP_Error::class, $rollback );
		$this->assertSame( 'post_locked', $rollback->get_error_code() );
		$this->assertSame(
			Post_Lock::held_message(),
			$rollback->get_error_message()
		);
		$this->assertSame( 'updated', $third['status'] );

		// ASSERT: The post holds the whole update.
		$this->assertSame( '<p>V3</p>', get_post( $post_id )->post_content );
		$this->assertSame(
			'V3',
			get_post_meta( $post_id, 'sp_version', true )
		);

		// ASSERT: The first update stays active under the newer one, whose
		// snapshot holds the content it replaced.
		$first_update = $this->repository->get_item( (int) $second['id'] );
		$this->assertSame( 0, (int) $first_update['rolled_back'] );
		$this->assertSame(
			(int) $third['id'],
			(int) $this->repository->get_item_for_post( $post_id )['id']
		);
		$this->assertSame(
			'<p>V2</p>',
			History_Repository::decode_item_changes(
				$third['content_changes']
			)['previous_content']
		);
	}

	/**
	 * Verifies that an import started while a rollback is in flight is
	 * refused and recorded, and the rollback's restore stands.
	 */
	public function test_import_during_a_rollback_is_refused(): void {
		// ARRANGE: A created post, updated twice.
		$created = $this->import_version( 'V1' );
		$second  = $this->import_version( 'V2' );
		$third   = $this->import_version( 'V3' );
		$post_id = (int) $created['post_id'];

		$import = null;
		$this->on_first(
			'pre_post_update',
			static fn (): bool => true,
			function () use ( &$import ): void {
				$import = $this->import_version( 'V4' );
			}
		);

		// ACT: Roll back the newest update, importing again midway.
		$rollback = $this->rollback_service->rollback_item(
			(int) $third['id']
		);

		// ASSERT: The import was refused and recorded as blocked.
		$this->assertIsArray( $import );
		$this->assertSame( 'error', $import['status'] );
		$this->assertSame(
			Post_Lock::held_message(),
			$import['error_message']
		);
		$this->assertSame(
			'concurrent_import_blocked',
			History_Repository::decode_item_changes(
				$import['content_changes']
			)['action']
		);

		// ASSERT: The rollback restored the content before the newest update.
		$this->assertIsArray( $rollback );
		$this->assertSame( 'restored', $rollback['action'] );
		$this->assertSame( '<p>V2</p>', get_post( $post_id )->post_content );
		$this->assertSame(
			(int) $second['id'],
			(int) $this->repository->get_item_for_post( $post_id )['id']
		);
	}

	/**
	 * Verifies that a rollback started while another rollback of the post is
	 * in flight is refused, so only the first one is applied and recorded.
	 */
	public function test_rollback_during_a_rollback_is_refused(): void {
		// ARRANGE: A created post, updated twice.
		$created = $this->import_version( 'V1' );
		$second  = $this->import_version( 'V2' );
		$third   = $this->import_version( 'V3' );
		$post_id = (int) $created['post_id'];

		$inner = null;
		$this->on_first(
			'pre_post_update',
			static fn (): bool => true,
			function () use ( &$inner, $second ): void {
				$inner = $this->rollback_service->rollback_item(
					(int) $second['id']
				);
			}
		);

		// ACT: Roll back the newest update, rolling back the one before
		// midway.
		$outer = $this->rollback_service->rollback_item( (int) $third['id'] );

		// ASSERT: The inner rollback was refused.
		$this->assertInstanceOf( WP_Error::class, $inner );
		$this->assertSame( 'post_locked', $inner->get_error_code() );

		// ASSERT: The outer rollback restored the content before its update.
		$this->assertIsArray( $outer );
		$this->assertSame( 'restored', $outer['action'] );
		$this->assertSame( '<p>V2</p>', get_post( $post_id )->post_content );

		// ASSERT: Only the newest update is rolled back, so the one before is
		// active again.
		$newest_update = $this->repository->get_item( (int) $third['id'] );
		$this->assertSame( 1, (int) $newest_update['rolled_back'] );
		$this->assertSame(
			(int) $second['id'],
			(int) $this->repository->get_item_for_post( $post_id )['id']
		);
	}

	/**
	 * Imports the source post at one version, in a session of its own.
	 *
	 * @param string $version Version label written to content and meta.
	 * @return array History row the import recorded.
	 */
	private function import_version( string $version ): array {
		$this->mock_post_overrides = array(
			'content' => "<p>{$version}</p>",
			'meta'    => array( 'sp_version' => $version ),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'single'
		);

		$this->import_service->import_post(
			array(
				'id'        => self::SOURCE_ID,
				'title'     => 'Concurrent Post',
				'link'      => 'https://source.example.com/concurrent-post',
				'post_type' => 'posts',
			),
			$session_id
		);

		$items = $this->repository->get_session_items( $session_id );
		$item  = $this->repository->get_item( (int) $items[0]['id'] );

		if ( null === $item ) {
			$this->fail( 'The import recorded no history row.' );
		}

		return $item;
	}

	/**
	 * Runs a rival operation the first time a hook fires with matching
	 * arguments, passing the hook's value through.
	 *
	 * @param string   $hook    Hook name.
	 * @param callable $matches Receives the hook arguments.
	 * @param callable $rival   Rival operation.
	 */
	private function on_first(
		string $hook,
		callable $matches,
		callable $rival
	): void {
		$callback = static function ( mixed ...$args ) use (
			&$callback,
			$hook,
			$matches,
			$rival
		): mixed {
			if ( $matches( $args ) ) {
				remove_filter( $hook, $callback, 0 );
				$rival();
			}

			return $args[0];
		};

		add_filter( $hook, $callback, 0, 3 );
	}
}
