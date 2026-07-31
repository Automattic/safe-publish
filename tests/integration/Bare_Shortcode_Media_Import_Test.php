<?php
/**
 * Integration tests for importing a bare [gallery] attached-media set.
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
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Drives real imports of a post whose bare [gallery] renders its attached image
 * set, proving the set is sideloaded from the enrichment field, parented to the
 * destination post so core renders it in menu_order, and that a set image which
 * fails to resolve is skipped rather than aborting the post.
 */
class Bare_Shortcode_Media_Import_Test extends Integration_Test_Case {

	use Per_Source_Id_Post_Api_Mock_Trait;
	use Per_Source_Id_Media_Api_Mock_Trait;
	use Image_Byte_Mock_Trait;

	private const SOURCE          = 'https://source.example.com';
	private const GALLERY_POST_ID = 7001;

	/**
	 * Source post ID => mocked REST body served to the importer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $post_bodies = array();

	/**
	 * Source media ID => mocked wp/v2/media body, or absent to force a dangling
	 * reference.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $media_bodies = array();

	/**
	 * Source URLs whose download is forced to fail, exercising the skip-on-
	 * sideload-failure path.
	 *
	 * @var list<string>
	 */
	private array $poisoned_urls = array();

	/**
	 * Import service under test.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $service;

	/**
	 * Sets up the connection, HTTP mocks, and import service.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );
		$this->add_per_source_id_post_api_mock();
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();
		add_filter( 'pre_http_request', array( $this, 'fail_poisoned_url' ), 0, 3 );
		$this->service = $this->build_service();
	}

	/**
	 * Removes the HTTP mocks and connection.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fail_poisoned_url' ), 0 );
		$this->remove_image_byte_response_mock();
		$this->remove_per_source_id_media_api_mock();
		$this->remove_per_source_id_post_api_mock();
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the registered source post body for an ID, or null when
	 * unregistered.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		return $this->post_bodies[ $source_id ] ?? null;
	}

	/**
	 * Returns the registered media body for an ID, or null to force a dangling
	 * reference the importer skips.
	 *
	 * @param int $source_media_id Source media ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id( int $source_media_id ): ?array {
		return $this->media_bodies[ $source_media_id ] ?? null;
	}

	/**
	 * Fails the download of any poisoned URL, exercising the sideload-failure
	 * skip path. Runs ahead of the byte mock so a poisoned image never resolves.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function fail_poisoned_url(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		return in_array( (string) strtok( $url, '?' ), $this->poisoned_urls, true )
			? new WP_Error( 'safe_publish_test_poisoned', 'Poisoned download' )
			: $preempt;
	}

	/**
	 * Verifies that a bare [gallery]'s attached images are sideloaded, parented
	 * to the destination post, and stamped with the source menu_order, so
	 * get_children returns them in render order regardless of import order.
	 */
	public function test_bare_gallery_children_import_parent_and_render_in_order(): void {
		// ARRANGE: A bare [gallery] whose set lists the first-imported image with
		// the higher menu_order, so render order can't coincide with dest ID order.
		$first_url          = $this->media_url( 8101 );
		$second_url         = $this->media_url( 8102 );
		$this->media_bodies = array(
			8101 => $this->media_body( 8101, $first_url ),
			8102 => $this->media_body( 8102, $second_url ),
		);
		$this->post_bodies  = array(
			self::GALLERY_POST_ID => $this->gallery_post_body(
				array(
					array(
						'id'         => 8101,
						'menu_order' => 2,
					),
					array(
						'id'         => 8102,
						'menu_order' => 1,
					),
				)
			),
		);

		// ACT: Import the post.
		$dest_post = $this->import( self::GALLERY_POST_ID );

		// ASSERT: Both images parented to the post with their source menu_order.
		$first  = $this->dest_attachment_for( $first_url );
		$second = $this->dest_attachment_for( $second_url );
		$this->assertSame( $dest_post, (int) $first->post_parent );
		$this->assertSame( $dest_post, (int) $second->post_parent );
		$this->assertSame( 2, (int) $first->menu_order );
		$this->assertSame( 1, (int) $second->menu_order );

		// ASSERT: Core's bare-gallery query returns the set in menu_order, so the
		// lower-menu_order image sorts ahead of the earlier-imported one.
		$this->assertSame(
			array( $second->ID, $first->ID ),
			$this->gallery_children( $dest_post ),
			'Bare [gallery] should render its attached set in menu_order'
		);
	}

	/**
	 * Verifies that a set image which fails to sideload and one whose source
	 * record is unreachable are both skipped, while the resolvable image still
	 * imports and the post itself succeeds.
	 */
	public function test_failed_gallery_children_are_skipped_not_fatal(): void {
		// ARRANGE: A good image, one poisoned to fail its download, and one whose
		// media record is not mocked (a dangling reference).
		$good_url           = $this->media_url( 8201 );
		$failed_url         = $this->media_url( 8202 );
		$this->media_bodies = array(
			8201 => $this->media_body( 8201, $good_url ),
			8202 => $this->media_body( 8202, $failed_url ),
			// 8203 is intentionally unmocked, forcing a dangling reference.
		);
		$this->poisoned_urls = array( $failed_url );
		$this->post_bodies   = array(
			self::GALLERY_POST_ID => $this->gallery_post_body(
				array(
					array(
						'id'         => 8201,
						'menu_order' => 1,
					),
					array(
						'id'         => 8202,
						'menu_order' => 2,
					),
					array(
						'id'         => 8203,
						'menu_order' => 3,
					),
				)
			),
		);

		// ACT: Import the post.
		$dest_post = $this->import( self::GALLERY_POST_ID );

		// ASSERT: Only the resolvable image imported, parented to the post; the
		// failed and dangling references left no attachment behind.
		$good = $this->dest_attachment_for( $good_url );
		$this->assertSame( $dest_post, (int) $good->post_parent );
		$this->assertNull(
			$this->find_dest_attachment( $failed_url ),
			'A set image that fails to sideload should leave no attachment'
		);
		$this->assertSame(
			array( $good->ID ),
			$this->gallery_children( $dest_post ),
			'Only the resolvable image should render in the bare gallery'
		);
	}

