<?php
/**
 * Export Logger class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\API;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish content export events.
 *
 * Records when content is served to a destination site via the REST API,
 * providing an audit trail on the source side.
 */
class Export_Logger extends Logger {

	/**
	 * Constructs the Export_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'export';
	}
}
