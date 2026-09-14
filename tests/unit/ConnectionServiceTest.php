<?php
/**
 * Connection service tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

// Restore isolated credential fixtures after each test.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Admin\Connection_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Exercises the public connection read contract.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ConnectionServiceTest extends TestCase {

	/**
	 * Verifies that URL validation precedes credential validation.
	 */
	public function test_missing_url_precedes_credentials(): void {
		// ARRANGE: A probe that must never run for missing form input.
		$api = $this->createMock( Source_Posts_API::class );
		$api->expects( $this->never() )->method( 'test_connection' );
		$queue   = new Telemetry_Event_Queue();
		$service = new Connection_Service(
			$api,
			new Telemetry_Service( array(), $queue )
		);

		// ACT: Both absent and PHP-empty string URLs fail before probing.
		$inputs = array( array(), array( 'connected_site_url' => '0' ) );
		foreach ( $inputs as $input ) {
			$result = $service->test_connection( $input );

			// ASSERT: Preserve the original message and default HTTP status.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame(
				'connected_site_url_required',
				$result->get_error_code()
			);
			$this->assertSame(
				'Connected site URL is required.',
				$result->get_error_message()
			);
			$this->assertNull( $result->get_error_data() );
		}
		$this->assertSame( array(), $queue->events() );
	}

	/**
	 * Verifies that missing and short secrets retain their messages and status.
	 */
	public function test_invalid_credentials(): void {
		// ARRANGE: No probe is allowed before credential validation succeeds.
		$previous = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		$api      = $this->createMock( Source_Posts_API::class );
		$api->expects( $this->never() )->method( 'test_connection' );
		$queue   = new Telemetry_Event_Queue();
		$service = new Connection_Service(
			$api,
			new Telemetry_Service( array(), $queue )
		);
		$cases   = array(
			''      => 'Shared Secret is not configured. Add SAFE_PUBLISH_SHARED_SECRET to wp-config.php on both sites.',
			'short' => 'Shared Secret is too short. SAFE_PUBLISH_SHARED_SECRET must be at least 16 characters.',
		);

		try {
			foreach ( $cases as $secret => $message ) {
				putenv( 'SAFE_PUBLISH_SHARED_SECRET=' . $secret );

				// ACT: Submit a URL with an invalid saved secret.
				$result = $service->test_connection(
					array( 'connected_site_url' => 'https://example.com' )
				);

				// ASSERT: Both failures retain the transport status and text.
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame(
					'invalid_shared_secret',
					$result->get_error_code()
				);
				$this->assertSame( $message, $result->get_error_message() );
				$this->assertSame(
					array( 'status' => 401 ),
					$result->get_error_data()
				);
			}
			$this->assertSame( array(), $queue->events() );
		} finally {
			putenv(
				false === $previous ? 'SAFE_PUBLISH_SHARED_SECRET'
					: 'SAFE_PUBLISH_SHARED_SECRET=' . $previous
			);
		}
	}

	/**
	 * Verifies that Basic Auth overrides preserve presence and empty semantics.
	 *
	 * @dataProvider basic_auth_overrides
	 *
	 * @param array<string, string> $input    Live form fields.
	 * @param array<string, string> $expected Expected Basic Auth credentials.
	 */
	public function test_basic_auth_overrides_and_telemetry(
		array $input,
		array $expected
	): void {
		// ARRANGE: Valid saved credentials and distinct live form values.
		$previous = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		putenv( 'SAFE_PUBLISH_SHARED_SECRET=neutral-test-secret' );
		set_test_option( Options::OPTION_BASIC_AUTH_USERNAME, 'saved-user' );
		set_test_option(
			Options::OPTION_BASIC_AUTH_PASSWORD,
			'saved-password'
		);

		try {
			$credentials = array( 'shared_secret' => 'neutral-test-secret' )
				+ $expected;
			$probe       = array(
				'success' => false,
				'status'  => 'blocked',
				'message' => 'Probe result',
			);
			$api         = $this->createMock( Source_Posts_API::class );
			$api->expects( $this->once() )->method( 'test_connection' )
				->with( 'https://example.com', $credentials )
				->willReturn( $probe );
			$queue   = new Telemetry_Event_Queue();
			$service = new Connection_Service(
				$api,
				new Telemetry_Service( array(), $queue )
			);

			// ACT: Invoke the public service with unslashed form data.
			$result = $service->test_connection(
				array_merge(
					array( 'connected_site_url' => 'https://example.com' ),
					$input
				)
			);

			// ASSERT: Preserve the probe and its normalized telemetry outcome.
			$this->assertSame( $probe, $result );
			$this->assertSame(
				array(
					array(
						'event'      => 'connection_test_completed',
						'properties' => array( 'outcome' => 'blocked' ),
					),
				),
				$queue->events()
			);
		} finally {
			reset_test_options();
			putenv(
				false === $previous ? 'SAFE_PUBLISH_SHARED_SECRET'
					: 'SAFE_PUBLISH_SHARED_SECRET=' . $previous
			);
		}
	}

	/**
	 * Supplies live inputs and explicit expected Basic Auth credentials.
	 *
	 * @return array<string, array{array<string, string>, array<string, string>}>
	 */
	public static function basic_auth_overrides(): array {
		$saved = array(
			'username' => 'saved-user',
			'password' => 'saved-password',
		);
		$live  = array(
			'username' => 'live-user',
			'password' => 'live-password',
		);

		return array(
			'absent fields retain saved credentials'   => array( array(), $saved ),
			'username alone retains saved credentials' => array(
				array( 'username' => 'live-user' ),
				$saved,
			),
			'password alone retains saved credentials' => array(
				array( 'password' => 'live-password' ),
				$saved,
			),
			'both empty clear saved credentials'       => array(
				array(
					'username' => '',
					'password' => '',
				),
				array(),
			),
			'zero username clears saved credentials'   => array(
				array(
					'username' => '0',
					'password' => 'live',
				),
				array(),
			),
			'zero password clears saved credentials'   => array(
				array(
					'username' => 'live',
					'password' => '0',
				),
				array(),
			),
			'empty username clears saved credentials'  => array(
				array(
					'username' => '',
					'password' => 'live',
				),
				array(),
			),
			'empty password clears saved credentials'  => array(
				array(
					'username' => 'live',
					'password' => '',
				),
				array(),
			),
			'both supplied replace saved credentials'  => array( $live, $live ),
		);
	}

	/**
	 * Verifies that cached status gets a fresh message without cache mutation.
	 */
	public function test_auth_status_reads_cached_probe(): void {
		// ARRANGE: Cached failure data differs from an unconfigured live probe.
		$cached = array(
			'status'     => 'blocked',
			'code'       => 403,
			'error_code' => 'upstream_gate',
			'message'    => 'Previously translated message',
		);
		set_site_transient( Connection_Service::AUTH_STATUS_TRANSIENT, $cached );
		$service = new Connection_Service(
			new Source_Posts_API(),
			new Telemetry_Service()
		);

		try {
			// ACT: Invoke the service directly, without an AJAX adapter.
			$result = $service->auth_status( array() );

			// ASSERT: Cached details survive with only the response translated.
			$expected            = $cached;
			$expected['message'] = Source_Posts_API::describe_auth_status(
				'blocked',
				'upstream_gate'
			);
			$this->assertNotSame( $cached['message'], $expected['message'] );
			$this->assertSame( $expected, $result );
			$this->assertSame(
				$cached,
				get_site_transient( Connection_Service::AUTH_STATUS_TRANSIENT )
			);
		} finally {
			unset( $GLOBALS['_test_site_transients'] );
		}
	}
}
