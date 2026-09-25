<?php
/**
 * Helper for tests that force a query to fail at the SQL layer
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Shared helpers for tests that need a query to fail at the SQL layer.
 *
 * Each matcher rewrites the queries it selects into one the server rejects, so
 * the code under test sees a genuine wpdb failure. Registering one also
 * suppresses wpdb's error output. Host classes call restore_failing_queries()
 * first in tearDown(), keeping the rewrite away from any cleanup queries a
 * parent class runs; a test may also call it directly to reinstate normal
 * queries before asserting.
 */
trait Failing_Query_Trait {

	/**
	 * Replacement SQL the server always rejects. It names no table, so no
	 * later schema change can let it succeed.
	 */
	private const FAILING_QUERY = 'SELECT no_such_column';

	/**
	 * Registered query filter callbacks.
	 *
	 * @var array<int, callable>
	 */
	private array $failing_query_filters = array();

	/**
	 * Error suppression captured before the first registration.
	 *
	 * @var bool|null
	 */
	private ?bool $failing_query_suppression = null;

	/**
	 * Forces queries of one kind that mention a table to fail.
	 *
	 * @param string $statement Leading SQL keyword to break, e.g. UPDATE.
	 * @param string $table     Table name the query must mention.
	 */
	protected function fail_table_queries(
		string $statement,
		string $table
	): void {
		$this->register_failing_query(
			static function ( $query ) use ( $statement, $table ): string {
				$query = (string) $query;

				if ( 0 !== stripos( ltrim( $query ), $statement )
					|| ! str_contains( $query, $table )
				) {
					return $query;
				}

				return self::FAILING_QUERY;
			}
		);
	}

	/**
	 * Forces every query carrying a fingerprint to fail.
	 *
	 * @param string $fingerprint SQL fragment unique to the query to break.
	 */
	protected function fail_queries_matching( string $fingerprint ): void {
		$this->register_failing_query(
			static function ( $query ) use ( $fingerprint ): string {
				$query = (string) $query;

				return str_contains( $query, $fingerprint )
					? self::FAILING_QUERY
					: $query;
			}
		);
	}

	/**
	 * Drops the registered failures and restores error suppression.
	 */
	protected function restore_failing_queries(): void {
		global $wpdb;

		foreach ( $this->failing_query_filters as $callback ) {
			remove_filter( 'query', $callback );
		}

		$this->failing_query_filters = array();

		if ( null !== $this->failing_query_suppression ) {
			$wpdb->suppress_errors( $this->failing_query_suppression );
			$this->failing_query_suppression = null;
		}
	}

	/**
	 * Registers a query rewrite and remembers it for removal.
	 *
	 * @param callable $callback Query filter callback.
	 */
	private function register_failing_query( callable $callback ): void {
		global $wpdb;

		if ( null === $this->failing_query_suppression ) {
			$this->failing_query_suppression = $wpdb->suppress_errors( true );
		}

		add_filter( 'query', $callback );

		$this->failing_query_filters[] = $callback;
	}
}
