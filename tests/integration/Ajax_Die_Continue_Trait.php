<?php
/**
 * Helper for AJAX tests that expect wp_die() in their handler
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WPAjaxDieContinueException;

/**
 * Dispatches AJAX actions that end in wp_die() and verifies the expected
 * WPAjaxDieContinueException was thrown.
 *
 * After the exception is caught, tests can inspect $this->_last_response.
 */
trait Ajax_Die_Continue_Trait {

	/**
	 * Dispatches an AJAX action expected to terminate via wp_die().
	 *
	 * Fails the test if the action completes without throwing
	 * WPAjaxDieContinueException.
	 *
	 * @param string $action AJAX action slug to dispatch.
	 */
	protected function dispatch_ajax_expecting_die( string $action ): void {
		try {
			$this->_handleAjax( $action );
			$this->fail( "Expected WPAjaxDieContinueException for $action" );
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected; the AJAX handler ends execution via wp_die().
		}
	}
}
