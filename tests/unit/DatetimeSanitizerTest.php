<?php
/**
 * Datetime Sanitizer Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Datetime_Sanitizer;

/**
 * Datetime Sanitizer Test Class.
 */
class DatetimeSanitizerTest extends TestCase {

	/**
	 * Verifies that null and empty strings normalize to null so callers
	 * can use a single absent check.
	 */
	public function test_null_and_empty_input_return_null(): void {
		// ACT + ASSERT.
		$this->assertNull( Datetime_Sanitizer::sanitize_iso_datetime( null ) );
		$this->assertNull( Datetime_Sanitizer::sanitize_iso_datetime( '' ) );
	}

	/**
	 * Verifies that non-string inputs are rejected outright.
	 */
	public function test_non_string_input_returns_false(): void {
		// ACT + ASSERT.
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( 42 ) );
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( array() ) );
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( true ) );
	}

	/**
	 * Verifies that a UTC ISO 8601 datetime with Z suffix parses into a
	 * MySQL datetime in UTC.
	 */
	public function test_iso_datetime_with_z_suffix_parses(): void {
		// ACT.
		$result = Datetime_Sanitizer::sanitize_iso_datetime( '2026-06-10T15:30:00Z' );

		// ASSERT.
		$this->assertSame( '2026-06-10 15:30:00', $result );
	}

	/**
	 * Verifies that an ISO 8601 datetime with a TZ offset is normalized
	 * to UTC in the MySQL output.
	 */
	public function test_iso_datetime_with_offset_normalizes_to_utc(): void {
		// ACT: 10am EDT is 14:00 UTC.
		$result = Datetime_Sanitizer::sanitize_iso_datetime( '2026-06-10T10:00:00-04:00' );

		// ASSERT.
		$this->assertSame( '2026-06-10 14:00:00', $result );
	}

	/**
	 * Verifies that a bare ISO datetime (no zone) is treated as-is — the
	 * helper trusts the caller's intent to send UTC datetimes.
	 */
	public function test_bare_iso_datetime_is_kept_verbatim(): void {
		// ACT.
		$result = Datetime_Sanitizer::sanitize_iso_datetime( '2026-06-10T15:30:00' );

		// ASSERT.
		$this->assertSame( '2026-06-10 15:30:00', $result );
	}

	/**
	 * Verifies that a bare calendar day with $ceiling=false resolves to
	 * the start of that day.
	 */
	public function test_calendar_day_without_ceiling_returns_start_of_day(): void {
		// ACT.
		$result = Datetime_Sanitizer::sanitize_iso_datetime( '2026-06-10', false );

		// ASSERT.
		$this->assertSame( '2026-06-10 00:00:00', $result );
	}

	/**
	 * Verifies that a bare calendar day with $ceiling=true resolves to
	 * the end of that day so an inclusive upper bound captures all events
	 * within the picked day.
	 */
	public function test_calendar_day_with_ceiling_returns_end_of_day(): void {
		// ACT.
		$result = Datetime_Sanitizer::sanitize_iso_datetime( '2026-06-10', true );

		// ASSERT.
		$this->assertSame( '2026-06-10 23:59:59', $result );
	}

	/**
	 * Verifies that calendar-day overflow (e.g. month 13) is rejected
	 * rather than silently normalized — DateTimeImmutable would otherwise
	 * coerce 2026-13-01 to 2027-01-01.
	 */
	public function test_calendar_day_overflow_returns_false(): void {
		// ACT + ASSERT.
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( '2026-13-01' ) );
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( '2026-02-30' ) );
	}

	/**
	 * Verifies that a free-form unparseable string returns false rather
	 * than null so callers can distinguish "absent" from "malformed".
	 */
	public function test_unparseable_string_returns_false(): void {
		// ACT + ASSERT.
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( 'not-a-date' ) );
		$this->assertFalse( Datetime_Sanitizer::sanitize_iso_datetime( '2026/06/10' ) );
	}

	/**
	 * Verifies that a MySQL GMT datetime is formatted as ISO 8601 with a Z
	 * marker so the wire payload carries an explicit UTC timestamp.
	 */
	public function test_gmt_to_iso8601_marks_utc_with_z(): void {
		// ACT: Format a MySQL GMT datetime.
		$result = Datetime_Sanitizer::gmt_to_iso8601( '2026-06-10 15:30:00' );

		// ASSERT: The space becomes 'T' and a 'Z' marks UTC.
		$this->assertSame( '2026-06-10T15:30:00Z', $result );
	}

	/**
	 * Verifies that empty and MySQL zero-date sentinel inputs collapse to an
	 * empty string rather than a malformed '0000-00-00T00:00:00Z'.
	 */
	public function test_gmt_to_iso8601_empty_and_zero_dates_return_empty(): void {
		// ACT + ASSERT: Empty and zero-date inputs both collapse to ''.
		$this->assertSame( '', Datetime_Sanitizer::gmt_to_iso8601( '' ) );
		$this->assertSame(
			'',
			Datetime_Sanitizer::gmt_to_iso8601( '0000-00-00 00:00:00' )
		);
	}
}
