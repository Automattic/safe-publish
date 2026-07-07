<?php
/**
 * Tests that untitled source posts stay visible in the catalog listing.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Tests\Integration\Mock_Catalog_Response_Trait;

/**
 * Empty Title Test.
 *
 * The destination's normalize_listing_item substitutes a "(no title)"
 * placeholder for an empty catalog title instead of dropping the item, so
 * untitled source posts stay visible instead of leaving a phantom gap.
 */
class Empty_Title_Test extends Integration_Test_Case {

	use Mock_Catalog_Response_Trait;

	/**
	 * Registers the catalog HTTP mock.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->register_catalog_mock();
	}

	/**
	 * Unregisters the catalog HTTP mock.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->unregister_catalog_mock();
		parent::tearDown();
	}

	/**
	 * Verifies that a source item with an empty title comes back with a
	 * "(no title)" placeholder and is not dropped from the listing.
	 */
	public function test_empty_title_becomes_placeholder_and_survives(): void {
		// ARRANGE: Otherwise-valid source item with no title.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/untitled',
				'title'        => '',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'publish',
			)
		);

		// ACT: Fetch the catalog.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( $this->source_site_url );

		// ASSERT: Item survives with the placeholder title.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( '(no title)', $result['items'][0]['title'] );
	}

	/**
	 * Verifies that a title of only a non-breaking space, which survives the
	 * source's sanitize_text_field, is treated as empty and replaced with the
	 * "(no title)" placeholder instead of rendering as a blank row.
	 */
	public function test_whitespace_only_title_becomes_placeholder(): void {
		// ARRANGE: Source item whose title is a lone non-breaking space.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/nbsp-title',
				'title'        => "\u{00A0}",
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'publish',
			)
		);

		// ACT: Fetch the catalog.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( $this->source_site_url );

		// ASSERT: The whitespace title is replaced with the placeholder.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( '(no title)', $result['items'][0]['title'] );
	}

	/**
	 * Verifies that an item without a usable id is still dropped, so the
	 * placeholder substitution didn't weaken the shape guard.
	 */
	public function test_item_without_id_is_still_dropped(): void {
		// ARRANGE: Source item missing its id.
		$this->mock_body = $this->envelope_with(
			array(
				'link'      => 'https://source.example.com/no-id',
				'title'     => 'Has a title but no id',
				'post_type' => 'post',
				'status'    => 'publish',
			)
		);

		// ACT: Fetch the catalog.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( $this->source_site_url );

		// ASSERT: The id guard drops it.
		$this->assertIsArray( $result );
		$this->assertCount( 0, $result['items'] );
	}
}
