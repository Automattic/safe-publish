<?php
/**
 * Authentication Logger class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish authentication events.
 */
class Auth_Logger extends Logger {

	/**
	 * Events that represent a successful authentication attempt.
	 *
	 * @var string[]
	 */
	private const SUCCESS_EVENTS = array(
		'AUTH_SUCCESS',
	);

	/**
	 * Events that represent a failed authentication attempt.
	 *
	 * @var string[]
	 */
	private const FAILURE_EVENTS = array(
		'NO_SECRET_CONFIGURED',
		'TIMESTAMP_EXPIRED',
		'CONTENT_HASH_MISSING',
		'CONTENT_HASH_MISMATCH',
		'SIGNATURE_INVALID',
	);

	/**
	 * Constructs the Auth_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'auth';
	}
}
