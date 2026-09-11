<?php
/**
 * Unit tests for history read contracts.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Admin\History_Read_Service;
use WP_Error;

if ( ! defined( 'ARRAY_A' ) ) {
	// Supply the WordPress output-mode constant in the unit environment.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Verifies history reads against a narrow database double.
 */
class HistoryReadServiceTest extends TestCase {

	/**
	 * Verifies that positive reads preserve rows and prepare the requested ID.
	 *
	 * @dataProvider read_provider
	 * @param string $method Reader method.
	 * @param string $key    Input ID key.
	 * @param array  $rows   Database response.
	 * @param string $query  Expected SQL fragment.
	 */
	public function test_reads(
		string $method,
		string $key,
		array $rows,
		string $query
	): void {
		// ARRANGE: Replace only the WordPress database boundary for this read.
		$previous         = $GLOBALS['wpdb'] ?? null;
		$database         = $this->getMockBuilder(
			Fake_History_Database::class
		)
			->addMethods( array( 'prepare', 'get_row', 'get_results' ) )
			->getMock();
		$database->prefix = 'example_';
		$database->expects( $this->once() )->method( 'prepare' )
			->with( $this->stringContains( $query ), 17 )
			->willReturn( 'prepared history query' );
		$read_method = 'get_session_items' === $method
			? 'get_results' : 'get_row';
		$database->expects( $this->once() )->method( $read_method )
			->with( 'prepared history query', ARRAY_A )->willReturn( $rows );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $database;

		try {
			// ACT: Read through the public service with the original ID.
			$result = ( new History_Read_Service() )->$method(
				array( $key => 17 )
			);

			// ASSERT: Do not decode payloads or coerce database strings/nulls.
			$this->assertSame( $rows, $result );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$GLOBALS['wpdb'] = $previous;
		}
	}

	/**
	 * Supplies representative database rows for each read operation.
	 *
	 * @return array[] Reader cases.
	 */
	public static function read_provider(): array {
		return array(
			'session' => array(
				'get_session',
				'session_id',
				array(
					'id'           => '17',
					'total_items'  => '3',
					'ended_at_gmt' => null,
				),
				'WHERE i.id = %d GROUP BY i.id',
			),
			'items'   => array(
				'get_session_items',
				'session_id',
				array( array( 'id' => '2' ), array( 'id' => '3' ) ),
				'ORDER BY id ASC',
			),
			'item'    => array(
				'get_item',
				'item_id',
				array(
					'id'              => '17',
					'rolled_back'     => '0',
					'content_changes' => '{"previous_content":"Original"}',
					'error_message'   => null,
				),
				'SELECT * FROM `example_safe_publish_import_items`',
			),
		);
	}

	/**
	 * Verifies that invalid IDs are rejected without touching the database.
	 */
	public function test_invalid_ids(): void {
		// ARRANGE: Each operation requires integer IDs without coercion.
		$service = new History_Read_Service();
		$methods = array( 'get_session', 'get_session_items', 'get_item' );
		foreach ( $methods as $method ) {
			$is_item = 'get_item' === $method;
			$key     = $is_item ? 'item_id' : 'session_id';
			foreach ( array( null, '17', 1.5, true, array() ) as $id ) {
				// ACT: Null also exercises the missing-key contract.
				$result = $service->$method(
					null === $id ? array() : array( $key => $id )
				);

				// ASSERT: The ID kind determines the stable error contract.
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame(
					'invalid_' . $key,
					$result->get_error_code()
				);
				$this->assertSame(
					$is_item ? 'Invalid import item ID'
						: 'Invalid import session ID',
					$result->get_error_message()
				);
			}
		}
	}

	/**
	 * Verifies that failed queries retain missing-row and empty-list results.
	 *
	 * @dataProvider failed_query_provider
	 * @param string       $method Reader method.
	 * @param array        $input  Valid read parameters.
	 * @param array|string $expected Empty result or missing-row error code.
	 */
	public function test_failed_queries(
		string $method,
		array $input,
		array|string $expected
	): void {
		// ARRANGE: A database failure produces null from each query method.
		$previous         = $GLOBALS['wpdb'] ?? null;
		$database         = $this->getMockBuilder( Fake_History_Database::class )
			->addMethods( array( 'prepare', 'get_row', 'get_results', 'get_var' ) )
			->getMock();
		$database->prefix = 'example_';
		$database->posts  = 'example_posts';
		$database->expects( $this->once() )->method( 'prepare' )
			->willReturn( 'failed history query' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $database;

		try {
			// ACT: Read without introducing a different database-error policy.
			$result = ( new History_Read_Service() )->$method( $input );

			// ASSERT: Single rows return an error; collections/counts stay empty.
			if ( is_string( $expected ) ) {
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( $expected, $result->get_error_code() );
			} else {
				$this->assertSame( $expected, $result );
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$GLOBALS['wpdb'] = $previous;
		}
	}

	/**
	 * Supplies valid inputs and historical failed-query outcomes.
	 *
	 * @return array[] Read cases.
	 */
	public static function failed_query_provider(): array {
		$source = array( 'source_site_url' => 'https://example.com' );
		return array(
			array( 'get_session', array( 'session_id' => 17 ), 'session_not_found' ),
			array( 'get_item', array( 'item_id' => 17 ), 'item_not_found' ),
			array( 'get_item_for_post', array( 'post_id' => 17 ), 'item_not_found' ),
			array( 'get_session_items', array( 'session_id' => 0 ), array() ),
			array( 'get_items_for_posts', array( 'post_ids' => array( 17 ) ), array() ),
			array(
				'get_active_items_by_source_ids',
				$source + array( 'source_ids' => array( 17 ) ),
				array(),
			),
			array( 'list_imported_source_rows', $source, array() ),
			array(
				'list_failures',
				$source + array(
					'offset' => 0,
					'limit'  => 10,
				),
				array(),
			),
			array( 'count_failures', $source, array( 'count' => 0 ) ),
		);
	}
}
