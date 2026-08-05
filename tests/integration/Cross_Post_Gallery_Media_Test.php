<?php
/**
 * Integration tests for pulling a cross-post [gallery id="B"] rendered set.
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
use Safe_Publish\Utils\Reconcile_Outcome;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Drives real imports of a post A rendering [gallery id="B"]/[playlist id="B"]:
 * once B is imported, A's import (or the issue retry, in the A-then-B order)
 * pulls B's rendered set of the referencing shortcode's media type, parents it
 * to dest-B, and stamps the source menu_order so core fills the shortcode.
 */
class Cross_Post_Gallery_Media_Test extends Integration_Test_Case {

	use Per_Source_Id_Post_Api_Mock_Trait;
	use Per_Source_Id_Media_Api_Mock_Trait;
	use Image_Byte_Mock_Trait;

	private const SOURCE   = 'https://source.example.com';
	private const A_SOURCE = 9001;
	private const B_SOURCE = 9002;

	/**
	 * Source post ID => mocked REST body served to the importer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $post_bodies = array();

	/**
	 * Source media ID => mocked wp/v2/media body.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $media_bodies = array();

	/**
	 * Source URLs whose download is forced to fail.
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
	 * skip path.
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
	 * Verifies that when B is already imported, importing A pulls B's image
	 * set, parents it to dest-B in menu_order, remaps A's reference to dest-B,
	 * and skips B's non-image children.
	 */
	public function test_gallery_reference_pulls_and_parents_b_image_set(): void {
		// ARRANGE: B is imported; its referenced set lists two images (the
		// first with the higher menu_order) and an audio child a gallery must
		// not pull.
		$dest_b     = $this->seed_imported_post( self::B_SOURCE );
		$first_url  = $this->media_url( 8501 );
		$second_url = $this->media_url( 8502 );
		$audio_url  = $this->media_url( 8503 );

		$this->media_bodies = array(
			8501 => $this->media_body( 8501, $first_url ),
			8502 => $this->media_body( 8502, $second_url ),
			8503 => $this->media_body( 8503, $audio_url ),
		);
		$this->post_bodies  = array(
			self::A_SOURCE => $this->referencing_post_body(
				'[gallery id="' . self::B_SOURCE . '"]'
			),
			self::B_SOURCE => $this->referenced_post_body(
				array(
					'image' => array(
						array(
							'id'         => 8501,
							'menu_order' => 2,
						),
						array(
							'id'         => 8502,
							'menu_order' => 1,
						),
					),
					'audio' => array(
						array(
							'id'         => 8503,
							'menu_order' => 1,
						),
					),
				)
			),
		);

		// ACT: Import A.
		$dest_a = $this->import( self::A_SOURCE );

		// ASSERT: Both images parented to dest-B with their source menu_order.
		$first  = $this->dest_attachment_for( $first_url );
		$second = $this->dest_attachment_for( $second_url );
		$this->assertSame( $dest_b, (int) $first->post_parent );
		$this->assertSame( $dest_b, (int) $second->post_parent );
		$this->assertSame( 2, (int) $first->menu_order );
		$this->assertSame( 1, (int) $second->menu_order );

		// ASSERT: Core's bare-gallery query on dest-B returns the set in order.
		$this->assertSame(
			array( $second->ID, $first->ID ),
			$this->gallery_children( $dest_b ),
			'Cross-post gallery should render dest-B children in menu_order'
		);

		// ASSERT: The audio child was not pulled, only the gallery's image
		// group.
		$this->assertNull(
			$this->find_dest_attachment( $audio_url ),
			'A gallery reference must not pull B non-image children'
		);

		// ASSERT: A's reference was remapped to dest-B.
		$this->assertStringContainsString(
			'[gallery id="' . $dest_b . '"]',
			(string) get_post_field( 'post_content', $dest_a )
		);
	}

	/**
	 * Verifies that a [playlist id="B"] pulls B's audio children, the media
	 * type that shortcode renders.
	 */
	public function test_playlist_reference_pulls_b_audio_set(): void {
		// ARRANGE: B is imported; its referenced set lists an audio and an
		// image.
		$dest_b    = $this->seed_imported_post( self::B_SOURCE );
		$audio_url = $this->media_url( 8601 );
		$image_url = $this->media_url( 8602 );

		$this->media_bodies = array(
			8601 => $this->media_body( 8601, $audio_url ),
			8602 => $this->media_body( 8602, $image_url ),
		);
		$this->post_bodies  = array(
			self::A_SOURCE => $this->referencing_post_body(
				'[playlist id="' . self::B_SOURCE . '"]'
			),
			self::B_SOURCE => $this->referenced_post_body(
				array(
					'audio' => array(
						array(
							'id'         => 8601,
							'menu_order' => 1,
						),
					),
					'image' => array(
						array(
							'id'         => 8602,
							'menu_order' => 1,
						),
					),
				)
			),
		);

		// ACT: Import A.
		$this->import( self::A_SOURCE );

		// ASSERT: The audio child imported and parented to dest-B; the image
		// not.
		$audio = $this->dest_attachment_for( $audio_url );
		$this->assertSame( $dest_b, (int) $audio->post_parent );
		$this->assertNull(
			$this->find_dest_attachment( $image_url ),
			'A playlist reference must not pull B image children'
		);
	}

