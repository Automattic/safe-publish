<?php
/**
 * Media Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Media;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish media import events.
 */
class Media_Logger extends Logger {

	/**
	 * Constructs the Media_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'media';
	}
}
