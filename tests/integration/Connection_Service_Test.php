<?php
/**
 * Connection service integration tests.
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
use WP_Ajax_UnitTestCase;

/**
 * Exercises public connection reads with real WordPress sanitization and cache.
 */
class Connection_Service_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Verifies that direct callers receive sanitized inputs without unslashing.
	 */
	public function test_sanitizes_direct_input_once(): void {
		// ARRANGE: A valid secret and unslashed form fields containing markup.
		$previous = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		putenv( 'SAFE_PUBLISH_SHARED_SECRET=neutral-test-secret' );
		$api = $this->createMock( Source_Posts_API::class );
		$api->expects( $this->once() )->method( 'test_connection' )
			->with(
				'https://example.com',
				$this->callback(
					static fn( array $credentials ): bool =>
						'user' === $credentials['username']
						&& 'pass\\word' === $credentials['password']
				)
			)->willReturn( array( 'status' => 'authorized' ) );
		$service = new Connection_Service( $api, new Telemetry_Service() );

		try {
			// ACT: Read through the same public service used by AJAX.
			$result = $service->test_connection(
				array(
					'connected_site_url' => ' <b>https://example.com</b> ',
					'username'           => ' <b>user</b> ',
					'password'           => 'pass\\word',
				)
			);

			// ASSERT: The sanitized probe is returned unchanged.
			$this->assertSame( array( 'status' => 'authorized' ), $result );
		} finally {
			putenv(
				false === $previous ? 'SAFE_PUBLISH_SHARED_SECRET'
					: 'SAFE_PUBLISH_SHARED_SECRET=' . $previous
			);
		}
	}

	/**
	 * Verifies that AJAX unslashes once and preserves a failed probe's envelope.
	 */
	public function test_ajax_preserves_credentials_and_probe_response(): void {
		// ARRANGE: WordPress-slashed credentials and an upstream rejection.
		$previous = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		putenv( 'SAFE_PUBLISH_SHARED_SECRET=neutral-test-secret' );
		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'administrator' ) )
		);
		$_POST   = wp_slash(
			array(
				'action'             => 'safe_publish_test_connection',
				'nonce'              => wp_create_nonce( 'safe_publish_ajax_nonce' ),
				'connected_site_url' => 'https://example.com',
				'username'           => 'user\\name',
				'password'           => 'pass\\word',
			)
		);
		$headers = array();
		$filter  = static function ( mixed $_preempt, array $args ) use ( &$headers ): array {
			$headers[] = $args['headers']['Authorization'] ?? '';
			return array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"code":"upstream_gate"}',
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 2 );

		try {
			// ACT: Dispatch the registered controller through WordPress AJAX.
			$this->dispatch_ajax_expecting_die( 'safe_publish_test_connection' );

			// ASSERT: Backslashes survive once and probe failure is success data.
			$this->assertSame(
				array( 'Basic ' . base64_encode( 'user\\name:pass\\word' ) ),
				$headers
			);
			$response = json_decode( $this->_last_response, true );
			$this->assertTrue( $response['success'] );
			$this->assertFalse( $response['data']['success'] );
			$this->assertSame( 'blocked', $response['data']['status'] );
			$this->assertSame(
				Source_Posts_API::describe_auth_status( 'blocked', 'upstream_gate' ),
				$response['data']['message']
			);
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
			putenv(
				false === $previous ? 'SAFE_PUBLISH_SHARED_SECRET'
					: 'SAFE_PUBLISH_SHARED_SECRET=' . $previous
			);
		}
	}

	/**
	 * Verifies that cached probe data stays untranslated until each read.
	 */
	public function test_cache_lifetime_and_localized_reads(): void {
		// ARRANGE: A cold cache and an unconfigured URL avoid network requests.
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		Connection_Service::bust_auth_status_cache();
		$service = new Connection_Service(
			new Source_Posts_API(),
			new Telemetry_Service()
		);
		$before  = time();

		// ACT: Populate the cache, then read under a different translation.
		$first  = $service->auth_status( array() );
		$cached = get_site_transient(
			Connection_Service::AUTH_STATUS_TRANSIENT
		);
		$filter = static function (
			string $translation,
			string $_text,
			string $domain
		): string {
			return 'safe-publish' === $domain
				? 'Localized probe message' : $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		try {
			$second = $service->auth_status( array() );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}

		// ASSERT: The stable key and TTL cache only probe data, not messages.
		$this->assertSame(
			'safe_publish_auth_status',
			Connection_Service::AUTH_STATUS_TRANSIENT
		);
		$this->assertSame( 300, Connection_Service::AUTH_STATUS_TTL );
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( array( 'status' => 'url_unset' ), $cached );
		$this->assertSame( 'url_unset', $first['status'] );
		$this->assertSame( 'Localized probe message', $second['message'] );
		$this->assertNotSame( $first['message'], $second['message'] );
		$this->assertSame(
			$cached,
			get_site_transient( Connection_Service::AUTH_STATUS_TRANSIENT )
		);
		$expires = (int) get_site_option(
			'_site_transient_timeout_safe_publish_auth_status'
		);
		$this->assertGreaterThanOrEqual( $before + 300, $expires );
		$this->assertLessThanOrEqual( time() + 300, $expires );
		Connection_Service::bust_auth_status_cache();
	}
}