	/**
	 * Verifies that a referenced-set image which fails to sideload is skipped,
	 * while the resolvable image still imports and A itself succeeds.
	 */
	public function test_failed_referenced_item_is_skipped_not_fatal(): void {
		// ARRANGE: B is imported; one of its images is poisoned to fail
		// download.
		$dest_b     = $this->seed_imported_post( self::B_SOURCE );
		$good_url   = $this->media_url( 8701 );
		$failed_url = $this->media_url( 8702 );

		$this->media_bodies  = array(
			8701 => $this->media_body( 8701, $good_url ),
			8702 => $this->media_body( 8702, $failed_url ),
		);
		$this->poisoned_urls = array( $failed_url );
		$this->post_bodies   = array(
			self::A_SOURCE => $this->referencing_post_body(
				'[gallery id="' . self::B_SOURCE . '"]'
			),
			self::B_SOURCE => $this->referenced_post_body(
				array(
					'image' => array(
						array(
							'id'         => 8701,
							'menu_order' => 1,
						),
						array(
							'id'         => 8702,
							'menu_order' => 2,
						),
					),
				)
			),
		);

		// ACT: Import A.
		$this->import( self::A_SOURCE );

		// ASSERT: Only the resolvable image imported, parented to dest-B.
		$good = $this->dest_attachment_for( $good_url );
		$this->assertSame( $dest_b, (int) $good->post_parent );
		$this->assertNull(
			$this->find_dest_attachment( $failed_url ),
			'A referenced image that fails to sideload should leave no attachment'
		);
	}

	/**
	 * Verifies that in the A-then-B order the issue retry repoints A's
	 * reference and pulls B's set, parenting it to dest-B, once B is imported.
	 */
	public function test_retry_repoints_and_pulls_after_b_imports(): void {
		// ARRANGE: Import A while B is absent, leaving the reference unmapped.
		$this->post_bodies = array(
			self::A_SOURCE => $this->referencing_post_body(
				'[gallery id="' . self::B_SOURCE . '"]'
			),
		);
		$dest_a            = $this->import( self::A_SOURCE );
		$this->assertStringContainsString(
			'[gallery id="' . self::B_SOURCE . '"]',
			(string) get_post_field( 'post_content', $dest_a ),
			'The reference stays unmapped until B is imported'
		);

		// ARRANGE: B is now imported and its referenced image set is available.
		$dest_b                              = $this->seed_imported_post( self::B_SOURCE );
		$image_url                           = $this->media_url( 8801 );
		$this->media_bodies                  = array(
			8801 => $this->media_body( 8801, $image_url ),
		);
		$this->post_bodies[ self::B_SOURCE ] = $this->referenced_post_body(
			array(
				'image' => array(
					array(
						'id'         => 8801,
						'menu_order' => 1,
					),
				),
			)
		);

		// ACT: Retry the unmapped gallery reference.
		$outcome = $this->service->retry_gallery_ref_remap(
			$dest_a,
			self::B_SOURCE,
			Options::get_connected_site_url_with_path()
		);

		// ASSERT: The retry resolved, repointing the reference to dest-B.
		$this->assertInstanceOf( Reconcile_Outcome::class, $outcome );
		$this->assertTrue( $outcome->is_resolved() );
		$this->assertStringContainsString(
			'[gallery id="' . $dest_b . '"]',
			(string) get_post_field( 'post_content', $dest_a )
		);

		// ASSERT: B's set was pulled and parented to dest-B by the retry.
		$image = $this->dest_attachment_for( $image_url );
		$this->assertSame( $dest_b, (int) $image->post_parent );
		$this->assertSame(
			array( $image->ID ),
			$this->gallery_children( $dest_b )
		);
	}

	/**
	 * Verifies that when a cross-post import aborts after pulling B's set, the
	 * pulled media is cleaned up with the aborted session and B is untouched.
	 */
	public function test_mid_import_abort_deletes_pulled_media_but_spares_b(): void {
		// ARRANGE: B is imported; A references B but carries an unknown
		// taxonomy that aborts A after B's set is pulled.
		$dest_b             = $this->seed_imported_post( self::B_SOURCE );
		$image_url          = $this->media_url( 8901 );
		$this->media_bodies = array( 8901 => $this->media_body( 8901, $image_url ) );

		$a_body                         = $this->referencing_post_body(
			'[gallery id="' . self::B_SOURCE . '"]'
		);
		$a_body['_embedded']['wp:term'] = array(
			array(
				array(
					'id'       => 1,
					'taxonomy' => 'nonexistent_taxonomy_xyz',
					'name'     => 'Bad',
					'slug'     => 'bad',
				),
			),
		);
		$this->post_bodies              = array(
			self::A_SOURCE => $a_body,
			self::B_SOURCE => $this->referenced_post_body(
				array(
					'image' => array(
						array(
							'id'         => 8901,
							'menu_order' => 1,
						),
					),
				)
			),
		);

		// ACT: Import A, which aborts on the unknown taxonomy.
		$result = $this->service->import_post(
			array(
				'id'        => self::A_SOURCE,
				'title'     => 'Referencing post',
				'link'      => self::SOURCE . '/post-' . self::A_SOURCE,
				'post_type' => 'posts',
			)
		);

		// ASSERT: The import failed.
		$this->assertFalse( $result['success'] ?? true );

		// ASSERT: B survives, and the media pulled for A was cleaned up without
		// touching B's own attached media.
		$this->assertNotNull( get_post( $dest_b ) );
		$this->assertNull(
			$this->find_dest_attachment( $image_url ),
			'Media pulled during an aborted import should be cleaned up'
		);
	}

