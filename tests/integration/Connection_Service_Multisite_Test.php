<?php
/**
 * Connection service multisite integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

// Restore isolated credential fixtures after each test.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Connection_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_UnitTestCase;

/**
 * Verifies that each network site reads its own cached auth status.
 */
class Connection_Service_Multisite_Test extends WP_UnitTestCase {

	/**
	 * Shared secret restored after each test.
	 *
	 * @var string|false
	 */
	private string|false $previous_secret = false;

	/**
	 * Skips the multisite-only cases in the normal single-site suite.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress multisite.' );
		}

		$this->previous_secret = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		putenv( 'SAFE_PUBLISH_SHARED_SECRET=neutral-test-secret-value' );
		Connection_Service::bust_auth_status_cache();
	}

	/**
	 * Restores the ambient shared secret.
	 */
	#[\Override]
	protected function tearDown(): void {
		Connection_Service::bust_auth_status_cache();
		putenv(
			false === $this->previous_secret ? 'SAFE_PUBLISH_SHARED_SECRET'
				: 'SAFE_PUBLISH_SHARED_SECRET=' . $this->previous_secret
		);

		parent::tearDown();
	}

	/**
	 * Verifies that one site's probe result is not served to another site whose
	 * own connection is unconfigured.
	 */
	public function test_probe_result_does_not_cross_network_sites(): void {
		// ARRANGE: This site holds a working connection, a second site none.
		$other_site = self::factory()->blog->create();
		$probes     = array();
		$filter     = static function (
			mixed $_preempt,
			array $_args,
			string $url
		) use ( &$probes ): array {
			$probes[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '[]',
				'headers'  => array(),
			);
		};

		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source-a.example.com'
		);
		switch_to_blog( $other_site );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		restore_current_blog();

		// ACT: Warm this site's cache, then read on the other site.
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$first = $this->service()->auth_status( array() );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		switch_to_blog( $other_site );
		$second = $this->service()->auth_status( array() );
		restore_current_blog();

		// ASSERT: The probing site is authorized while the unconfigured site
		// reports its own truth, having issued no request of its own.
		$this->assertCount( 1, $probes );
		$this->assertStringContainsString( 'source-a.example.com', $probes[0] );
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( 'authorized', $first['status'] );
		$this->assertSame( 'url_unset', $second['status'] );
	}

	/**
	 * Verifies that busting one site's cache leaves another site's entry intact,
	 * so saving a connection never re-probes the rest of the network.
	 */
	public function test_cache_invalidation_is_confined_to_one_site(): void {
		// ARRANGE: Both sites hold a cached probe result.
		$other_site = self::factory()->blog->create();
		$cached     = array(
			'status' => 'authorized',
			'code'   => 200,
		);

		set_transient(
			Connection_Service::AUTH_STATUS_TRANSIENT,
			$cached,
			Connection_Service::AUTH_STATUS_TTL
		);
		switch_to_blog( $other_site );
		set_transient(
			Connection_Service::AUTH_STATUS_TRANSIENT,
			$cached,
			Connection_Service::AUTH_STATUS_TTL
		);
		restore_current_blog();

		// ACT: Invalidate only this site's cache.
		Connection_Service::bust_auth_status_cache();

		// ASSERT: The other site keeps its entry.
		$this->assertFalse(
			get_transient( Connection_Service::AUTH_STATUS_TRANSIENT )
		);
		switch_to_blog( $other_site );
		$survived = get_transient( Connection_Service::AUTH_STATUS_TRANSIENT );
		delete_transient( Connection_Service::AUTH_STATUS_TRANSIENT );
		restore_current_blog();
		$this->assertSame( $cached, $survived );
	}

	/**
	 * Constructs the service with the real source API.
	 *
	 * @return Connection_Service Service under test.
	 */
	private function service(): Connection_Service {
		return new Connection_Service(
			new Source_Posts_API(),
			new Telemetry_Service()
		);
	}
}
