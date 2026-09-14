<?php
/**
 * Audit read adapter contract tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Audit_Log_Page;
use Safe_Publish\Auth\Permissions;
use Safe_Publish\Utils\Audit_Log_Table;
use WP_Ajax_UnitTestCase;
use WPAjaxDieStopException;

/**
 * Verifies that the adapter retains request and response semantics.
 */
class Audit_Read_Ajax_Contract_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Verifies that an invalid nonce fails before the capability check.
	 */
	public function test_invalid_nonce_retains_bare_failure(): void {
		// ARRANGE: An unprivileged user and an invalid nonce.
		( new Audit_Log_Page() )->init();
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'subscriber' ) )
		);
		$_POST = array( 'nonce' => 'invalid' );

		// ACT: Dispatch the request with the legacy nonce failure path.
		try {
			$this->_handleAjax( 'safe_publish_get_audit_events' );
			$this->fail( 'Expected the nonce check to stop the request.' );
		} catch ( WPAjaxDieStopException $error ) {
			// ASSERT: The nonce failure remains bare -1, not a JSON envelope.
			$this->assertSame( '-1', $error->getMessage() );
		}
	}

	/**
	 * Verifies that the capability denial retains its exact JSON data.
	 */
	public function test_forbidden_envelope_is_unchanged(): void {
		// ARRANGE: A valid nonce without audit read permission.
		( new Audit_Log_Page() )->init();
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'subscriber' ) )
		);
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Dispatch the authorized transport request.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT: Error data is a string with the original message.
		$this->assertSame(
			array(
				'success' => false,
				'data'    => 'Forbidden',
			),
			json_decode( $this->_last_response, true )
		);
	}

	/**
	 * Verifies that an audit-only request unslashes search text exactly once.
	 */
	public function test_audit_only_slashed_search_retains_object_data(): void {
		// ARRANGE: An audit-only actor and an event with both escaping cases.
		( new Audit_Log_Page() )->init();
		$user_id = self::factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		( new \WP_User( $user_id ) )->add_cap(
			Permissions::VIEW_AUDIT_LOG_CAPABILITY
		);
		wp_set_current_user( $user_id );
		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'audit-test' );
		Audit_Log_Table::insert(
			'audit-test',
			'info',
			"ACTOR'S\\PATH_EVENT",
			'2026-03-02 10:00:00',
			array()
		);
		$_POST = array(
			'nonce'        => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'channels'     => array( 'audit-test' ),
			'event_search' => wp_slash( "ACTOR'S\\PATH" ),
		);

		// ACT: Dispatch through the adapter and service.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_audit_events' );

		// ASSERT: Permission and one unslash preserve the event and data.
		$response = json_decode( $this->_last_response );
		$this->assertTrue( $response->success );
		$this->assertSame( 1, $response->data->total );
		$this->assertCount( 1, $response->data->items );
		$this->assertSame(
			"ACTOR'S\\PATH_EVENT",
			$response->data->items[0]->event
		);
		$this->assertSame(
			'{}',
			wp_json_encode( $response->data->items[0]->data )
		);
	}
}
