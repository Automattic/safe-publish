<?php
/**
 * Integration tests for the Source_Terms_REST_Field class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\API;

use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\API\Source_Terms_REST_Field;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use ReflectionClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Source Terms REST Field Test Class.
 *
 * The field is source-canonical: A taxonomy the post holds no terms in travels
 * as an empty list so the destination clears it, which is what separates
 * "cleared on the source" from "not sent".
 */
class Source_Terms_REST_Field_Test extends WP_UnitTestCase {

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

		( new Source_Terms_REST_Field( $this->authenticator ) )->init();

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );
	}

	/**
	 * Cleans up the global REST server between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Verifies that a taxonomy the post holds no terms in is emitted as an
	 * empty list, the signal the destination clears on.
	 */
	public function test_taxonomy_without_terms_is_emitted_empty(): void {
		// ARRANGE: A post with a category but no tags. Hierarchical taxonomies
		// resolve names as IDs, so the category is assigned by ID.
		$post_id     = self::factory()->post->create();
		$category_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Kept',
			)
		);
		wp_set_post_terms( $post_id, array( $category_id ), 'category' );

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch a single-post REST request.
		$field = $this->fetch_field( $post_id );

		// ASSERT: post_tag travels as an empty list, category as its records.
		$this->assertSame( array(), $field['post_tag'] );
		$this->assertSame( 'Kept', $field['category'][0]['name'] );
	}

	/**
	 * Verifies that a taxonomy the post holds terms in is unchanged, carrying
	 * its assigned records.
	 */
	public function test_taxonomy_with_terms_is_unchanged(): void {
		// ARRANGE: A post carrying one tag.
		$post_id = self::factory()->post->create();
		wp_set_post_terms( $post_id, array( 'Featured' ), 'post_tag' );

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch a single-post REST request.
		$field = $this->fetch_field( $post_id );

		// ASSERT: The assigned tag is carried as a record.
		$this->assertSame( 'Featured', $field['post_tag'][0]['name'] );
		$this->assertTrue( $field['post_tag'][0]['assigned'] );
	}

	/**
	 * Verifies that a taxonomy whose terms cannot be read is omitted rather
	 * than emitted empty, so an undeterminable assignment never clears.
	 */
	public function test_undeterminable_taxonomy_is_omitted(): void {
		// ARRANGE: A post whose post_tag lookup errors.
		$post_id = self::factory()->post->create();
		wp_set_post_terms( $post_id, array( 'Featured' ), 'post_tag' );

		$to_error = static fn( $terms, $_post_id, $taxonomy ) =>
			'post_tag' === $taxonomy
				? new WP_Error( 'invalid_taxonomy', 'Simulated failure.' )
				: $terms;
		add_filter( 'get_the_terms', $to_error, 10, 3 );

		$this->force_hmac_authenticated( true );

		// ACT: Dispatch a single-post REST request.
		$field = $this->fetch_field( $post_id );

		remove_filter( 'get_the_terms', $to_error, 10 );

		// ASSERT: post_tag is absent, while category is still collected.
		$this->assertArrayNotHasKey( 'post_tag', $field );
		$this->assertNotSame( array(), $field['category'] );
	}

	/**
	 * Verifies that an unauthenticated request carries no field value, so an
	 * older or third-party consumer cannot read the emptiness signal.
	 */
	public function test_field_is_null_for_non_hmac_request(): void {
		// ARRANGE: A post with no tags, and no HMAC authentication.
		$post_id = self::factory()->post->create();

		// ACT: Dispatch a public single-post REST request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		// ASSERT: The field is registered but null.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'safe_publish_terms', $data );
		$this->assertNull( $data['safe_publish_terms'] );
	}

	/**
	 * Dispatches a single-post request and returns the field value.
	 *
	 * @param int $post_id Post to request.
	 * @return array<string, mixed> The safe_publish_terms field value.
	 */
	private function fetch_field( int $post_id ): array {
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['safe_publish_terms'] );

		return $data['safe_publish_terms'];
	}

	/**
	 * Forces the HMAC authenticator's authenticated flag for tests that do not
	 * sign a real request.
	 *
	 * @param bool $authenticated Value to force.
	 */
	private function force_hmac_authenticated( bool $authenticated ): void {
		$reflection = new ReflectionClass( $this->authenticator );
		$property   = $reflection->getProperty( 'authenticated' );
		$property->setValue( $this->authenticator, $authenticated );
	}
}
