<?php
/**
 * Integration tests for the settings audit logger.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;

/**
 * Settings Logger Test Class.
 *
 * Verifies that security-relevant settings changes are recorded on the
 * settings channel with the agreed payload shapes — including the explicit
 * guarantee that the Basic Auth password event never carries a previous or
 * new value, not even indirectly (e.g. as a length or hash).
 */
class Settings_Logger_Test extends Integration_Test_Case {

	/**
	 * Channel under test.
	 */
	private const CHANNEL = 'settings';

	/**
	 * Options whose changes are audited. Cleared in setUp so each test starts
	 * from the "first set" path.
	 */
	private const AUDITED_OPTIONS = array(
		Options::OPTION_CONNECTED_SITE_URL,
		Options::OPTION_BASIC_AUTH_USERNAME,
		Options::OPTION_BASIC_AUTH_PASSWORD,
		Options::OPTION_SYNC_MODE,
	);

	/**
	 * Resets the audit table and the audited options before each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( self::CHANNEL );

		foreach ( self::AUDITED_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Verifies that the first-set of the connected site URL records an event
	 * with an empty previous_value.
	 */
	public function test_connected_site_url_first_set_emits_event_with_empty_previous_value(): void {
		// ARRANGE: Option starts unset (cleared in setUp).

		// ACT: First-time write triggers add_option_<name>.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// ASSERT: One event with previous_value '' and the new value.
		$data = $this->get_single_event_data(
			Log_Events::CONNECTED_SITE_URL_CHANGED
		);
		$this->assertSame( '', $data['previous_value'] );
		$this->assertSame( 'https://source.example.com', $data['new_value'] );
	}

	/**
	 * Verifies that an update to the connected site URL records both the
	 * previous and new values.
	 */
	public function test_connected_site_url_update_emits_event_with_both_values(): void {
		// ARRANGE: Seed an existing value and discard its add_option event.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://old.example.com' );
		Audit_Log_Table::clear( self::CHANNEL );

		// ACT: Update fires update_option_<name>.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://new.example.com' );

		// ASSERT: One event captures both old and new values.
		$data = $this->get_single_event_data(
			Log_Events::CONNECTED_SITE_URL_CHANGED
		);
		$this->assertSame( 'https://old.example.com', $data['previous_value'] );
		$this->assertSame( 'https://new.example.com', $data['new_value'] );
	}

	/**
	 * Verifies that the first-set of the Basic Auth username records an event
	 * with an empty previous_value.
	 */
	public function test_basic_auth_username_first_set_emits_event_with_empty_previous_value(): void {
		// ARRANGE: Option starts unset (cleared in setUp).

		// ACT: First-time write triggers add_option_<name>.
		update_option( Options::OPTION_BASIC_AUTH_USERNAME, 'editor' );

		// ASSERT: One event with previous_value '' and the new value.
		$data = $this->get_single_event_data(
			Log_Events::BASIC_AUTH_USERNAME_CHANGED
		);
		$this->assertSame( '', $data['previous_value'] );
		$this->assertSame( 'editor', $data['new_value'] );
	}

	/**
	 * Verifies that an update to the Basic Auth username records both values.
	 */
	public function test_basic_auth_username_update_emits_event_with_both_values(): void {
		// ARRANGE: Seed an existing value and discard its add_option event.
		update_option( Options::OPTION_BASIC_AUTH_USERNAME, 'editor' );
		Audit_Log_Table::clear( self::CHANNEL );

		// ACT: Update fires update_option_<name>.
		update_option( Options::OPTION_BASIC_AUTH_USERNAME, 'admin' );

		// ASSERT: One event captures both old and new values.
		$data = $this->get_single_event_data(
			Log_Events::BASIC_AUTH_USERNAME_CHANGED
		);
		$this->assertSame( 'editor', $data['previous_value'] );
		$this->assertSame( 'admin', $data['new_value'] );
	}

	/**
	 * Verifies that the first-set of the Basic Auth password records an event
	 * tagged 'set' with no value fields. This is the core sensitivity check —
	 * the payload must not carry the password under any key.
	 */
	public function test_basic_auth_password_first_set_emits_event_with_change_type_set_and_no_value(): void {
		// ARRANGE: Option starts unset (cleared in setUp).
		$password = 's3cr3t-distinctive-value-1';

		// ACT: First-time write triggers add_option_<name>.
		update_option( Options::OPTION_BASIC_AUTH_PASSWORD, $password );

		// ASSERT: Event tagged 'set' and no field carries the value.
		$data = $this->get_single_event_data(
			Log_Events::BASIC_AUTH_PASSWORD_CHANGED
		);
		$this->assertSame( 'set', $data['change_type'] );
		$this->assert_payload_does_not_leak_password( $data, $password );
	}

