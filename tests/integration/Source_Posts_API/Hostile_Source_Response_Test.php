<?php
/**
 * Tests that the destination's catalog-response normalizer defends
 * against hostile content from a compromised source.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Hostile Source Response Test.
 *
 * HMAC authenticates the source's identity, not its honesty. The
 * destination's normalize_listing_item must defend against payloads that
 * would otherwise be interpolated into the catalog UI's HTML attributes
 * or CSS class names. These tests pin the link-scheme and status-allowlist
 * clamping that prevents XSS / open-redirect via a compromised source.
 */
class Hostile_Source_Response_Test extends Integration_Test_Case {

	/**
	 * Source URL the mocked catalog endpoint is rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Body returned by the mocked catalog response, set per test.
	 *
	 * @var string
	 */
	private string $mock_body = '';

	/**
	 * Sets the connected URL and registers the HTTP mock.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE_SITE_URL );
		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5, 3 );
	}

	/**
	 * Cleans up the HTTP mock and the connected-URL option.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the mocked catalog response.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $args    HTTP request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return array Mock HTTP response.
	 */
	public function intercept_http_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): array {
		unset( $preempt, $args, $url );

		return array(
			'headers'  => array(),
			'body'     => $this->mock_body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Builds the mocked envelope body around a single item.
	 *
	 * @param array $item Listing item payload.
	 * @return string Encoded envelope.
	 */
	private function envelope_with( array $item ): string {
		return (string) wp_json_encode(
			array(
				'items'    => array( $item ),
				'has_more' => false,
			)
		);
	}

	/**
	 * Verifies that a hostile status value (one that would inject
	 * arbitrary HTML attributes via the safe-publish-status-badge--<x>
	 * className template) is clamped to an empty string.
	 */
	public function test_hostile_status_is_clamped_to_empty_string(): void {
		// ARRANGE: Source returns a status value engineered to break out of
		// the className attribute on the destination's React render.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/post',
				'title'        => 'Hostile status',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'publish onmouseover=alert(1)',
			)
		);

		// ACT: Fetch via the destination's API.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( self::SOURCE_SITE_URL );

		// ASSERT: Status was not allowlisted, so the destination drops it.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( '', $result['items'][0]['status'] );
	}

	/**
	 * Verifies that an allowlisted status value passes through unchanged.
	 */
	public function test_allowlisted_status_passes_through(): void {
		// ARRANGE: Source returns an honest status.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/post',
				'title'        => 'Honest status',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'draft',
			)
		);

		// ACT.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( self::SOURCE_SITE_URL );

		// ASSERT: Status survives intact.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'draft', $result['items'][0]['status'] );
	}

	/**
	 * Verifies that a hostile link (e.g. javascript:) is stripped to
	 * an empty string by the http/https allowlist on esc_url_raw.
	 */
	public function test_hostile_link_scheme_is_stripped(): void {
		// ARRANGE: Source returns a javascript: URL that would otherwise
		// render as an active anchor href on the destination.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'javascript:fetch("/wp-admin/admin-ajax.php")',
				'title'        => 'Hostile link',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'publish',
			)
		);

		// ACT.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( self::SOURCE_SITE_URL );

		// ASSERT: esc_url_raw with an http/https allowlist returns an empty
		// string for any other scheme.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( '', $result['items'][0]['link'] );
	}

	/**
	 * Verifies that http and https links pass through unchanged.
	 */
	public function test_http_and_https_links_pass_through(): void {
		// ARRANGE.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/post-1',
				'title'        => 'Honest link',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'publish',
			)
		);

		// ACT.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( self::SOURCE_SITE_URL );

		// ASSERT.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame(
			'https://source.example.com/post-1',
			$result['items'][0]['link']
		);
	}
}
