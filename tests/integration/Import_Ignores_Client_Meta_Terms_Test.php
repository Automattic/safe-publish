<?php
/**
 * Verifies imported meta and terms come from the source, not the request.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_Ajax_UnitTestCase;

/**
 * Import Ignores Client Meta Terms Test Class.
 *
 * Both import AJAX handlers accept a decoded post-data payload that can carry
 * meta and terms. The fresh source payload fetched during import is the sole
 * authority for both, so request-supplied values must never reach the post.
 * These tests drive the single (ajax_create_draft) and bulk (ajax_bulk_import)
 * handlers with a request that smuggles meta and terms, and assert only the
 * source values land.
 */
class Import_Ignores_Client_Meta_Terms_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;
	use Bulk_Import_Ajax_Trait;

	/**
	 * Connected source site URL for the batch.
	 */
	private const CONNECTION = 'https://source.example.com';

	/**
	 * Meta key the fresh source payload carries; must be imported.
	 */
	private const SOURCE_META_KEY = 'sp_source_meta';

	/**
	 * Meta value the fresh source payload carries.
	 */
	private const SOURCE_META_VALUE = 'from-source';

	/**
	 * Term name the fresh source payload carries; must be assigned.
	 */
	private const SOURCE_TERM_NAME = 'Source Tag';

	/**
	 * Protected meta key the request smuggles; must not be written.
	 */
	private const INJECTED_PROTECTED_META = '_sp_injected_protected';

	/**
	 * Custom meta key the request smuggles; must not be written.
	 */
	private const INJECTED_META = 'sp_injected';

	/**
	 * Term name the request smuggles; must not be created or assigned.
	 */
	private const INJECTED_TERM_NAME = 'Injected Category';

	/**
	 * Sets up the import harness against the single-site connection.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->set_up_bulk_import_harness( self::CONNECTION );
	}

	/**
	 * Tears down the import harness.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->tear_down_bulk_import_harness();
		parent::tearDown();
	}

	/**
	 * Builds the fresh source payload for a registered ID, adding a meta value
	 * and an embedded tag so the tests can prove source data is imported.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( ! isset( $this->source_payloads[ $source_id ] ) ) {
			return null;
		}

		$body              = $this->bulk_mock_body( $source_id, self::CONNECTION );
		$body['meta']      = array( self::SOURCE_META_KEY => self::SOURCE_META_VALUE );
		$body['_embedded'] = array(
			'wp:term' => array(
				array(
					array(
						'id'       => 4321,
						'taxonomy' => 'post_tag',
						'slug'     => 'source-tag',
						'name'     => self::SOURCE_TERM_NAME,
					),
				),
			),
		);

		return $body;
	}

	/**
	 * Verifies that the single-import handler imports source meta and terms
	 * while ignoring meta and terms supplied in the request.
	 */
	public function test_single_import_ignores_client_meta_and_terms(): void {
		// ARRANGE: A source post plus a request that smuggles meta and terms.
		$this->source_payloads = array( 700 => array() );

		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => 700,
			'title'          => 'Source Post 700',
			'source_link'    => self::CONNECTION . '/post-700',
			'post_type'      => 'pages',
			'meta'           => wp_json_encode(
				array(
					self::INJECTED_PROTECTED_META => 'x',
					self::INJECTED_META           => 'y',
				)
			),
			'terms'          => wp_json_encode(
				array( 'category' => array( self::INJECTED_TERM_NAME ) )
			),
		);

		// ACT: Dispatch the single import.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );
		$data = $this->decode_success_response();

		// ASSERT: Source meta and term landed; request meta and term did not.
		$post_id = (int) $data['post_id'];
		$this->assert_source_meta_and_term_applied( $post_id );
		$this->assert_client_meta_and_term_absent( $post_id );
	}

	/**
	 * Verifies that the bulk-import handler imports source meta and terms while
	 * ignoring meta and terms carried on the request item.
	 */
	public function test_bulk_import_ignores_client_meta_and_terms(): void {
		// ARRANGE: A source post whose request item smuggles meta and terms.
		$this->source_payloads = array( 800 => array() );

		// ACT: Dispatch the bulk import.
		$data = $this->dispatch_bulk_import(
			array(
				array(
					'id'        => 800,
					'title'     => 'Source Post 800',
					'link'      => self::CONNECTION . '/post-800',
					'post_type' => 'pages',
					'meta'      => array(
						self::INJECTED_PROTECTED_META => 'x',
						self::INJECTED_META           => 'y',
					),
					'terms'     => array( 'category' => array( self::INJECTED_TERM_NAME ) ),
				),
			)
		);

		// ASSERT: Source meta and term landed; request meta and term did not.
		$this->assertSame( 1, $data['successful'] );
		$post_id = (int) $data['results'][0]['post_id'];
		$this->assert_source_meta_and_term_applied( $post_id );
		$this->assert_client_meta_and_term_absent( $post_id );
	}

	/**
	 * Decodes the last AJAX response and asserts it reported success.
	 *
	 * @return array Response data payload.
	 */
	private function decode_success_response(): array {
		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['success'] );

		return $decoded['data'];
	}

	/**
	 * Asserts the imported post carries the fresh source meta and term.
	 *
	 * @param int $post_id Imported destination post ID.
	 */
	private function assert_source_meta_and_term_applied( int $post_id ): void {
		$this->assertSame(
			self::SOURCE_META_VALUE,
			get_post_meta( $post_id, self::SOURCE_META_KEY, true ),
			'Fresh source meta must be imported.'
		);
		$this->assertContains(
			self::SOURCE_TERM_NAME,
			wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ),
			'Fresh source term must be assigned.'
		);
	}

	/**
	 * Asserts none of the request-supplied meta or terms reached the post.
	 *
	 * @param int $post_id Imported destination post ID.
	 */
	private function assert_client_meta_and_term_absent( int $post_id ): void {
		$this->assertSame(
			'',
			get_post_meta( $post_id, self::INJECTED_PROTECTED_META, true ),
			'Request-supplied protected meta must not be written.'
		);
		$this->assertSame(
			'',
			get_post_meta( $post_id, self::INJECTED_META, true ),
			'Request-supplied meta must not be written.'
		);
		$this->assertFalse(
			get_term_by( 'name', self::INJECTED_TERM_NAME, 'category' ),
			'Request-supplied term must not be created or assigned.'
		);
	}
}
