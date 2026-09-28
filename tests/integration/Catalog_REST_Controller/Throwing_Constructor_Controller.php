<?php
/**
 * Post type REST controller double whose constructor throws
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Catalog_REST_Controller;

use RuntimeException;
use WP_REST_Controller;

/**
 * Stands in for a third-party controller that errors on instantiation, which
 * core defers until the catalog asks the post type for its controller.
 */
class Throwing_Constructor_Controller extends WP_REST_Controller {

	/**
	 * Message carried by the thrown error.
	 */
	public const MESSAGE = 'Third-party controller construction threw.';

	/**
	 * Throws instead of constructing.
	 *
	 * @param string $_post_type Post type slug core passes in.
	 *
	 * @throws RuntimeException Always.
	 */
	public function __construct( string $_post_type ) {
		throw new RuntimeException( self::MESSAGE );
	}
}
