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
 * Invariant: the Basic Auth password event must never carry a previous or
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
}
