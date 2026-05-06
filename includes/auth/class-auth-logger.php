<?php
/**
 * Authentication Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Auth;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish authentication events.
 */
class Auth_Logger extends Logger {

	/**
	 * Constructs the Auth_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'auth';
	}
}
