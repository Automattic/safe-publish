<?php
/**
 * Import Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish import-history events such as session and item
 * rollbacks.
 */
class Import_Logger extends Logger {

	/**
	 * Constructs the Import_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'import';
	}
}
