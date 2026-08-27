<?php
/**
 * Tests the embedded-terms fallback when safe_publish_terms is absent.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Source_Posts_API;

/**
 * Terms Fallback Test.
 *
 * A source running an older plugin sends no safe_publish_terms field, so
 * fetch_fresh_post_content() must fall back to the minimal embedded wp:term
 * payload and keep importing terms flat, exactly as before the field existed.
 * Only the field can carry a taxonomy as present but empty, so the fallback
 * never reaches the importer's clear branch.
 */
class Terms_Fallback_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source URL the mocked endpoint is rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Source Posts API under test.
	 *
	 * @var Source_Posts_API
	 */
	private Source_Posts_API $api;

	/**
	 * Sets up the API under test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->api = new Source_Posts_API( new HTTP_Client() );
	}

	/**
	 * Verifies that a response carrying only embedded wp:term data (no
	 * safe_publish_terms field) yields the flat name/slug records the importer
	 * expected before the field existed.
	 */
	public function test_absent_field_falls_back_to_embedded_terms(): void {
		// ARRANGE: A mocked post whose only term data is the embedded payload.
		$this->mock_post_overrides = array(
			'terms' => array(
				'category' => array( 'Fallback Cat', 'Another Cat' ),
			),
		);

		// ACT: Fetch the post.
		$result = $this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			array(),
			'post'
		);

		// ASSERT: Terms come from the embed, flat, with no parent or
		// description.
		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'category' => array(
					array(
						'source_term_id' => 0,
						'slug'           => '',
						'name'           => 'Fallback Cat',
					),
					array(
						'source_term_id' => 0,
						'slug'           => '',
						'name'           => 'Another Cat',
					),
				),
			),
			$result['terms']
		);
	}

	/**
	 * Verifies that an older source, which sends no safe_publish_terms field,
	 * never yields an empty taxonomy, so the fallback path cannot clear terms.
	 */
	public function test_embedded_fallback_never_yields_an_empty_taxonomy(): void {
		// ARRANGE: An embedded payload whose only taxonomy has no usable term.
		$this->mock_post_overrides = array(
			'terms' => array( 'post_tag' => array( '' ) ),
		);

		// ACT: Fetch the post.
		$result = $this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			array(),
			'post'
		);

		// ASSERT: The taxonomy is absent rather than empty.
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['terms'] );
	}

	/**
	 * Verifies that the embedded fallback keys a taxonomy the way the import
	 * writes it, so a loosely named one still reaches the same terms.
	 */
	public function test_embedded_fallback_sanitizes_the_taxonomy_key(): void {
		// ARRANGE: A source naming the taxonomy in a form register_taxonomy
		// permits and the import narrows at write time.
		$this->mock_post_overrides = array(
			'terms' => array( 'Category' => array( 'Narrowed' ) ),
		);

		// ACT: Fetch the post.
		$result = $this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			array(),
			'post'
		);

		// ASSERT: The key arrives narrowed, as update_terms() writes it.
		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'category' ),
			array_keys( $result['terms'] )
		);
	}

	/**
	 * Verifies that a taxonomy whose name survives no sanitizing is dropped
	 * rather than collected under an empty key.
	 */
	public function test_embedded_fallback_drops_an_unusable_key(): void {
		// ARRANGE: A taxonomy name with nothing sanitize_key keeps.
		$this->mock_post_overrides = array(
			'terms' => array( '@@@' => array( 'Orphan' ) ),
		);

		// ACT: Fetch the post.
		$result = $this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			array(),
			'post'
		);

		// ASSERT: Nothing is collected.
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['terms'] );
	}

	/**
	 * Verifies that a taxonomy the source sent empty survives the JSON round
	 * trip as an empty list, reaching the importer as the signal to clear.
	 */
	public function test_empty_taxonomy_survives_the_fetch(): void {
		// ARRANGE: A source sending post_tag present but empty, alongside a
		// taxonomy that still carries a term.
		$this->mock_post_overrides = array(
			'safe_publish_terms' => array(
				'post_tag' => array(),
				'category' => array(
					array(
						'id'   => 7,
						'name' => 'Kept',
						'slug' => 'kept',
					),
				),
			),
		);

		// ACT: Fetch the post.
		$result = $this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			array(),
			'post'
		);

		// ASSERT: The empty taxonomy arrives as an empty list, the populated
		// one unaffected.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_tag', $result['terms'] );
		$this->assertSame( array(), $result['terms']['post_tag'] );
		$this->assertSame(
			array( 'Kept' ),
			array_column( $result['terms']['category'], 'name' )
		);
	}
}
