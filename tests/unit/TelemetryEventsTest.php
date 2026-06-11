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
		// ARRANGE / ACT: pass a known code through the normalizer.
		$result = Telemetry_Events::normalize_error_code( 'media_download_failed' );

		// ASSERT: returned as-is.
		$this->assertSame( 'media_download_failed', $result );
	}

	/**
	 * Verifies that an unknown code is replaced with the bounded fallback
	 * so unbounded strings can't leak into Pendo.
	 */
	public function test_normalize_error_code_replaces_unknown_with_fallback(): void {
		// ARRANGE / ACT: pass an unrecognized code.
		$result = Telemetry_Events::normalize_error_code( 'something_unexpected' );

		// ASSERT: replaced with the bounded fallback.
		$this->assertSame( Telemetry_Events::ERROR_CODE_UNKNOWN, $result );
	}

	/**
	 * Verifies that media error codes are recognized so the per-item
	 * failure carries a media_failure_count property.
	 */
	public function test_is_media_error_code_recognizes_media_codes(): void {
		// ARRANGE / ACT / ASSERT: both documented media codes are recognized.
		$this->assertTrue( Telemetry_Events::is_media_error_code( 'media_download_failed' ) );
		$this->assertTrue( Telemetry_Events::is_media_error_code( 'malformed_media_markup' ) );
	}

	/**
	 * Verifies that non-media error codes are rejected so unrelated
	 * failures don't carry an irrelevant property.
	 */
	public function test_is_media_error_code_rejects_non_media_codes(): void {
		// ARRANGE / ACT / ASSERT: a non-media code is not recognized.
		$this->assertFalse( Telemetry_Events::is_media_error_code( 'post_create_failed' ) );
		$this->assertFalse( Telemetry_Events::is_media_error_code( Telemetry_Events::ERROR_CODE_UNKNOWN ) );
	}

	/**
	 * Verifies that a clean run with no failures and at least one row
	 * changed is reported as success.
	 */
	public function test_rollback_outcome_success_when_no_failures_and_rows_changed(): void {
		// ARRANGE / ACT: derive outcome from non-zero changed counts and no failures.
		$result = Telemetry_Events::rollback_outcome( 5, 2, 0 );

		// ASSERT: success.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_SUCCESS, $result );
	}

	/**
	 * Verifies that a run where nothing changed and only failures occurred
	 * is reported as failed.
	 */
	public function test_rollback_outcome_failed_when_only_failures(): void {
		// ARRANGE / ACT: derive outcome from zero changed counts and failures.
		$result = Telemetry_Events::rollback_outcome( 0, 0, 3 );

		// ASSERT: failed.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_FAILED, $result );
	}

	/**
	 * Verifies that a mixed result is reported as partial.
	 */
	public function test_rollback_outcome_partial_when_mixed(): void {
		// ARRANGE / ACT: derive outcome from some success and some failures.
		$result = Telemetry_Events::rollback_outcome( 2, 1, 1 );

		// ASSERT: partial.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_PARTIAL, $result );
	}

	/**
	 * Verifies that a no-op rollback (no rows touched at all) is reported
	 * as partial rather than success — there was nothing to undo.
	 */
	public function test_rollback_outcome_partial_when_all_zero(): void {
		// ARRANGE / ACT: derive outcome from all-zero counts.
		$result = Telemetry_Events::rollback_outcome( 0, 0, 0 );

		// ASSERT: partial.
		$this->assertSame( Telemetry_Events::ROLLBACK_OUTCOME_PARTIAL, $result );
	}
}
