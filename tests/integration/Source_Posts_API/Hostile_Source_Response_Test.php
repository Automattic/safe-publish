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
use Safe_Publish\Tests\Integration\Mock_Catalog_Response_Trait;

/**
 * Hostile Source Response Test.
 *
 * HMAC authenticates the source's identity, not its honesty. The
 * destination's normalize_listing_item must defend against payloads that
 * would otherwise be interpolated into the catalog UI. These tests pin the
 * link-scheme allowlist (blocks a javascript: href) and the status slug
 * sanitization (bounds the value to a CSS-class-safe token).
 */
class Hostile_Source_Response_Test extends Integration_Test_Case {

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
	 * Verifies that a hostile status value (one that would inject arbitrary
	 * characters into the safe-publish-status-badge--<x> className) is reduced
	 * to a safe [a-z0-9_-] token rather than reaching the destination raw.
	 */
	public function test_hostile_status_is_sanitized_to_safe_token(): void {
		// ARRANGE: Source returns a status carrying quotes, angle brackets, and
		// a script payload — characters that must not survive to a className.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/post',
				'title'        => 'Hostile status',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => '"><script>alert(1)</script>',
			)
		);

		// ACT: Fetch via the destination's API.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( $this->source_site_url );

		// ASSERT: sanitize_key stripped every unsafe character to a bare token.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'scriptalert1script', $result['items'][0]['status'] );
		$this->assertMatchesRegularExpression(
			'/^[a-z0-9_-]*$/',
			$result['items'][0]['status']
		);
	}

	/**
	 * Verifies that a built-in status value passes through unchanged.
	 */
	public function test_builtin_status_passes_through(): void {
		// ARRANGE: Source returns an honest built-in status.
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

		// ACT: Fetch via the destination's API.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( $this->source_site_url );

		// ASSERT: Status survives intact.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'draft', $result['items'][0]['status'] );
	}

	/**
	 * Verifies that a legitimate custom status slug survives sanitization, so
	 * custom-status posts keep their source status in the listing.
	 */
	public function test_custom_status_passes_through(): void {
		// ARRANGE: Source returns a hyphenated editorial-workflow status.
		$this->mock_body = $this->envelope_with(
			array(
				'id'           => 1,
				'link'         => 'https://source.example.com/post',
				'title'        => 'Custom status',
				'post_type'    => 'post',
				'date_gmt'     => '2024-07-15T15:00:00Z',
				'modified_gmt' => '2024-07-15T15:00:00Z',
				'status'       => 'in-progress',
			)
		);

		// ACT: Fetch via the destination's API.
		$result = ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_posts( $this->source_site_url );

		// ASSERT: The slug is already sanitize_key-safe, so it is preserved.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'in-progress', $result['items'][0]['status'] );
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
			->fetch_posts( $this->source_site_url );

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
			->fetch_posts( $this->source_site_url );

		// ASSERT.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame(
			'https://source.example.com/post-1',
			$result['items'][0]['link']
		);
	}
}
