<?php
/**
 * Tests for the title field's contract in the listing payload.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\Catalog_REST_Controller;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;

/**
 * Title Field Test Class.
 *
 * UI/security contract: titles are emitted as plain text (HTML entities
 * decoded, tags stripped) so the destination listing UI can render them
 * directly without raw entity markup or smuggled tags.
 */
class Title_Field_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source Posts API instance.
	 *
	 * @var Source_Posts_API
	 */
	private Source_Posts_API $api;

	/**
	 * Sets up the API instance reused by each case.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->api = new Source_Posts_API( new HTTP_Client() );
	}

	/**
	 * Builds a local published post with the given title and runs it through
	 * the source-side listing preparer.
	 *
	 * The destination's normalize_listing_item only re-sanitizes already-
	 * prepared payloads; the contract that the preparer must decode entities
	 * before stripping tags lives entirely on the source side, so this test
	 * exercises it directly.
	 *
	 * @param string $raw_title Title written to the post (entities still encoded).
	 * @return string Prepared title from the listing payload.
	 */
	private function prepared_title_for( string $raw_title ): string {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => $raw_title,
			)
		);

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		$prepared = Catalog_REST_Controller::prepare_listing_payload_from_post( $post );

		return $prepared['title'];
	}

	/**
	 * Verifies that numeric HTML entities stored in post_title are decoded
	 * to their literal characters in the listing payload.
	 */
	public function test_prepared_title_decodes_numeric_entities(): void {
		// ARRANGE + ACT: en-dash stored as &#8211; in post_title.
		$title = $this->prepared_title_for( 'Post 19 &#8211; 1P' );

		// ASSERT: Listing UI receives the literal en-dash.
		$this->assertSame( 'Post 19 – 1P', $title );
	}

	/**
	 * Verifies that named HTML entities are decoded too — covers the &amp;
	 * case for ampersands in titles.
	 */
	public function test_prepared_title_decodes_named_entities(): void {
		// ARRANGE + ACT: ampersand stored as &amp; in post_title.
		$title = $this->prepared_title_for( 'Tom &amp; Jerry' );

		// ASSERT: Listing UI receives the literal ampersand.
		$this->assertSame( 'Tom & Jerry', $title );
	}

	/**
	 * Verifies that decoded tag-shaped entities are stripped, not preserved
	 * as literal markup. Entities must decode before tags are stripped, so
	 * `&lt;script&gt;X&lt;/script&gt;` resolves to `X` in the payload — never
	 * to literal `<script>X</script>` text that downstream consumers might
	 * later render as HTML.
	 */
	public function test_prepared_title_strips_tags_after_decoding_entities(): void {
		// ARRANGE + ACT: encoded script tag stored in post_title.
		$title = $this->prepared_title_for(
			'Title &lt;script&gt;alert(1)&lt;/script&gt;'
		);

		// ASSERT: The script tag and its contents are gone. Under the
		// old strip-then-decode order, the entities would have survived the
		// strip and decoded into literal `<script>alert(1)</script>` text.
		$this->assertSame( 'Title', $title );
	}
}
