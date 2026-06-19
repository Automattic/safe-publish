<?php
/**
 * Source Posts API test double serving canned catalog pages.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use Safe_Publish\API\Source_Posts_API;
use WP_Error;

/**
 * Source Posts API double serving canned catalog pages by page number.
 */
class Fake_Catalog_Source_Posts_API extends Source_Posts_API {

	/**
	 * Canned responses keyed by requested page number.
	 *
	 * @var array<int, array{items: list<array>, has_more: bool}>
	 */
	public array $pages = array();

	/**
	 * Response returned for any page absent from $pages.
	 *
	 * @var array{items: list<array>, has_more: bool}
	 */
	public array $default_page = array(
		'items'    => array(),
		'has_more' => false,
	);

	/**
	 * Captured per_page values, in call order.
	 *
	 * @var int[]
	 */
	public array $requested_per_pages = array();

	/**
	 * Constructs the double without the parent's dependencies.
	 */
	public function __construct() {}

	/**
	 * Returns the canned page for the requested page number.
	 *
	 * @param string $source_site_url  Source URL; unused by the double.
	 * @param array  $auth_credentials Credentials; unused by the double.
	 * @param array  $args             Request args; reads page and per_page.
	 * @return array|WP_Error Canned { items, has_more } page.
	 */
	#[\Override]
	public function fetch_posts(
		string $source_site_url,
		array $auth_credentials = array(),
		array $args = array()
	): array|WP_Error {
		$this->requested_per_pages[] = (int) ( $args['per_page'] ?? 0 );

		return $this->pages[ (int) ( $args['page'] ?? 1 ) ]
			?? $this->default_page;
	}
}
