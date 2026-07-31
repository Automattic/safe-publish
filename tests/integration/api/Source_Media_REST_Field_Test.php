<?php
/**
 * Integration tests for the Source_Media_REST_Field class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\API;

use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\API\Source_Media_REST_Field;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Source Media REST Field Test Class.
 *
 * Access control: the safe_publish_media field is populated only for Safe
 * Publish HMAC-authenticated single-item requests, and it resolves the post's
 * own media URLs to the raw library metadata and source parent on each
 * attachment record.
 */
class Source_Media_REST_Field_Test extends WP_UnitTestCase {

	/**
	 * REST server instance used for dispatching requests.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * HMAC authenticator under test.
	 *
	 * @var HMAC_Authenticator
	 */
	private HMAC_Authenticator $authenticator;

	/**
	 * Sets up the REST server and the field registration.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			'integration-test-secret-key-32chars-ok',
			home_url()
		);

		( new Source_Media_REST_Field( $this->authenticator ) )->init();

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );
	}

	/**
	 * Clears the global REST server between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Creates an attachment carrying known library metadata and returns its
	 * ID and resolvable URL. The label prefixes each metadata field so callers
	 * seeding more than one attachment can tell their values apart.
	 *
	 * @param string $file  Uploads-relative file path.
	 * @param string $label Metadata field prefix. Default 'Library'.
	 * @return array{id: int, url: string}
	 */
	private function seed_attachment( string $file, string $label = 'Library' ): array {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => $label . ' title',
				'post_excerpt'   => $label . ' caption',
				'post_content'   => $label . ' description',
			)
		);
		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			$label . ' alt'
		);

		return array(
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Creates an attachment carrying known library metadata and one registered
	 * intermediate size, returning its ID, full-size URL, and the sized URL.
	 *
	 * @param string $file      Uploads-relative file path of the original.
	 * @param string $size_file Basename of the registered rendition.
	 * @return array{id: int, url: string, sized_url: string}
	 */
	private function seed_attachment_with_size(
		string $file,
		string $size_file
	): array {
		$image = $this->seed_attachment( $file );

		wp_update_attachment_metadata(
			$image['id'],
			array(
				'file'  => $file,
				'sizes' => array(
					'large' => array(
						'file'      => $size_file,
						'width'     => 1024,
						'height'    => 683,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		$image['sized_url'] = dirname( $image['url'] ) . '/' . $size_file;

		return $image;
	}

	/**
	 * Forces the HMAC authenticator's authenticated flag for tests that do not
	 * need a fully signed request.
	 *
	 * @param bool $authenticated Desired authentication state.
	 */
	private function force_hmac_authenticated( bool $authenticated ): void {
		$reflection = new ReflectionClass( $this->authenticator );
		$property   = $reflection->getProperty( 'authenticated' );
		$property->setValue( $this->authenticator, $authenticated );
	}

	/**
	 * Verifies that the field is null for requests not authenticated by Safe
	 * Publish HMAC, so no library metadata is exposed to public consumers.
	 */
	public function test_field_is_null_for_non_hmac_request(): void {
		// ARRANGE: a post referencing a real attachment, no HMAC auth.
		$image   = $this->seed_attachment( '2025/01/public-image.jpg' );
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $image['url'] . '" alt="x"/>' )
		);

		// ACT: dispatch a public single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: field present (registered) but null.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'safe_publish_media', $data );
		$this->assertNull( $data['safe_publish_media'] );
	}

	/**
	 * Verifies that an HMAC-authenticated single-item request maps each inline
	 * media URL to the raw library values on its attachment record.
	 */
	public function test_field_maps_inline_urls_to_library_metadata(): void {
		// ARRANGE: a post embedding an attachment's URL.
		$image   = $this->seed_attachment( '2025/01/inline-image.jpg' );
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:image --><figure><img src="'
					. $image['url'] . '" alt="inline"/></figure><!-- /wp:image -->',
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: the URL maps to the raw library metadata.
		$this->assertSame( 200, $response->get_status() );
		$media = $response->get_data()['safe_publish_media'];
		$this->assertSame(
			array(
				'alt'         => 'Library alt',
				'title'       => 'Library title',
				'caption'     => 'Library caption',
				'description' => 'Library description',
				'parent'      => '0',
			),
			$media[ $image['url'] ] ?? null
		);
	}

	/**
	 * Verifies that the map reports each attachment's source parent post, the
	 * value the destination re-parents its imported copy to.
	 */
	public function test_field_reports_source_parent(): void {
		// ARRANGE: An attachment attached to one post, inlined in another.
		$parent_post = self::factory()->post->create();
		$this->assertIsInt( $parent_post );
		$image = $this->seed_attachment( '2025/01/attached-image.jpg' );
		wp_update_post(
			array(
				'ID'          => $image['id'],
				'post_parent' => $parent_post,
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<img src="' . $image['url'] . '" alt="x"/>',
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: The map reports the attachment's source parent post.
		$media = $response->get_data()['safe_publish_media'];
		$this->assertSame(
			(string) $parent_post,
			$media[ $image['url'] ]['parent'] ?? null
		);
	}

	/**
	 * Verifies that an inline image inserted at an intermediate size resolves to
	 * its parent library item, keyed by the sized URL so the destination lookup
	 * hits.
	 */
	public function test_field_maps_sized_inline_url_to_parent_metadata(): void {
		// ARRANGE: an attachment with a registered -1024x683 rendition, inlined
		// at that size.
		$image   = $this->seed_attachment_with_size(
			'2025/01/sized-image.jpg',
			'sized-image-1024x683.jpg'
		);
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<img src="' . $image['sized_url']
					. '" alt="inline"/>',
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: the sized URL carries the parent's library metadata.
		$media = $response->get_data()['safe_publish_media'];
		$this->assertSame(
			array(
				'alt'         => 'Library alt',
				'title'       => 'Library title',
				'caption'     => 'Library caption',
				'description' => 'Library description',
				'parent'      => '0',
			),
			$media[ $image['sized_url'] ] ?? null
		);
	}

	/**
	 * Verifies that a sized URL sharing a base filename with an attachment but
	 * not among its registered sizes is omitted, so metadata is never borrowed
	 * from an unrelated item.
	 */
	public function test_field_omits_sized_url_not_a_known_rendition(): void {
		// ARRANGE: an attachment whose only registered size is -1024x683, but the
		// post inlines a -2x3 URL that is not a real rendition of it.
		$image   = $this->seed_attachment_with_size(
			'2025/01/base.jpg',
			'base-1024x683.jpg'
		);
		$bogus   = dirname( $image['url'] ) . '/base-2x3.jpg';
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $bogus . '" alt="x"/>' )
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: the base name matches an attachment, but -2x3 is not one of its
		// registered sizes, so the map is empty.
		$this->assertSame(
			array(),
			$response->get_data()['safe_publish_media']
		);
	}

	/**
	 * Verifies that a real attachment whose own filename ends in a size-like
	 * suffix resolves directly to its own metadata, never normalized to a
	 * same-base parent.
	 */
	public function test_field_does_not_normalize_real_size_named_file(): void {
		// ARRANGE: a parent photo.jpg and a distinct real file
		// photo-1920x1080.jpg whose name coincidentally looks like a rendition.
		$this->seed_attachment( '2025/01/photo.jpg', 'Parent' );
		$standalone = $this->seed_attachment(
			'2025/01/photo-1920x1080.jpg',
			'Standalone'
		);
		$post_id    = self::factory()->post->create(
			array(
				'post_content' => '<img src="' . $standalone['url']
					. '" alt="x"/>',
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: it resolves to its own record, not the photo.jpg parent's.
		$media = $response->get_data()['safe_publish_media'];
		$this->assertSame(
			array(
				'alt'         => 'Standalone alt',
				'title'       => 'Standalone title',
				'caption'     => 'Standalone caption',
				'description' => 'Standalone description',
				'parent'      => '0',
			),
			$media[ $standalone['url'] ] ?? null
		);
	}

	/**
	 * Verifies that a responsive srcset sub-size descriptor resolves to its
	 * parent library item, matching the documented inline coverage.
	 */
	public function test_field_maps_srcset_subsize_to_parent_metadata(): void {
		// ARRANGE: an attachment referenced only through a srcset sub-size
		// descriptor. Authoring as an admin keeps the srcset attribute, which
		// the default kses allowlist would otherwise strip from stored content.
		$image = $this->seed_attachment_with_size(
			'2025/01/responsive.jpg',
			'responsive-1024x683.jpg'
		);
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<img srcset="' . $image['sized_url']
					. ' 1024w" alt="x"/>',
			)
		);
		wp_set_current_user( 0 );

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: the sub-size descriptor carries the parent's library metadata.
		$media = $response->get_data()['safe_publish_media'];
		$this->assertSame(
			array(
				'alt'         => 'Library alt',
				'title'       => 'Library title',
				'caption'     => 'Library caption',
				'description' => 'Library description',
				'parent'      => '0',
			),
			$media[ $image['sized_url'] ] ?? null
		);
	}

	/**
	 * Verifies that URLs outside the uploads directory are skipped before any
	 * attachment lookup, so page and other non-media links cost no query.
	 */
	public function test_field_skips_lookup_for_non_uploads_urls(): void {
		// ARRANGE: a post mixing an uploads image with same-host page links, and
		// a spy recording every URL that reaches attachment_url_to_postid().
		$image     = $this->seed_attachment( '2025/01/scanned-image.jpg' );
		$looked_up = array();
		add_filter(
			'pre_attachment_url_to_postid',
			static function ( ?int $result, string $url ) use ( &$looked_up ) {
				$looked_up[] = $url;
				return $result;
			},
			10,
			2
		);
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<a href="' . home_url( '/about' ) . '">a</a>'
					. ' <a href="' . home_url( '/contact' ) . '">c</a>'
					. ' <img src="' . $image['url'] . '" alt="x"/>',
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: the uploads image was looked up; the page links never were.
		$this->assertContains( $image['url'], $looked_up );
		$this->assertNotContains( home_url( '/about' ), $looked_up );
		$this->assertNotContains( home_url( '/contact' ), $looked_up );
	}

	/**
	 * Verifies that relocating the uploads directory still resolves inline media
	 * to its metadata, so the non-uploads skip never excludes a real attachment.
	 */
	public function test_field_resolves_metadata_under_relocated_uploads(): void {
		// ARRANGE: move uploads to /media, then seed and inline an attachment
		// that now lives under the relocated path.
		add_filter(
			'upload_dir',
			static function ( array $dir ): array {
				$dir['baseurl'] = home_url( '/media' );
				$dir['url']     = home_url( '/media' ) . (string) $dir['subdir'];
				return $dir;
			}
		);
		$image   = $this->seed_attachment( '2025/01/relocated-image.jpg' );
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $image['url'] . '" alt="x"/>' )
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: the relocated URL still carries the library metadata.
		$media = $response->get_data()['safe_publish_media'];
		$this->assertSame(
			array(
				'alt'         => 'Library alt',
				'title'       => 'Library title',
				'caption'     => 'Library caption',
				'description' => 'Library description',
				'parent'      => '0',
			),
			$media[ $image['url'] ] ?? null
		);
	}

	/**
	 * Verifies that URLs which do not resolve to a local attachment are omitted:
	 * a same-host page and file, and a third-party-host image (excluded by the
	 * host-anchored scan).
	 */
	public function test_field_omits_non_attachment_urls(): void {
		// ARRANGE: a post with a same-host page, a same-host non-attachment file,
		// and a third-party image.
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<a href="' . home_url( '/some-page' )
					. '">page</a> <img src="' . home_url( '/missing.jpg' )
					. '" alt="x"/> <img src="https://cdn.example.net/third.jpg"'
					. ' alt="y"/>',
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-post request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: nothing resolved, so the map is empty.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(),
			$response->get_data()['safe_publish_media']
		);
	}

	/**
	 * Verifies that the field is null on collection responses so list endpoints
	 * expose no library metadata.
	 */
	public function test_field_is_null_for_collection_request(): void {
		// ARRANGE: a post and an authenticated request.
		$image = $this->seed_attachment( '2025/01/collection-image.jpg' );
		self::factory()->post->create(
			array( 'post_content' => '<img src="' . $image['url'] . '" alt="x"/>' )
		);

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the collection request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts' )
		);

		// ASSERT: every row carries a null field.
		$this->assertSame( 200, $response->get_status() );
		foreach ( $response->get_data() as $row ) {
			$this->assertNull( $row['safe_publish_media'] );
		}
	}

	/**
	 * Verifies that the field is not registered on attachment responses.
	 */
	public function test_field_is_not_registered_on_attachments(): void {
		// ARRANGE: an attachment and an authenticated request.
		$image = $this->seed_attachment( '2025/01/attachment-image.jpg' );

		$this->force_hmac_authenticated( true );

		// ACT: dispatch the single-attachment request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/media/' . $image['id'] )
		);

		// ASSERT: the field key is absent.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey(
			'safe_publish_media',
			$response->get_data(),
			'safe_publish_media must not be registered on attachment responses.'
		);
	}

	/**
	 * Creates an attachment attached to a parent post with a MIME type and
	 * menu_order, seeding a child a bare gallery/playlist would render.
	 *
	 * @param int    $parent_id  Parent post the attachment belongs to.
	 * @param string $file       Uploads-relative file path.
	 * @param string $mime_type  Attachment MIME type.
	 * @param int    $menu_order Attachment menu_order.
	 * @return int Attachment ID.
	 */
	private function seed_attached_child(
		int $parent_id,
		string $file,
		string $mime_type,
		int $menu_order
	): int {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_parent'    => $parent_id,
				'post_mime_type' => $mime_type,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'menu_order'     => $menu_order,
			)
		);

		$this->assertIsInt( $attachment_id );

		return $attachment_id;
	}

	/**
	 * Dispatches an HMAC-authenticated single-post request and returns the
	 * safe_publish_attached_media field value.
	 *
	 * @param int $post_id Post to request.
	 * @return mixed Field value.
	 */
	private function attached_media_for( int $post_id ): mixed {
		$this->force_hmac_authenticated( true );

		return $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		)->get_data()['safe_publish_attached_media'] ?? null;
	}

	/**
	 * Verifies that a bare [gallery] lists the post's attached image children as
	 * { id, menu_order } records in render order (menu_order then ID), not
	 * creation order.
	 */
	public function test_attached_field_lists_bare_gallery_children_in_order(): void {
		// ARRANGE: A bare [gallery] with two images seeded so the lower-ID one
		// carries the higher menu_order, proving menu_order drives the order.
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Intro [gallery] outro' )
		);
		$second  = $this->seed_attached_child(
			$post_id,
			'2025/01/g-second.jpg',
			'image/jpeg',
			2
		);
		$first   = $this->seed_attached_child(
			$post_id,
			'2025/01/g-first.jpg',
			'image/jpeg',
			1
		);

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: Both images, ordered by menu_order, each carrying its order.
		$this->assertSame(
			array(
				array(
					'id'         => $first,
					'menu_order' => 1,
				),
				array(
					'id'         => $second,
					'menu_order' => 2,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a bare [gallery] collects only image children, skipping an
	 * attached audio file that the gallery would not render.
	 */
	public function test_attached_field_gallery_excludes_non_image_children(): void {
		// ARRANGE: A bare [gallery] with one image and one audio child attached.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[gallery]' )
		);
		$image   = $this->seed_attached_child(
			$post_id,
			'2025/01/only.jpg',
			'image/jpeg',
			1
		);
		$this->seed_attached_child( $post_id, '2025/01/skip.mp3', 'audio/mpeg', 2 );

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: Only the image child is returned.
		$this->assertSame(
			array(
				array(
					'id'         => $image,
					'menu_order' => 1,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a bare [playlist] collects the post's audio children (the
	 * shortcode default), skipping an attached image.
	 */
	public function test_attached_field_lists_bare_playlist_audio_children(): void {
		// ARRANGE: A bare [playlist] with an audio child and an image child.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[playlist]' )
		);
		$audio   = $this->seed_attached_child(
			$post_id,
			'2025/01/song.mp3',
			'audio/mpeg',
			1
		);
		$this->seed_attached_child( $post_id, '2025/01/cover.jpg', 'image/jpeg', 2 );

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: Only the audio child is returned.
		$this->assertSame(
			array(
				array(
					'id'         => $audio,
					'menu_order' => 1,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a bare [playlist type="video"] collects the post's video
	 * children instead of audio.
	 */
	public function test_attached_field_playlist_type_video_lists_video_children(): void {
		// ARRANGE: A [playlist type="video"] with a video and an audio child.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[playlist type="video"]' )
		);
		$video   = $this->seed_attached_child(
			$post_id,
			'2025/01/clip.mp4',
			'video/mp4',
			1
		);
		$this->seed_attached_child( $post_id, '2025/01/audio.mp3', 'audio/mpeg', 2 );

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: Only the video child is returned.
		$this->assertSame(
			array(
				array(
					'id'         => $video,
					'menu_order' => 1,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a [playlist] with a non-audio type collects video children,
	 * mirroring wp_playlist_shortcode's coercion of any non-audio type to video.
	 */
	public function test_attached_field_playlist_non_audio_type_collects_video(): void {
		// ARRANGE: A [playlist] with an unrecognized type, plus video and audio
		// children; core renders such a playlist as video.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[playlist type="jazz"]' )
		);
		$video   = $this->seed_attached_child(
			$post_id,
			'2025/01/reel.mp4',
			'video/mp4',
			1
		);
		$this->seed_attached_child( $post_id, '2025/01/track.mp3', 'audio/mpeg', 2 );

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: Only the video child is returned.
		$this->assertSame(
			array(
				array(
					'id'         => $video,
					'menu_order' => 1,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a [gallery ids="..."] returns an empty set: the id-bearing
	 * form is the shortcode-ID rewriter's domain, not this attached-set field.
	 */
	public function test_attached_field_empty_for_ids_gallery(): void {
		// ARRANGE: A [gallery ids=...] with an attached image the field must
		// not pull in.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[gallery ids="10,11"]' )
		);
		$this->seed_attached_child( $post_id, '2025/01/lib.jpg', 'image/jpeg', 1 );

		// ACT + ASSERT: The field returns an empty set.
		$this->assertSame( array(), $this->attached_media_for( $post_id ) );
	}

	/**
	 * Verifies that an empty selector attribute (ids="") leaves the shortcode
	 * bare, mirroring core's ! empty() gate, so the attached set is exposed.
	 */
	public function test_attached_field_treats_empty_ids_as_bare(): void {
		// ARRANGE: A [gallery ids=""] whose empty selector core treats as bare.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[gallery ids=""]' )
		);
		$image   = $this->seed_attached_child(
			$post_id,
			'2025/01/empty-ids.jpg',
			'image/jpeg',
			1
		);

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: The own image is returned.
		$this->assertSame(
			array(
				array(
					'id'         => $image,
					'menu_order' => 1,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a [gallery id="B"] naming another post returns an empty set,
	 * since the field only exposes a post's own attached media.
	 */
	public function test_attached_field_empty_for_cross_post_gallery(): void {
		// ARRANGE: A cross-post gallery plus an own attached image.
		$other   = self::factory()->post->create();
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[gallery id="' . $other . '"]' )
		);
		$this->seed_attached_child( $post_id, '2025/01/own.jpg', 'image/jpeg', 1 );

		// ACT + ASSERT: The field returns an empty set.
		$this->assertSame( array(), $this->attached_media_for( $post_id ) );
	}

	/**
	 * Verifies that a [gallery id="SELF"] naming the post itself is treated as
	 * bare and lists the post's own attached images.
	 */
	public function test_attached_field_treats_self_id_gallery_as_bare(): void {
		// ARRANGE: Create the post, then set content referencing its own ID.
		$post_id = self::factory()->post->create();
		$this->assertIsInt( $post_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '[gallery id="' . $post_id . '"]',
			)
		);
		$image = $this->seed_attached_child(
			$post_id,
			'2025/01/self.jpg',
			'image/jpeg',
			1
		);

		// ACT: Request the field.
		$set = $this->attached_media_for( $post_id );

		// ASSERT: The own image is returned.
		$this->assertSame(
			array(
				array(
					'id'         => $image,
					'menu_order' => 1,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that an escaped [[gallery]] literal, which renders as text rather
	 * than a live gallery, exposes no attached media.
	 */
	public function test_attached_field_ignores_escaped_gallery(): void {
		// ARRANGE: An escaped [[gallery]] plus an attached image the field must
		// not expose.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[[gallery]]' )
		);
		$this->seed_attached_child( $post_id, '2025/01/escaped.jpg', 'image/jpeg', 1 );

		// ACT + ASSERT: The field returns an empty set.
		$this->assertSame( array(), $this->attached_media_for( $post_id ) );
	}

	/**
	 * Verifies that a post without a bare gallery/playlist returns an empty set,
	 * so the whole attached library is never exposed.
	 */
	public function test_attached_field_empty_without_bare_shortcode(): void {
		// ARRANGE: Plain content plus an attached image, no shortcode.
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Just text, no shortcodes.' )
		);
		$this->seed_attached_child( $post_id, '2025/01/lib.jpg', 'image/jpeg', 1 );

		// ACT + ASSERT: The field returns an empty set.
		$this->assertSame( array(), $this->attached_media_for( $post_id ) );
	}

	/**
	 * Verifies that the attached-media field is null for requests not
	 * authenticated by Safe Publish HMAC.
	 */
	public function test_attached_field_is_null_for_non_hmac_request(): void {
		// ARRANGE: A bare-gallery post, no HMAC auth.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[gallery]' )
		);

		// ACT: Dispatch a public single-post request.
		$data = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		)->get_data();

		// ASSERT: Field present (registered) but null.
		$this->assertArrayHasKey( 'safe_publish_attached_media', $data );
		$this->assertNull( $data['safe_publish_attached_media'] );
	}
}
