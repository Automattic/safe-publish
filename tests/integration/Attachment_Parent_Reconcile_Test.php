<?php
/**
 * Integration tests for attachment parenting across import orders.
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
use WP_Post;

/**
 * Drives real imports through Post_Import_Service to prove the attachment
 * parenting invariant holds regardless of import order: The sweep adopts an
 * attachment once its parent post arrives, and the forward pass parents one
 * whose parent was already imported.
 */
class Attachment_Parent_Reconcile_Test extends Integration_Test_Case {

	use Per_Source_Id_Post_Api_Mock_Trait;
	use Image_Byte_Mock_Trait;

	private const SOURCE           = 'https://source.example.com';
	private const PARENT_SOURCE_ID = 6001;
	private const CHILD_SOURCE_ID  = 6002;
	private const IMAGE_ID         = 6501;

	/**
	 * Source post ID => mocked REST body served to the importer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $post_bodies = array();

	/**
	 * Import service under test, wired to a single media importer per class so
	 * the forward pass sees each run's freshly sideloaded attachments.
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
		$this->add_image_byte_response_mock();
		$this->service = $this->build_service();
	}

	/**
	 * Removes the HTTP mocks and connection.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_image_byte_response_mock();
		$this->remove_per_source_id_post_api_mock();
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the registered source body for an ID, or null when unregistered.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		return $this->post_bodies[ $source_id ] ?? null;
	}

	/**
	 * Verifies that an attachment sideloaded before its source parent is
	 * imported stays unattached with its source parent recorded, then is adopted
	 * by the sweep when that parent post is imported.
	 */
	public function test_orphan_attachment_self_heals_when_parent_imported_later(): void {
		// ARRANGE: A child post referencing an image owned by a not-yet-imported
		// parent post.
		$this->post_bodies = array(
			self::CHILD_SOURCE_ID  => $this->post_body(
				self::CHILD_SOURCE_ID,
				$this->content_with_image(),
				$this->media_map_owned_by( self::PARENT_SOURCE_ID )
			),
			self::PARENT_SOURCE_ID => $this->post_body(
				self::PARENT_SOURCE_ID,
				'<p>Parent body.</p>',
				array()
			),
		);

		// ACT: Import the child before its image's parent exists.
		$this->import( self::CHILD_SOURCE_ID );

		// ASSERT: The attachment records its source parent but stays unattached.
		$attachment = $this->dest_attachment();
		$this->assertSame(
			0,
			(int) $attachment->post_parent,
			'Attachment should stay unattached while its parent is unimported'
		);
		$this->assertSame(
			self::PARENT_SOURCE_ID,
			(int) get_post_meta(
				$attachment->ID,
				Options::META_SOURCE_ATTACHMENT_PARENT_ID,
				true
			),
			'Attachment should record its source parent'
		);

		// ACT: Import the parent post.
		$dest_parent = $this->import( self::PARENT_SOURCE_ID );

		// ASSERT: The sweep adopts the orphaned attachment.
		clean_post_cache( $attachment->ID );
		$this->assertSame(
			$dest_parent,
			(int) get_post( $attachment->ID )->post_parent,
			'Importing the parent should adopt the orphaned attachment'
		);
	}

	/**
	 * Verifies that an attachment whose source parent is already imported is
	 * parented to that destination post as it is sideloaded, via the forward
	 * pass, even though the parent renders no media of its own.
	 */
	public function test_attachment_parents_to_already_imported_source_parent(): void {
		// ARRANGE: A parent post that renders nothing, then a child referencing
		// the parent's image.
		$this->post_bodies = array(
			self::PARENT_SOURCE_ID => $this->post_body(
				self::PARENT_SOURCE_ID,
				'<p>Parent body.</p>',
				array()
			),
			self::CHILD_SOURCE_ID  => $this->post_body(
				self::CHILD_SOURCE_ID,
				$this->content_with_image(),
				$this->media_map_owned_by( self::PARENT_SOURCE_ID )
			),
		);

		// ACT: Import the parent first, then the child.
		$dest_parent = $this->import( self::PARENT_SOURCE_ID );
		$this->import( self::CHILD_SOURCE_ID );

		// ASSERT: The child's import parents the fresh attachment to dest parent.
		$this->assertSame(
			$dest_parent,
			(int) $this->dest_attachment()->post_parent,
			'Attachment should be parented to its already-imported source parent'
		);
	}

