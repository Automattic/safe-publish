<?php
/**
 * Audit read service tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Admin\Audit_Read_Service;

/**
 * Verifies that audit reads preserve query and response contracts.
 */
class AuditReadServiceTest extends TestCase {

	/**
	 * Verifies that normalized filters reach both reads unchanged.
	 *
	 * @dataProvider query_cases
	 * @param array $input    Service input.
	 * @param array $expected Expected query arguments.
	 */
	public function test_query_contract( array $input, array $expected ): void {
		// ARRANGE: Capture both table operations independently.
		$queries = array();
		$service = new Audit_Read_Service(
			static function ( array $args ) use ( &$queries ): array {
				$queries[] = $args;
				return array();
			},
			static function ( array $args ) use ( &$queries ): int {
				$queries[] = $args;
				return 17;
			}
		);

		// ACT: Read a page with the supplied filters.
		$result = $service->get_events( $input );

		// ASSERT: Pagination does not replace the matching total.
		$this->assertSame( array( $expected, $expected ), $queries );
		$this->assertSame(
			array(
				'items' => array(),
				'total' => 17,
			),
			$result
		);
	}

	/**
	 * Supplies independent input and expected query contracts.
	 *
	 * @return array<string, array{array, array}> Query cases.
	 */
	public static function query_cases(): array {
		return array(
			'defaults'                   => array(
				array(),
				array(
					'limit'  => 25,
					'offset' => 0,
				),
			),
			'cap and absolute page'      => array(
				array(
					'page'     => '-3',
					'per_page' => '9999',
				),
				array(
					'limit'  => 100,
					'offset' => 200,
				),
			),
			'zero bounds'                => array(
				array(
					'page'     => '0',
					'per_page' => '0',
				),
				array(
					'limit'  => 1,
					'offset' => 0,
				),
			),
			'filters and inclusive days' => array(
				array(
					'channels'     => array( 'AUTH', '', '0', 'custom' ),
					'levels'       => array( 'WARNING', 'invalid' ),
					'event_search' => "Actor's event",
					'after'        => '2026-03-01',
					'before'       => '2026-03-02',
					'page'         => 2,
					'per_page'     => 3,
				),
				array(
					'channel'    => array( 'auth', 'custom' ),
					'level'      => array( 'warning' ),
					'event_type' => "Actor's event",
					'after_gmt'  => '2026-03-01 00:00:00',
					'before_gmt' => '2026-03-02 23:59:59',
					'limit'      => 3,
					'offset'     => 3,
				),
			),
			'ignored filters'            => array(
				array(
					'channels'     => 'auth',
					'levels'       => array( 'invalid' ),
					'event_search' => '0',
					'after'        => '2026-02-30',
					'before'       => 'not-a-date',
				),
				array(
					'limit'  => 25,
					'offset' => 0,
				),
			),
			'offset dates'               => array(
				array( 'after' => '2026-03-01T10:00:00+02:00' ),
				array(
					'after_gmt' => '2026-03-01 08:00:00',
					'limit'     => 25,
					'offset'    => 0,
				),
			),
		);
	}

	/**
	 * Verifies that rows retain actor fields and object payloads on the wire.
	 */
	public function test_wire_contract(): void {
		// ARRANGE: Populated, empty, and malformed decoded payloads.
		$actor = array(
			'actor_user_id'      => '7',
			'actor_display_name' => 'Example Actor',
			'actor_source'       => 'cli',
			'nested'             => array( 'key' => 'value' ),
		);
		$rows  = array();
		foreach ( array( $actor, array(), 'invalid' ) as $data ) {
			$rows[] = array(
				'id'             => '8',
				'channel'        => 'auth',
				'level'          => 'info',
				'event'          => 'EXAMPLE',
				'created_at_gmt' => '2026-03-01 10:00:00',
				'data'           => $data,
			);
		}
		$service = new Audit_Read_Service(
			static fn (): array => $rows,
			static fn (): int => 3
		);

		// ACT: Format the decoded rows.
		$result = $service->get_events( array() );

		// ASSERT: Every key and type matches the existing JSON contract.
		$base      = array(
			'id'                 => 8,
			'channel'            => 'auth',
			'level'              => 'info',
			'event'              => 'EXAMPLE',
			'date'               => '2026-03-01T10:00:00Z',
			'actor_user_id'      => 0,
			'actor_display_name' => '',
			'actor_source'       => '',
			'data'               => (object) array(),
		);
		$populated = array_replace(
			$base,
			array(
				'actor_user_id'      => 7,
				'actor_display_name' => 'Example Actor',
				'actor_source'       => 'cli',
				'data'               => (object) $actor,
			)
		);
		$this->assertSame(
			wp_json_encode(
				array(
					'items' => array( $populated, $base, $base ),
					'total' => 3,
				)
			),
			wp_json_encode( $result )
		);
	}
}
