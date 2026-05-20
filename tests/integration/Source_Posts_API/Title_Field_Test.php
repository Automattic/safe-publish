<?php
/**
 * Tests for the title field's contract in the listing payload.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

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
	 * Drives a single fetch_posts() call against a mocked listing endpoint
	 * with the given rendered title, returning the prepared title.
	 *
	 * @param string $rendered_title Value placed in title.rendered.
	 * @return string Prepared title from the listing payload.
	 */
	private function prepared_title_for( string $rendered_title ): string {
		$body = (string) wp_json_encode(
			array(
				array(
					'id'           => 1,
					'link'         => 'https://source.example.com/post',
					'title'        => array( 'rendered' => $rendered_title ),
					'modified_gmt' => '2024-07-15T15:00:00',
				),
			)
		);

		$callback = static function ( $preempt, $args, $url ) use ( $body ) {
			unset( $args );
			if ( false !== $preempt ) {
				return $preempt;
			}
			if ( ! str_contains( $url, '/wp-json/wp/v2/posts?' ) ) {
				return $preempt;
			}
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => $body,
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 5, 3 );

		try {
			$result = $this->api->fetch_posts(
				'https://source.example.com',
				1
			);
		} finally {
			remove_filter( 'pre_http_request', $callback, 5 );
		}

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );

		return $result[0]['title'];
	}

	/**
	 * Verifies that numeric HTML entities the REST API emits in title.rendered
	 * are decoded to their literal characters in the listing payload.
	 */
	public function test_prepared_title_decodes_numeric_entities(): void {
		// ARRANGE + ACT: en-dash arrives as &#8211; in the REST response.
		$title = $this->prepared_title_for( 'Post 19 &#8211; 1P' );

		// ASSERT: Listing UI receives the literal en-dash.
		$this->assertSame( 'Post 19 – 1P', $title );
	}

	/**
	 * Verifies that named HTML entities are decoded too — covers the &amp;
	 * case the REST API uses for ampersands in titles.
	 */
	public function test_prepared_title_decodes_named_entities(): void {
		// ARRANGE + ACT: ampersand arrives as &amp; in the REST response.
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
		// ARRANGE + ACT: encoded script tag arrives in the REST response.
		$title = $this->prepared_title_for(
			'Title &lt;script&gt;alert(1)&lt;/script&gt;'
		);

		// ASSERT: The script tag and its contents are gone. Under the
		// old strip-then-decode order, the entities would have survived the
		// strip and decoded into literal `<script>alert(1)</script>` text.
		$this->assertSame( 'Title', $title );
	}
}
