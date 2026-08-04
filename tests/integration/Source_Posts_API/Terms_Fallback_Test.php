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
}
