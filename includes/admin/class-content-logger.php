<?php
/**
 * Content Logger class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish content fetch events.
 */
class Content_Logger extends Logger {

	/**
	 * Constructs the Content_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'content';
	}
}
