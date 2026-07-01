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
 * own media URLs to the raw library metadata on each attachment record.
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
	 * ID and resolvable URL.
	 *
	 * @param string $file Uploads-relative file path.
	 * @return array{id: int, url: string}
	 */
	private function seed_attachment( string $file ): array {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Library title',
				'post_excerpt'   => 'Library caption',
				'post_content'   => 'Library description',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Library alt' );

		return array(
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_url( $attachment_id ),
		);
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
}
