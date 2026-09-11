<?php
/**
 * Audit read service integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Audit_Read_Service;
use Safe_Publish\Utils\Audit_Log_Table;

/**
 * Verifies that direct audit reads preserve database semantics.
 */
class Audit_Read_Service_Test extends Integration_Test_Case {

	/**
	 * Verifies that date bounds and pagination share an unpaginated total.
	 */
	public function test_direct_read_filters_and_paginates(): void {
		// ARRANGE: Neutral events at both inclusive boundaries and beyond.
		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'audit-test' );
		foreach ( array( '00:00:00', '23:59:59' ) as $time ) {
			Audit_Log_Table::insert(
				'audit-test',
				'warning',
				"ACTOR'S\\PATH_EVENT",
				'2026-03-02 ' . $time,
				array()
			);
		}
		Audit_Log_Table::insert(
			'audit-test',
			'warning',
			"ACTOR'S\\PATH_EVENT",
			'2026-03-03 00:00:00',
			array()
		);

		// ACT: Read the second matching row with unslashed search text.
		$result = ( new Audit_Read_Service() )->get_events(
			array(
				'channels'     => array( 'AUDIT-TEST' ),
				'levels'       => array( 'WARNING', 'unknown' ),
				'event_search' => "ACTOR'S\\PATH",
				'after'        => '2026-03-02',
				'before'       => '2026-03-02',
				'per_page'     => 1,
				'page'         => 2,
			)
		);

		// ASSERT: The second boundary row is returned and payload stays {}.
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame(
			'2026-03-02T00:00:00Z',
			$result['items'][0]['date']
		);
		$this->assertSame(
			'{}',
			wp_json_encode( $result['items'][0]['data'] )
		);
		$this->assertSame( 0, $result['items'][0]['actor_user_id'] );
	}
}
