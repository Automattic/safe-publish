<?php
/**
 * Integration tests for actor attribution in audit log events.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Audit_Log_Table;

/**
 * Audit Log Actor Attribution Test Class.
 *
 * Verifies that every audit log channel records the acting user (id and
 * display name snapshot) so forensic queries can answer "who did this?".
 */
class Audit_Log_Actor_Attribution_Test extends Integration_Test_Case {

	private const CHANNELS = array(
		'auth',
		'content',
		'export',
		'import',
		'media',
		'settings',
	);

	private const VALID_SOURCES = array(
		'cli',
		'cron',
		'hmac',
		'xmlrpc',
		'ajax',
		'rest',
		'admin',
		'front',
		'unknown',
	);

	/**
	 * Set up the audit log table and clear every channel under test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		foreach ( self::CHANNELS as $channel ) {
			Audit_Log_Table::clear( $channel );
		}
	}

	/**
	 * Verifies that the auth channel records the acting user.
	 */
	public function test_auth_channel_records_actor(): void {
		$this->assert_channel_records_actor( new Test_Logger( 'auth' ), 'auth' );
	}

	/**
	 * Verifies that the content channel records the acting user.
	 */
	public function test_content_channel_records_actor(): void {
		$this->assert_channel_records_actor( new Test_Logger( 'content' ), 'content' );
	}

	/**
	 * Verifies that the settings channel records the acting user.
	 */
	public function test_settings_channel_records_actor(): void {
		$this->assert_channel_records_actor( new Test_Logger( 'settings' ), 'settings' );
	}

	/**
	 * Verifies that the export channel records the acting user.
	 */
	public function test_export_channel_records_actor(): void {
		$this->assert_channel_records_actor( new Test_Logger( 'export' ), 'export' );
	}

	/**
	 * Verifies that the import channel records the acting user.
	 */
	public function test_import_channel_records_actor(): void {
		$this->assert_channel_records_actor( new Test_Logger( 'import' ), 'import' );
	}

	/**
	 * Verifies that the media channel records the acting user.
	 */
	public function test_media_channel_records_actor(): void {
		$this->assert_channel_records_actor( new Test_Logger( 'media' ), 'media' );
	}

	/**
	 * Verifies that an unauthenticated context records actor_user_id of 0
	 * and an empty display name.
	 */
	public function test_unauthenticated_actor_recorded_as_zero(): void {
		// ARRANGE: Drop the current user so the logger sees no session.
		wp_set_current_user( 0 );

		// ACT: Log an event; auth channel is representative.
		( new Test_Logger( 'auth' ) )->fire_event( 'TEST_UNAUTH' );

		// ASSERT: Actor fields reflect "no user".
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'auth',
				'event_type' => 'TEST_UNAUTH',
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( 0, $events[0]['data']['actor_user_id'] );
		$this->assertSame( '', $events[0]['data']['actor_display_name'] );
		$this->assertContains(
			$events[0]['data']['actor_source'],
			self::VALID_SOURCES
		);
	}

	/**
	 * Verifies that the display name is snapshotted at log time and survives
	 * the user being deleted afterwards.
	 */
	public function test_actor_display_name_is_snapshotted(): void {
		// ARRANGE: Create a user with a known display name and act as them.
		$user_id = self::factory()->user->create(
			array( 'display_name' => 'Snapshot User' )
		);
		wp_set_current_user( $user_id );

		// ACT: Log an event, then delete the user.
		( new Test_Logger( 'auth' ) )->fire_event( 'TEST_SNAPSHOT' );
		wp_delete_user( $user_id );

		// ASSERT: The audit row still carries the captured display name.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'auth',
				'event_type' => 'TEST_SNAPSHOT',
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( $user_id, $events[0]['data']['actor_user_id'] );
		$this->assertSame(
			'Snapshot User',
			$events[0]['data']['actor_display_name']
		);
		$this->assertContains(
			$events[0]['data']['actor_source'],
			self::VALID_SOURCES
		);
	}

