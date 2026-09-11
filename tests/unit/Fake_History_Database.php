<?php
/**
 * Database prefix for history read unit doubles.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

/**
 * Supplies the table prefix while PHPUnit doubles the query methods.
 */
class Fake_History_Database {

	/**
	 * Isolated table prefix.
	 *
	 * Read through the WordPress database global by table-name helpers.
	 *
	 * @psalm-suppress PossiblyUnusedProperty
	 * @var string
	 */
	public string $prefix;

	/**
	 * Posts table name for the imported-row join.
	 *
	 * @psalm-suppress PossiblyUnusedProperty
	 * @var string
	 */
	public string $posts;
}