	/**
	 * Verifies that the pull applies B's source menu_order to an image B already
	 * imported inline (menu_order 0), correcting a dedup hit's order in place.
	 */
	public function test_pull_reorders_previously_imported_item(): void {
		// ARRANGE: B already owns image X at menu_order 0, as an inline import
		// leaves it; B's referenced set orders X at 5.
		$dest_b   = $this->seed_imported_post( self::B_SOURCE );
		$x_url    = $this->media_url( 8951 );
		$existing = $this->seed_imported_attachment( $dest_b, $x_url, 0 );

		$this->media_bodies = array( 8951 => $this->media_body( 8951, $x_url ) );
		$this->post_bodies  = array(
			self::A_SOURCE => $this->referencing_post_body(
				'[gallery id="' . self::B_SOURCE . '"]'
			),
			self::B_SOURCE => $this->referenced_post_body(
				array(
					'image' => array(
						array(
							'id'         => 8951,
							'menu_order' => 5,
						),
					),
				)
			),
		);

		// ACT: Import A.
		$this->import( self::A_SOURCE );

		// ASSERT: The same attachment is reused, its order corrected to 5.
		$x = $this->dest_attachment_for( $x_url );
		$this->assertSame( $existing, $x->ID );
		$this->assertSame( 5, (int) $x->menu_order );
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
	 * Seeds a destination post tagged with a source post ID and the source
	 * site, standing in for an already-imported referenced post B.
	 *
	 * @param int $source_id Source post ID meta value.
	 * @return int Destination post ID.
	 */
	private function seed_imported_post( int $source_id ): int {
		$post_id = self::factory()->post->create();
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, self::SOURCE );

		return $post_id;
	}

	/**
	 * Seeds an attachment already imported for a post: Parented to it and
	 * carrying the source origin URL, so a later pull dedups onto it.
	 *
	 * @param int    $parent_id  Owning destination post.
	 * @param string $source_url Source origin URL recorded on the attachment.
	 * @param int    $menu_order Attachment menu_order.
	 * @return int Attachment ID.
	 */
	private function seed_imported_attachment(
		int $parent_id,
		string $source_url,
		int $menu_order
	): int {
		$id = self::factory()->attachment->create(
			array(
				'post_parent'    => $parent_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Prior import',
				'menu_order'     => $menu_order,
			)
		);
		$this->assertIsInt( $id );
		update_post_meta( $id, Options::META_ORIGINAL_URL, $source_url );
		update_post_meta( $id, Options::META_IMPORTED_FROM, self::SOURCE );

		return $id;
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
	 * Builds a source REST body for post A, whose content references post B.
	 *
	 * @param string $content Post content carrying the cross-post shortcode.
	 * @return array<string, mixed>
	 */
	private function referencing_post_body( string $content ): array {
		$user = wp_get_current_user();

		return array(
			'id'                          => self::A_SOURCE,
			'title'                       => array( 'raw' => 'Referencing post' ),
			'content'                     => array( 'raw' => $content ),
			'excerpt'                     => array( 'raw' => '' ),
			'link'                        => self::SOURCE . '/post-' . self::A_SOURCE,
			'guid'                        => self::SOURCE . '/?p=' . self::A_SOURCE,
			'slug'                        => 'referencing-post',
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
			'safe_publish_attached_media' => array(),
			'_embedded'                   => array( 'wp:term' => array() ),
		);
	}

	/**
	 * Builds a minimal source body carrying post B's referenced-media groups,
	 * the only field the referenced-set fetch reads.
	 *
	 * @param array<string, list<array{id: int, menu_order: int}>> $groups Grouped set.
	 * @return array<string, mixed>
	 */
	private function referenced_post_body( array $groups ): array {
		return array(
			'id'                            => self::B_SOURCE,
			'safe_publish_referenced_media' => $groups,
		);
	}

	/**
	 * Builds a wp/v2/media body resolving a source media ID to a downloadable
	 * URL, recording post B as its source parent.
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
			// B is the media's source parent; the destination re-parents to it.
			'post'        => self::B_SOURCE,
		);
	}

	/**
	 * Returns the source URL for a media ID.
	 *
	 * @param int $media_id Source media ID.
	 * @return string
	 */
	private function media_url( int $media_id ): string {
		return self::SOURCE . '/wp-content/uploads/2025/01/cross-'
			. $media_id . '.jpg';
	}

	/**
	 * Returns the destination image attachments parented to a post, in the
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