	/**
	 * Verifies that an HMAC-signed request is tagged with actor_source = hmac.
	 *
	 * Detection is based on the presence of the signature header so failed
	 * authentication still tags the audit row as an HMAC attempt.
	 */
	public function test_actor_source_detects_hmac_signature_header(): void {
		// ARRANGE: Mark the request as carrying an HMAC signature header.
		$_SERVER['HTTP_X_SAFE_PUBLISH_SIGNATURE'] = 'test-signature';

		try {
			// ACT: Log an event in any channel; auth is representative.
			( new Test_Logger( 'auth' ) )->fire_event( 'TEST_HMAC_SOURCE' );

			// ASSERT: The audit row is tagged hmac.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'auth',
					'event_type' => 'TEST_HMAC_SOURCE',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'hmac', $events[0]['data']['actor_source'] );
		} finally {
			unset( $_SERVER['HTTP_X_SAFE_PUBLISH_SIGNATURE'] );
		}
	}

	/**
	 * Verifies that caller-supplied $data cannot clobber auto-captured
	 * forensic fields (actor_*, site_url, user_agent, request_uri,
	 * timestamp). Channel-specific keys still pass through unchanged.
	 */
	public function test_reserved_keys_cannot_be_overridden(): void {
		// ARRANGE: Act as a known user.
		$user_id = self::factory()->user->create(
			array( 'display_name' => 'Real User' )
		);
		wp_set_current_user( $user_id );

		// ACT: Log with $data that tries to spoof every reserved key.
		( new Test_Logger( 'auth' ) )->fire_event(
			'TEST_RESERVED_KEYS',
			array(
				'actor_user_id'      => 99999,
				'actor_display_name' => 'Imposter',
				'actor_source'       => 'spoofed',
				'site_url'           => 'https://evil.example/',
				'user_agent'         => 'Mallory',
				'request_uri'        => '/spoofed',
				'timestamp'          => '1970-01-01 00:00:00',
				'route'              => '/safe-test/',
			)
		);

		// ASSERT: Forensic fields reflect captured values, not caller's.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'auth',
				'event_type' => 'TEST_RESERVED_KEYS',
			)
		);
		$this->assertCount( 1, $events );
		$data = $events[0]['data'];
		$this->assertSame( $user_id, $data['actor_user_id'] );
		$this->assertSame( 'Real User', $data['actor_display_name'] );
		$this->assertNotSame( 'spoofed', $data['actor_source'] );
		$this->assertContains( $data['actor_source'], self::VALID_SOURCES );
		$this->assertNotSame(
			'https://evil.example/',
			$data['site_url']
		);
		$this->assertNotSame( 'Mallory', $data['user_agent'] );
		$this->assertNotSame( '/spoofed', $data['request_uri'] );
		$this->assertNotSame(
			'1970-01-01 00:00:00',
			$events[0]['created_at_gmt']
		);

		// ASSERT: Channel-specific keys still pass through.
		$this->assertSame( '/safe-test/', $data['route'] );
	}

	/**
	 * Asserts that a logger records the current user's id and display name.
	 *
	 * @param Test_Logger $logger  Logger instance to test.
	 * @param string      $channel Audit log channel.
	 */
	private function assert_channel_records_actor(
		Test_Logger $logger,
		string $channel
	): void {
		// ARRANGE: Create a user with a known display name and act as them.
		$user_id = self::factory()->user->create(
			array( 'display_name' => 'Test Actor' )
		);
		wp_set_current_user( $user_id );

		// ACT: Log an event on the given channel.
		$logger->fire_event( 'TEST_EVENT' );

		// ASSERT: Actor fields are populated from the current user.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => $channel,
				'event_type' => 'TEST_EVENT',
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( $user_id, $events[0]['data']['actor_user_id'] );
		$this->assertSame(
			'Test Actor',
			$events[0]['data']['actor_display_name']
		);
		$this->assertContains(
			$events[0]['data']['actor_source'],
			self::VALID_SOURCES
		);
	}
}
