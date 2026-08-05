<?php
/**
 * Request_Actions Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\Request_Actions;

/**
 * Request_Actions Test.
 *
 * Validates the action vocabulary contract: Known constants pass is_valid(),
 * unknown values fall through, and only the two import-shaped actions count
 * as exports.
 */
class RequestActionsTest extends TestCase {

	/**
	 * Verifies that every declared constant is reported as valid.
	 */
	public function test_is_valid_accepts_every_known_constant(): void {
		foreach ( Request_Actions::all() as $action ) {
			$this->assertTrue(
				Request_Actions::is_valid( $action ),
				sprintf( 'Action %s declared in all() should be valid.', $action )
			);
		}
	}

	/**
	 * Verifies that an unrecognized value fails the validation gate.
	 */
	public function test_is_valid_rejects_unrecognized_action(): void {
		$this->assertFalse( Request_Actions::is_valid( 'totally-made-up' ) );
	}

	/**
	 * Verifies that an empty string fails the validation gate — a missing
	 * X-Safe-Publish-Action header is treated the same as an unrecognized
	 * value.
	 */
	public function test_is_valid_rejects_empty_string(): void {
		$this->assertFalse( Request_Actions::is_valid( '' ) );
	}

	/**
	 * Verifies that is_valid is case-sensitive — destination must send the
	 * exact lowercase constant value, not a variant. Otherwise HMAC signing
	 * and dispatch-side classification could diverge on case normalization.
	 */
	public function test_is_valid_is_case_sensitive(): void {
		$this->assertFalse( Request_Actions::is_valid( 'IMPORT' ) );
		$this->assertFalse( Request_Actions::is_valid( 'Import' ) );
	}

	/**
	 * Verifies that only IMPORT and MEDIA_IMPORT count as real exports for
	 * routing to the export audit channel.
	 */
	public function test_is_export_recognizes_only_import_actions(): void {
		$this->assertTrue( Request_Actions::is_export( Request_Actions::IMPORT ) );
		$this->assertTrue( Request_Actions::is_export( Request_Actions::MEDIA_IMPORT ) );

		$this->assertFalse( Request_Actions::is_export( Request_Actions::LIST_ITEMS ) );
		$this->assertFalse( Request_Actions::is_export( Request_Actions::PREVIEW ) );
		$this->assertFalse( Request_Actions::is_export( Request_Actions::PROBE ) );
	}

	/**
	 * Verifies that is_export rejects unrecognized values — defensive guard
	 * so an unrecognized action never accidentally routes to the export
	 * channel.
	 */
	public function test_is_export_rejects_unrecognized_action(): void {
		$this->assertFalse( Request_Actions::is_export( 'totally-made-up' ) );
		$this->assertFalse( Request_Actions::is_export( '' ) );
	}
}
