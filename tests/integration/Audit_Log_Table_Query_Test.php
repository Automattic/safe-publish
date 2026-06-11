<?php
/**
 * Integration tests for the Audit_Log_Table query extensions used by the
 * Audit Log UI: array channel/level filters and date-range filters.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Audit_Log_Table;

/**
 * Audit Log Table Query Test Class.
 */
class Audit_Log_Table_Query_Test extends Integration_Test_Case {

	/**
	 * Channels that may carry rows in these tests; cleared before each run
	 * so seeded fixtures aren't mixed with leftovers from other tests.
	 *
	 * @var string[]
	 */
	private const CHANNELS = array(
		'auth',
		'content',
		'dispatch',
		'export',
		'import',
		'media',
		'settings',
	);

	/**
	 * Seeds the audit log table with a known set of rows spread across
	 * channels, levels, and timestamps.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		foreach ( self::CHANNELS as $channel ) {
			Audit_Log_Table::clear( $channel );
		}

		Audit_Log_Table::insert( 'auth', 'info', 'REQUEST_AUTHENTICATED', '2026-01-10 10:00:00', array() );
		Audit_Log_Table::insert( 'auth', 'error', 'SIGNATURE_INVALID', '2026-01-11 11:00:00', array() );
		Audit_Log_Table::insert( 'export', 'info', 'CONTENT_EXPORTED', '2026-01-12 12:00:00', array() );
		Audit_Log_Table::insert( 'import', 'error', 'SESSION_ROLLBACK_FAILED', '2026-01-13 13:00:00', array() );
		Audit_Log_Table::insert( 'settings', 'info', 'SYNC_MODE_CHANGED', '2026-01-14 14:00:00', array() );
	}

	/**
	 * Verifies that passing an array of channels is treated as an OR
	 * (IN-clause) match rather than failing or short-circuiting.
	 */
	public function test_get_events_accepts_channel_array(): void {
		// ACT.
		$rows = Audit_Log_Table::get_events(
			array( 'channel' => array( 'auth', 'export' ) )
		);

		// ASSERT.
		$this->assertCount( 3, $rows );
		$channels = array_unique( array_column( $rows, 'channel' ) );
		sort( $channels );
		$this->assertSame( array( 'auth', 'export' ), $channels );
	}

	/**
	 * Verifies that string channel still works (backward compat with
	 * existing callers that pass a single string).
	 */
	public function test_get_events_accepts_channel_string(): void {
		// ACT.
		$rows = Audit_Log_Table::get_events( array( 'channel' => 'export' ) );

		// ASSERT.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'export', $rows[0]['channel'] );
	}

	/**
	 * Verifies that passing an array of levels filters via IN-clause.
	 */
	public function test_get_events_accepts_level_array(): void {
		// ACT.
		$rows = Audit_Log_Table::get_events(
			array( 'level' => array( 'error' ) )
		);

		// ASSERT.
		$this->assertCount( 2, $rows );
		$levels = array_unique( array_column( $rows, 'level' ) );
		$this->assertSame( array( 'error' ), $levels );
	}

	/**
	 * Verifies that after_gmt restricts rows to those at or after the
	 * given GMT datetime.
	 */
	public function test_get_events_after_gmt_filters_inclusively(): void {
		// ACT.
		$rows = Audit_Log_Table::get_events(
			array( 'after_gmt' => '2026-01-13 00:00:00' )
		);

		// ASSERT: the 2026-01-13 and 2026-01-14 rows remain.
		$this->assertCount( 2, $rows );
		$events = array_column( $rows, 'event' );
		sort( $events );
		$this->assertSame(
			array( 'SESSION_ROLLBACK_FAILED', 'SYNC_MODE_CHANGED' ),
			$events
		);
	}

	/**
	 * Verifies that before_gmt is inclusive — rows at exactly the bound
	 * are captured so callers can pass end-of-day (23:59:59) to mean
	 * "through this calendar day".
	 */
	public function test_get_events_before_gmt_filters_inclusively(): void {
		// ARRANGE: insert an event at exactly the bound moment.
		Audit_Log_Table::insert( 'auth', 'info', 'EDGE', '2026-01-12 23:59:59', array() );

		// ACT: pass end-of-day 2026-01-12 to capture through that day.
		$rows = Audit_Log_Table::get_events(
			array( 'before_gmt' => '2026-01-12 23:59:59' )
		);

		// ASSERT: rows on/before 2026-01-12 are included, including the
		// 12:00:00 event AND the bound-edge EDGE event; 2026-01-13 is not.
		$events = array_column( $rows, 'event' );
		$this->assertContains( 'EDGE', $events );
		$this->assertContains( 'REQUEST_AUTHENTICATED', $events );
		$this->assertNotContains( 'SESSION_ROLLBACK_FAILED', $events );
	}

	/**
	 * Verifies that count() honors the same filter shapes used by
	 * get_events() so server-side pagination totals stay accurate.
	 */
	public function test_count_honors_array_and_date_filters(): void {
		// ACT.
		$total = Audit_Log_Table::count(
			array(
				'channel'   => array( 'auth', 'import' ),
				'level'     => array( 'error' ),
				'after_gmt' => '2026-01-11 00:00:00',
			)
		);

		// ASSERT: auth/error on 2026-01-11 and import/error on 2026-01-13.
		$this->assertSame( 2, $total );
	}

	/**
	 * Verifies that events tied at the same created_at_gmt receive a
	 * deterministic order (id DESC tiebreak) so paginated UIs don't see
	 * the same row appear on adjacent pages.
	 */
	public function test_get_events_orders_ties_deterministically(): void {
		// ARRANGE: insert two rows sharing a timestamp.
		Audit_Log_Table::clear( 'auth' );
		Audit_Log_Table::insert( 'auth', 'info', 'FIRST', '2026-02-01 09:00:00', array() );
		Audit_Log_Table::insert( 'auth', 'info', 'SECOND', '2026-02-01 09:00:00', array() );

		// ACT.
		$rows = Audit_Log_Table::get_events( array( 'channel' => 'auth' ) );

		// ASSERT: the higher id (latest insert) sorts first.
		$this->assertCount( 2, $rows );
		$this->assertSame( 'SECOND', $rows[0]['event'] );
		$this->assertSame( 'FIRST', $rows[1]['event'] );
	}
}
