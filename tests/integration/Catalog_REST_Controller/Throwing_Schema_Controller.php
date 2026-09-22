<?php
/**
 * Post type REST controller double whose item schema throws
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Catalog_REST_Controller;

use RuntimeException;
use WP_REST_Posts_Controller;

/**
 * Stands in for a third-party controller that errors when the catalog asks
 * a post type for its raw fields.
 */
class Throwing_Schema_Controller extends WP_REST_Posts_Controller {

	/**
	 * Message carried by the thrown error.
	 */
	public const MESSAGE = 'Third-party item schema threw.';

	/**
	 * Throws instead of returning an item schema.
	 *
	 * @throws RuntimeException Always.
	 */
	#[\Override]
	public function get_item_schema() {
		throw new RuntimeException( self::MESSAGE );
	}
}