	/**
	 * Imports a registered source post and returns its destination post ID.
	 *
	 * @param int $source_id Source post ID to import.
	 * @return int Destination post ID.
	 */
	private function import( int $source_id ): int {
		$result = $this->service->import_post(
			array(
				'id'        => $source_id,
				'title'     => 'Post ' . $source_id,
				'link'      => self::SOURCE . '/post-' . $source_id,
				'post_type' => 'posts',
			)
		);

		$this->assertTrue(
			$result['success'] ?? false,
			"Import of source {$source_id} should succeed: "
			. (string) ( $result['error'] ?? '' )
		);

		return (int) $result['post_id'];
	}

	/**
	 * Builds the import service, mirroring the plugin's wiring.
	 *
	 * @return Post_Import_Service
	 */
	private function build_service(): Post_Import_Service {
		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		return new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			new History_Repository(),
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Builds a source REST body for a bare [gallery] carrying the given
	 * attached-media enrichment set.
	 *
	 * @param list<array{id: int, menu_order: int}> $attached_media Enrichment set.
	 * @return array<string, mixed>
	 */
	private function gallery_post_body( array $attached_media ): array {
		$user = wp_get_current_user();

		return array(
			'id'                          => self::GALLERY_POST_ID,
			'title'                       => array( 'raw' => 'Bare gallery' ),
			'content'                     => array( 'raw' => '[gallery]' ),
			'excerpt'                     => array( 'raw' => '' ),
			'link'                        => self::SOURCE . '/post-' . self::GALLERY_POST_ID,
			'guid'                        => self::SOURCE . '/?p=' . self::GALLERY_POST_ID,
			'slug'                        => 'bare-gallery',
			'type'                        => 'post',
			'status'                      => 'publish',
			'date'                        => '2025-01-01T00:00:00',
			'date_gmt'                    => '2025-01-01T00:00:00',
			'comment_status'              => 'open',
			'ping_status'                 => 'open',
			'menu_order'                  => 0,
			'password'                    => '',
			'parent'                      => 0,
			'featured_media'              => 0,
			'meta'                        => array(),
			'safe_publish_author'         => array(
				'email'        => $user->user_email,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
			),
			'safe_publish_media'          => array(),
			'safe_publish_attached_media' => $attached_media,
			'_embedded'                   => array( 'wp:term' => array() ),
		);
	}

	/**
	 * Builds a wp/v2/media body resolving a source media ID to a downloadable
	 * URL and full library metadata.
	 *
	 * @param int    $media_id   Source media ID.
	 * @param string $source_url Downloadable source URL.
	 * @return array<string, mixed>
	 */
	private function media_body( int $media_id, string $source_url ): array {
		return array(
			'id'          => $media_id,
			'source_url'  => $source_url,
			'media_type'  => 'image',
			'mime_type'   => 'image/jpeg',
			'alt_text'    => '',
			'title'       => array( 'raw' => '' ),
			'caption'     => array( 'raw' => '' ),
			'description' => array( 'raw' => '' ),
			// The attached set's own parent post; the destination re-parents to it.
			'post'        => self::GALLERY_POST_ID,
		);
	}

	/**
	 * Returns the source URL for a media ID.
	 *
	 * @param int $media_id Source media ID.
	 * @return string
	 */
	private function media_url( int $media_id ): string {
		return self::SOURCE . '/wp-content/uploads/2025/01/gallery-'
			. $media_id . '.jpg';
	}

	/**
	 * Returns the destination image attachments parented to the post, in the
	 * order core's bare [gallery] renders them.
	 *
	 * @param int $post_id Destination post ID.
	 * @return list<int> Attachment IDs in menu_order.
	 */
	private function gallery_children( int $post_id ): array {
		$children = get_children(
			array(
				'post_parent'    => $post_id,
				'post_status'    => 'inherit',
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'orderby'        => 'menu_order ID',
				'order'          => 'ASC',
				'numberposts'    => -1,
			)
		);

		return array_values(
			array_map( static fn ( $child ): int => (int) $child->ID, $children )
		);
	}

	/**
	 * Resolves the single destination attachment sideloaded from a source URL,
	 * failing when absent.
	 *
	 * @param string $source_url Source URL.
	 * @return \WP_Post
	 */
	private function dest_attachment_for( string $source_url ): \WP_Post {
		$attachment = $this->find_dest_attachment( $source_url );

		$this->assertNotNull(
			$attachment,
			"Source URL {$source_url} should have sideloaded to an attachment"
		);

		return $attachment;
	}

	/**
	 * Finds the destination attachment sideloaded from a source URL, or null.
	 *
	 * @param string $source_url Source URL.
	 * @return \WP_Post|null
	 */
	private function find_dest_attachment( string $source_url ): ?\WP_Post {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'meta_key'         => Options::META_ORIGINAL_URL,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $source_url,
				'suppress_filters' => false,
			)
		);

		return array() === $attachments ? null : $attachments[0];
	}
}
