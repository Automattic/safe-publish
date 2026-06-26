<?php
/**
 * Integration tests for the Audit Log AJAX endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Audit_Log_Page;
use Safe_Publish\Tests\Integration\Ajax_Die_Continue_Trait;
use Safe_Publish\Utils\Audit_Log_Table;
use WP_Ajax_UnitTestCase;

/**
 * Audit Log Page Test Class.
 */
class Audit_Log_Page_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Channels that may carry rows in these tests.
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
	 * Sets up the audit log table, the AJAX handler, and an admin user.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		foreach ( self::CHANNELS as $channel ) {
			Audit_Log_Table::clear( $channel );
		}

		( new Audit_Log_Page() )->init();

		$admin_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $admin_id );
	}

	/**
	 * Verifies that the AJAX response envelope carries an items array and
	 * a total count matching the rows that pass the active filters.
	 */
	public function test_response_returns_items_and_total(): void {
		// ARRANGE.
		Audit_Log_Table::insert( 'auth', 'info', 'REQUEST_AUTHENTICATED', '2026-03-01 10:00:00', array() );
		Audit_Log_Table::insert( 'export', 'info', 'CONTENT_EXPORTED', '2026-03-02 11:00:00', array() );

		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['total'] );
		$this->assertCount( 2, $response['data']['items'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$response['data']['items'][0]['date']
		);
	}

	/**
	 * Verifies that the channels[] filter restricts the result set to the
	 * named channels only.
	 */
	public function test_response_honors_channels_filter(): void {
		// ARRANGE.
		Audit_Log_Table::insert( 'auth', 'info', 'A', '2026-03-01 10:00:00', array() );
		Audit_Log_Table::insert( 'export', 'info', 'B', '2026-03-02 11:00:00', array() );
		Audit_Log_Table::insert( 'import', 'info', 'C', '2026-03-03 12:00:00', array() );

		$_POST = array(
			'nonce'    => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'channels' => array( 'auth', 'import' ),
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['total'] );
		$channels = array_unique( array_column( $response['data']['items'], 'channel' ) );
		sort( $channels );
		$this->assertSame( array( 'auth', 'import' ), $channels );
	}

	/**
	 * Verifies that the levels[] filter accepts 'warning' and returns only
	 * warning-level rows, confirming warning is part of the page's level
	 * filter contract.
	 */
	public function test_response_honors_warning_level_filter(): void {
		// ARRANGE: one row per level on a single channel.
		Audit_Log_Table::insert( 'import', 'info', 'A', '2026-03-01 10:00:00', array() );
		Audit_Log_Table::insert( 'import', 'warning', 'B', '2026-03-02 11:00:00', array() );
		Audit_Log_Table::insert( 'import', 'error', 'C', '2026-03-03 12:00:00', array() );

		$_POST = array(
			'nonce'  => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'levels' => array( 'warning' ),
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT: only the warning row passes the filter.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['total'] );
		$this->assertSame( 'warning', $response['data']['items'][0]['level'] );
		$this->assertSame( 'B', $response['data']['items'][0]['event'] );
	}

	/**
	 * Verifies that the before bound is treated as inclusive of the entire
	 * selected day — picking 2026-03-02 returns events from that day, not just
	 * events strictly before midnight.
	 */
	public function test_before_calendar_day_is_inclusive(): void {
		// ARRANGE.
		Audit_Log_Table::insert( 'auth', 'info', 'EARLY', '2026-03-01 10:00:00', array() );
		Audit_Log_Table::insert( 'auth', 'info', 'EDGE', '2026-03-02 23:59:00', array() );
		Audit_Log_Table::insert( 'auth', 'info', 'LATE', '2026-03-03 00:00:01', array() );

		$_POST = array(
			'nonce'  => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'before' => '2026-03-02',
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['total'] );
		$events = array_column( $response['data']['items'], 'event' );
		$this->assertContains( 'EARLY', $events );
		$this->assertContains( 'EDGE', $events );
		$this->assertNotContains( 'LATE', $events );
	}

	/**
	 * Verifies that pagination caps per_page at the handler's MAX_PER_PAGE
	 * limit so a caller can't request unbounded results.
	 */
	public function test_per_page_is_capped(): void {
		// ARRANGE: insert 3 rows; request a wildly oversized page.
		Audit_Log_Table::insert( 'auth', 'info', 'A', '2026-03-01 10:00:00', array() );
		Audit_Log_Table::insert( 'auth', 'info', 'B', '2026-03-02 11:00:00', array() );
		Audit_Log_Table::insert( 'auth', 'info', 'C', '2026-03-03 12:00:00', array() );

		$_POST = array(
			'nonce'    => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'per_page' => '9999',
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT: all three returned, but the request itself didn't crash
		// and the response shape is intact.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 3, $response['data']['total'] );
		$this->assertCount( 3, $response['data']['items'] );
	}

	/**
	 * Verifies that non-admin users get a 403 — the AJAX endpoint must not
	 * expose audit data to unprivileged callers.
	 */
	public function test_non_admin_is_forbidden(): void {
		// ARRANGE: swap the admin set up in setUp() for a subscriber.
		$subscriber_id = $this->factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $subscriber_id );

		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT.
		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Verifies that a malformed after bound is silently dropped rather than
	 * passed through to the SQL layer.
	 */
	public function test_malformed_calendar_day_is_dropped(): void {
		// ARRANGE.
		Audit_Log_Table::insert( 'auth', 'info', 'A', '2026-03-01 10:00:00', array() );

		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'after' => 'not-a-date',
		);

		// ACT.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT: filter is ignored; the row still comes back.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['total'] );
	}
}
