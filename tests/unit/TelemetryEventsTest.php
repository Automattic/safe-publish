<?php
/**
 * Telemetry Events Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Telemetry_Events;

/**
 * Telemetry Events Test.
 *
 * Verifies the helper methods that normalize raw audit-log values into
 * the bounded telemetry enums.
 */
class TelemetryEventsTest extends TestCase {

	/**
	 * Verifies that an allowed error code passes through unchanged.
	 */
	public function test_normalize_error_code_allows_known_codes(): void {
		// ARRANGE: A documented per-item error code.
		$code = 'media_download_failed';

		// ACT: Pass it through the normalizer.
		$result = Telemetry_Events::normalize_error_code( $code );

		// ASSERT: Returned as-is.
		$this->assertSame( $code, $result );
	}

	/**
	 * Verifies that an unknown code is replaced with the bounded fallback
	 * so unbounded strings can't leak into Pendo.
	 */
	public function test_normalize_error_code_replaces_unknown_with_fallback(): void {
		// ARRANGE: A code not in the allowlist.
		$code = 'something_unexpected';

		// ACT: Pass it through the normalizer.
		$result = Telemetry_Events::normalize_error_code( $code );

		// ASSERT: Replaced with the bounded fallback.
		$this->assertSame( Telemetry_Events::ERROR_CODE_UNKNOWN, $result );
	}

	/**
	 * Verifies that media error codes are recognized so the per-item
	 * failure carries a media_failure_count property.
	 */
	public function test_is_media_error_code_recognizes_media_codes(): void {
		// ARRANGE: The two documented media error codes.
		$download = 'media_download_failed';
		$markup   = 'malformed_media_markup';

		// ACT: Check each.
		$download_is_media = Telemetry_Events::is_media_error_code( $download );
		$markup_is_media   = Telemetry_Events::is_media_error_code( $markup );

		// ASSERT: Both are recognized.
		$this->assertTrue( $download_is_media );
		$this->assertTrue( $markup_is_media );
	}

	/**
	 * Verifies that non-media error codes are rejected so unrelated
	 * failures don't carry an irrelevant property.
	 */
	public function test_is_media_error_code_rejects_non_media_codes(): void {
		// ARRANGE: An unrelated error code and the bounded fallback.
		$unrelated = 'post_create_failed';
		$fallback  = Telemetry_Events::ERROR_CODE_UNKNOWN;

		// ACT: Check each.
		$unrelated_is_media = Telemetry_Events::is_media_error_code( $unrelated );
		$fallback_is_media  = Telemetry_Events::is_media_error_code( $fallback );

		// ASSERT: Neither is recognized.
		$this->assertFalse( $unrelated_is_media );
		$this->assertFalse( $fallback_is_media );
	}

	/**
	 * Verifies that the three configured sync modes pass through unchanged.
	 */
	public function test_normalize_sync_mode_allows_configured_modes(): void {
		// ARRANGE: The three documented modes.
		$import        = 'import';
		$export        = 'export';
		$bidirectional = 'bidirectional';

		// ACT: Normalize each.
		$import_result        = Telemetry_Events::normalize_sync_mode( $import );
		$export_result        = Telemetry_Events::normalize_sync_mode( $export );
		$bidirectional_result = Telemetry_Events::normalize_sync_mode( $bidirectional );

		// ASSERT: Each mode normalizes to itself.
		$this->assertSame( $import, $import_result );
		$this->assertSame( $export, $export_result );
		$this->assertSame( $bidirectional, $bidirectional_result );
	}

	/**
	 * Verifies that the empty-string default from a fresh install maps to
	 * the bounded fallback so Pendo never receives the raw empty string.
	 */
	public function test_normalize_sync_mode_replaces_empty_with_fallback(): void {
		// ARRANGE: The empty default that Options uses.
		$mode = '';

		// ACT: Normalize.
		$result = Telemetry_Events::normalize_sync_mode( $mode );

		// ASSERT: Bounded fallback.
		$this->assertSame( Telemetry_Events::SYNC_MODE_UNCONFIGURED, $result );
	}

	/**
	 * Verifies that the single session_type sentinel passes through
	 * unchanged.
	 */
	public function test_normalize_session_type_allows_single(): void {
		// ARRANGE: The single sentinel.
		$type = Telemetry_Events::SESSION_TYPE_SINGLE;

		// ACT: Normalize.
		$result = Telemetry_Events::normalize_session_type( $type );

		// ASSERT: Returned as-is.
		$this->assertSame( Telemetry_Events::SESSION_TYPE_SINGLE, $result );
	}