	/**
	 * Verifies that an inline image unattached at the source lands unattached,
	 * recording no source parent, so library items never gain a parent.
	 */
	public function test_unattached_source_media_stays_unattached(): void {
		// ARRANGE: A post whose inline image is an unattached source library item.
		$this->post_bodies = array(
			self::CHILD_SOURCE_ID => $this->post_body(
				self::CHILD_SOURCE_ID,
				$this->content_with_image(),
				$this->media_map_owned_by( 0 )
			),
		);

		// ACT: Import the post.
		$this->import( self::CHILD_SOURCE_ID );

		// ASSERT: The attachment stays unattached and records no source parent.
		$attachment = $this->dest_attachment();
		$this->assertSame(
			0,
			(int) $attachment->post_parent,
			'An unattached source image should stay unattached'
		);
		$this->assertSame(
			'',
			(string) get_post_meta(
				$attachment->ID,
				Options::META_SOURCE_ATTACHMENT_PARENT_ID,
				true
			),
			'An unattached source image should carry no source parent meta'
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
	 * Builds a minimal source REST body carrying the given content and
	 * safe_publish_media map.
	 *
	 * @param int                                  $source_id          Source post ID.
	 * @param string                               $content            Raw post content.
	 * @param array<string, array<string, string>> $safe_publish_media Source URL => enrichment.
	 * @return array<string, mixed>
	 */
	private function post_body(
		int $source_id,
		string $content,
		array $safe_publish_media
	): array {
		$user = wp_get_current_user();

		return array(
			'id'                  => $source_id,
			'title'               => array( 'raw' => 'Post ' . $source_id ),
			'content'             => array( 'raw' => $content ),
			'excerpt'             => array( 'raw' => '' ),
			'link'                => self::SOURCE . '/post-' . $source_id,
			'guid'                => self::SOURCE . '/?p=' . $source_id,
			'slug'                => 'post-' . $source_id,
			'type'                => 'post',
			'status'              => 'publish',
			'date'                => '2025-01-01T00:00:00',
			'date_gmt'            => '2025-01-01T00:00:00',
			'comment_status'      => 'open',
			'ping_status'         => 'open',
			'menu_order'          => 0,
			'password'            => '',
			'parent'              => 0,
			'featured_media'      => 0,
			'meta'                => array(),
			'safe_publish_author' => array(
				'email'        => $user->user_email,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
			),
			'safe_publish_media'  => $safe_publish_media,
			'_embedded'           => array( 'wp:term' => array() ),
		);
	}

	/**
	 * Builds a core/image block referencing the shared source image.
	 *
	 * @return string
	 */
	private function content_with_image(): string {
		return '<!-- wp:image --><figure class="wp-block-image"><img src="'
			. $this->image_url() . '" alt="" /></figure><!-- /wp:image -->';
	}

	/**
	 * Builds the safe_publish_media map for the shared image, owned by the given
	 * source post.
	 *
	 * @param int $owner_source_id Source post the image is attached to.
	 * @return array<string, array<string, string>>
	 */
	private function media_map_owned_by( int $owner_source_id ): array {
		return array(
			$this->image_url() => array(
				'alt'         => '',
				'title'       => '',
				'caption'     => '',
				'description' => '',
				'parent'      => (string) $owner_source_id,
			),
		);
	}

	/**
	 * Returns the source URL of the shared image.
	 *
	 * @return string
	 */
	private function image_url(): string {
		return self::SOURCE . '/wp-content/uploads/2025/01/img-'
			. self::IMAGE_ID . '.jpg';
	}

	/**
	 * Finds the single destination attachment sideloaded from the shared image.
	 *
	 * @return WP_Post
	 */
	private function dest_attachment(): WP_Post {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'meta_key'         => Options::META_ORIGINAL_URL,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $this->image_url(),
				'suppress_filters' => false,
			)
		);

		$this->assertNotEmpty(
			$attachments,
			'The inline image should have sideloaded to an attachment'
		);

		return $attachments[0];
	}
}