	/**
	 * Verifies that a rotation of the Basic Auth password records an event
	 * tagged 'rotated' with no value fields.
	 */
	public function test_basic_auth_password_update_emits_event_with_change_type_rotated_and_no_value(): void {
		// ARRANGE: Seed an existing value and discard its add_option event.
		$previous = 's3cr3t-previous-distinctive-1';
		$rotated  = 's3cr3t-rotated-distinctive-2';
		update_option( Options::OPTION_BASIC_AUTH_PASSWORD, $previous );
		Audit_Log_Table::clear( self::CHANNEL );

		// ACT: Update fires update_option_<name>.
		update_option( Options::OPTION_BASIC_AUTH_PASSWORD, $rotated );

		// ASSERT: Event tagged 'rotated' and no field carries either value.
		$data = $this->get_single_event_data(
			Log_Events::BASIC_AUTH_PASSWORD_CHANGED
		);
		$this->assertSame( 'rotated', $data['change_type'] );
		$this->assert_payload_does_not_leak_password( $data, $previous );
		$this->assert_payload_does_not_leak_password( $data, $rotated );
	}

	/**
	 * Verifies that the first-set of the sync mode records an event with an
	 * empty previous_value.
	 */
	public function test_sync_mode_first_set_emits_event_with_empty_previous_value(): void {
		// ARRANGE: Option starts unset (cleared in setUp).

		// ACT: First-time write triggers add_option_<name>.
		update_option( Options::OPTION_SYNC_MODE, Options::SYNC_MODE_IMPORT );

		// ASSERT: One event with previous_value '' and the new value.
		$data = $this->get_single_event_data(
			Log_Events::SYNC_MODE_CHANGED
		);
		$this->assertSame( '', $data['previous_value'] );
		$this->assertSame( Options::SYNC_MODE_IMPORT, $data['new_value'] );
	}

	/**
	 * Verifies that an update to the sync mode records both values.
	 */
	public function test_sync_mode_update_emits_event_with_both_values(): void {
		// ARRANGE: Seed an existing value and discard its add_option event.
		update_option( Options::OPTION_SYNC_MODE, Options::SYNC_MODE_IMPORT );
		Audit_Log_Table::clear( self::CHANNEL );

		// ACT: Update fires update_option_<name>.
		update_option( Options::OPTION_SYNC_MODE, Options::SYNC_MODE_BIDIRECTIONAL );

		// ASSERT: One event captures both old and new values.
		$data = $this->get_single_event_data(
			Log_Events::SYNC_MODE_CHANGED
		);
		$this->assertSame( Options::SYNC_MODE_IMPORT, $data['previous_value'] );
		$this->assertSame(
			Options::SYNC_MODE_BIDIRECTIONAL,
			$data['new_value']
		);
	}

	/**
	 * Verifies that writing an option outside the audited set does not emit
	 * any settings-channel event. Guards against accidentally blanket-auditing
	 * every plugin option in the future.
	 */
	public function test_unrelated_option_change_does_not_emit_settings_event(): void {
		// ARRANGE: Empty settings channel.

		// ACT: Set an unrelated option.
		update_option( 'safe_publish_unrelated_option', 'value' );

		// ASSERT: No settings-channel events.
		$events = Audit_Log_Table::get_events(
			array( 'channel' => self::CHANNEL )
		);
		$this->assertCount( 0, $events );
	}

	/**
	 * Verifies that a no-op update (writing the same value) emits nothing.
	 * Documents the assumption that update_option does not fire its action
	 * hooks when the value is unchanged.
	 */
	public function test_no_op_write_emits_nothing(): void {
		// ARRANGE: Seed a value and clear the resulting add_option event.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		Audit_Log_Table::clear( self::CHANNEL );

		// ACT: Write the same value again.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// ASSERT: No new events.
		$events = Audit_Log_Table::get_events(
			array( 'channel' => self::CHANNEL )
		);
		$this->assertCount( 0, $events );
	}

	/**
	 * Returns the data payload for the single event matching the given type.
	 *
	 * @param string $event_type Expected event constant from Log_Events.
	 * @return array Decoded data payload.
	 */
	private function get_single_event_data( string $event_type ): array {
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => self::CHANNEL,
				'event_type' => $event_type,
			)
		);

		$this->assertCount( 1, $events, "Expected one {$event_type} event." );
		return $events[0]['data'];
	}

	/**
	 * Asserts that the audit payload contains no field that exposes the given
	 * password — directly, partially, or via a derived form a caller might be
	 * tempted to log (length, hash, prefix). This is broader than the explicit
	 * previous_value/new_value check; it guards future contributors against
	 * adding new "harmless" fields that would still leak.
	 *
	 * @param array  $data     Decoded event payload.
	 * @param string $password Plaintext password seeded by the test.
	 */
	private function assert_payload_does_not_leak_password(
		array $data,
		string $password
	): void {
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$this->assertStringNotContainsString(
					$password,
					$value,
					"Audit payload field '{$key}' must not contain the password."
				);
			}
		}

		$forbidden_keys = array(
			'previous_value',
			'new_value',
			'value',
			'password',
			'password_hash',
			'password_length',
		);

		foreach ( $forbidden_keys as $forbidden ) {
			$this->assertArrayNotHasKey(
				$forbidden,
				$data,
				"Audit payload must not include '{$forbidden}' for password events."
			);
		}
	}
}