	/**
	 * Verifies that any non-single value (including the empty string from
	 * a missing session row) collapses to bulk, matching the schema's
	 * default.
	 */
	public function test_normalize_session_type_collapses_other_values_to_bulk(): void {
		// ARRANGE: The bulk sentinel and the empty-session sentinel.
		$bulk    = Telemetry_Events::SESSION_TYPE_BULK;
		$missing = '';

		// ACT: Normalize each.
		$bulk_result    = Telemetry_Events::normalize_session_type( $bulk );
		$missing_result = Telemetry_Events::normalize_session_type( $missing );

		// ASSERT: Both report as bulk.
		$this->assertSame( Telemetry_Events::SESSION_TYPE_BULK, $bulk_result );
		$this->assertSame( Telemetry_Events::SESSION_TYPE_BULK, $missing_result );
	}

	/**
	 * Verifies that each VIP_Safe_Auth probe status passes through the
	 * connection-outcome normalizer unchanged.
	 */
	public function test_normalize_connection_outcome_allows_known_statuses(): void {
		// ARRANGE: The four bounded probe statuses.
		$statuses = Telemetry_Events::CONNECTION_OUTCOME_ALLOWED;

		// ACT + ASSERT: Each status normalizes to itself.
		foreach ( $statuses as $status ) {
			$this->assertSame(
				$status,
				Telemetry_Events::normalize_connection_outcome( $status )
			);
		}
	}

	/**
	 * Verifies that an unknown probe status collapses to the bounded fallback
	 * so an unbounded string can't reach Pendo.
	 */
	public function test_normalize_connection_outcome_replaces_unknown_with_fallback(): void {
		// ARRANGE: A status outside the allowlist and the empty string that a
		// missing status key would produce.
		$unexpected = 'teapot';
		$missing    = '';

		// ACT: Normalize each.
		$unexpected_result = Telemetry_Events::normalize_connection_outcome( $unexpected );
		$missing_result    = Telemetry_Events::normalize_connection_outcome( $missing );

		// ASSERT: Both report the bounded fallback.
		$this->assertSame(
			Telemetry_Events::CONNECTION_OUTCOME_UNKNOWN,
			$unexpected_result
		);
		$this->assertSame(
			Telemetry_Events::CONNECTION_OUTCOME_UNKNOWN,
			$missing_result
		);
	}

	/**
	 * Verifies that a clean run with no failures and at least one row
	 * changed is reported as success.
	 */
	public function test_rollback_outcome_success_when_no_failures_and_rows_changed(): void {
		// ARRANGE: Five new posts removed and two updates reverted, no
		// failures.
		$deleted  = 5;
		$restored = 2;
		$failed   = 0;

		// ACT: Derive the outcome.
		$result = Telemetry_Events::rollback_outcome( $deleted, $restored, $failed );

		// ASSERT: Success.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_SUCCESS, $result );
	}

	/**
	 * Verifies that a run where nothing changed and only failures occurred
	 * is reported as failed.
	 */
	public function test_rollback_outcome_failed_when_only_failures(): void {
		// ARRANGE: Three items failed to roll back, nothing changed.
		$deleted  = 0;
		$restored = 0;
		$failed   = 3;

		// ACT: Derive the outcome.
		$result = Telemetry_Events::rollback_outcome( $deleted, $restored, $failed );

		// ASSERT: Failed.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_FAILED, $result );
	}

	/**
	 * Verifies that a mixed result is reported as partial.
	 */
	public function test_rollback_outcome_partial_when_mixed(): void {
		// ARRANGE: Some success and some failures.
		$deleted  = 2;
		$restored = 1;
		$failed   = 1;

		// ACT: Derive the outcome.
		$result = Telemetry_Events::rollback_outcome( $deleted, $restored, $failed );

		// ASSERT: Partial.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_PARTIAL, $result );
	}

	/**
	 * Verifies that a no-op rollback (no rows touched at all) is reported
	 * as partial rather than success — there was nothing to undo.
	 */
	public function test_rollback_outcome_partial_when_all_zero(): void {
		// ARRANGE: All-zero counts.
		$deleted  = 0;
		$restored = 0;
		$failed   = 0;

		// ACT: Derive the outcome.
		$result = Telemetry_Events::rollback_outcome( $deleted, $restored, $failed );

		// ASSERT: Partial.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_PARTIAL, $result );
	}
}
