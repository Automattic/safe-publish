<?php
/**
 * Integration tests for the Source_Author_REST_Field class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\API;

use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\API\Source_Author_REST_Field;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Source Author REST Field Test Class.
 *
 * Access control: the safe_publish_author REST field is populated only for
 * Safe Publish HMAC-authenticated requests, and its payload reflects the
 * source post's author at the time of the request.
 */
class Source_Author_REST_Field_Test extends WP_UnitTestCase {

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

		( new Source_Author_REST_Field( $this->authenticator ) )->init();

		register_post_type(
			'sp_event',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'sp_events',
			)
		);

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );
	}

	/**
	 * Cleans up post types and the global REST server between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		unregister_post_type( 'sp_event' );

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Verifies that the safe_publish_author field is null for requests that
	 * were not authenticated by Safe Publish HMAC.
	 */
	public function test_field_is_null_for_non_hmac_request(): void {
		// ARRANGE: An administrator-owned post, no HMAC auth.
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'author@source.example',
				'user_login' => 'source-author',
			)
		);
		$post_id  = self::factory()->post->create( array( 'post_author' => $admin_id ) );

		// ACT: Dispatch a public REST request — authenticator is not authenticated.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: Field is present (registered) but null — no PII exposed.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'safe_publish_author', $data );
		$this->assertNull( $data['safe_publish_author'] );
	}

	/**
	 * Verifies that the safe_publish_author field carries author email, login,
	 * and display name when the request is HMAC-authenticated.
	 */
	public function test_field_is_populated_for_hmac_authenticated_request(): void {
		// ARRANGE: A post owned by a user with known credentials.
		$author_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'user_email'   => 'jane@source.example',
				'user_login'   => 'jane',
				'display_name' => 'Jane Doe',
			)
		);
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch a single-post REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: Field contains the source author's email, login, and display name.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'safe_publish_author', $data );
		$this->assertSame(
			array(
				'email'        => 'jane@source.example',
				'login'        => 'jane',
				'display_name' => 'Jane Doe',
			),
			$data['safe_publish_author']
		);
	}

	/**
	 * Verifies that an HMAC-authenticated response carries empty strings when
	 * the source post has no author (post_author = 0).
	 */
	public function test_field_is_empty_strings_when_post_has_no_author(): void {
		// ARRANGE: A post explicitly attributed to user ID 0.
		$post_id = self::factory()->post->create( array( 'post_author' => 0 ) );

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch the single-post REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: Field present, all sub-fields empty.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array(
				'email'        => '',
				'login'        => '',
				'display_name' => '',
			),
			$data['safe_publish_author']
		);
	}

	/**
	 * Verifies that an HMAC-authenticated response carries empty strings when
	 * the post_author references a user that no longer exists on the source.
	 *
	 * Uses a non-existent author ID directly because wp_delete_user() also
	 * deletes the user's posts (and would not leave an orphan post_author
	 * reference behind to exercise the get_userdata() === false branch).
	 */
	public function test_field_is_empty_strings_when_source_user_is_deleted(): void {
		// ARRANGE: Attribute a post to an author ID that does not exist.
		$post_id = self::factory()->post->create( array( 'post_author' => 999999 ) );

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch the single-post REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: Field present, all sub-fields empty for the orphaned author ID.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array(
				'email'        => '',
				'login'        => '',
				'display_name' => '',
			),
			$data['safe_publish_author']
		);
	}

	/**
	 * Verifies that the field is registered on the built-in page post type so
	 * the destination can import pages with their source author.
	 */
	public function test_field_is_registered_on_pages(): void {
		// ARRANGE: A page attributed to a known user.
		$author_id = self::factory()->user->create(
			array(
				'user_email' => 'page-author@source.example',
				'user_login' => 'pageauthor',
			)
		);
		$page_id   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $author_id,
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch the single-page REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page_id )
		);

		// ASSERT: Field contains the page author's email and login.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			'page-author@source.example',
			$data['safe_publish_author']['email']
		);
		$this->assertSame( 'pageauthor', $data['safe_publish_author']['login'] );
	}

	/**
	 * Verifies that the field is NOT registered on attachments.
	 *
	 * Attachments are public and REST-exposed but the destination only reads
	 * source_url from media responses. Excluding them avoids transmitting
	 * uploader PII on every featured-image fetch.
	 */
	public function test_field_is_not_registered_on_attachments(): void {
		// ARRANGE: A media attachment owned by a known user.
		$author_id     = self::factory()->user->create(
			array(
				'user_email' => 'media-author@source.example',
				'user_login' => 'mediaauthor',
			)
		);
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'test.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_author'    => $author_id,
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch the single-attachment REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment_id )
		);

		// ASSERT: Field key is not present in the response.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey(
			'safe_publish_author',
			$response->get_data(),
			'safe_publish_author must not be registered on attachment responses.'
		);
	}

	/**
	 * Verifies that the field is registered on public, REST-exposed custom
	 * post types.
	 */
	public function test_field_is_registered_on_custom_public_post_type(): void {
		// ARRANGE: A custom-post-type entry attributed to a known user.
		$author_id = self::factory()->user->create(
			array(
				'user_email' => 'event-author@source.example',
				'user_login' => 'eventauthor',
			)
		);
		$event_id  = self::factory()->post->create(
			array(
				'post_type'   => 'sp_event',
				'post_author' => $author_id,
			)
		);

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch the custom-post-type REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/sp_events/' . $event_id )
		);

		// ASSERT: Field is registered and reflects the post's author.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'safe_publish_author', $data );
		$this->assertSame( 'eventauthor', $data['safe_publish_author']['login'] );
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
}
