<?php
/**
 * Helper for tests that need a post lock another request holds
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Post_Lock;

/**
 * Holds a post lock the way another request would.
 *
 * Writes the lock row directly rather than through Post_Lock, so a lock that
 * lives only in this process, like the runtime object cache, cannot see it.
 */
trait Held_Post_Lock_Trait {

	/**
	 * Inserts the lock row for a source post.
	 *
	 * @param int      $source_post_id Source post ID.
	 * @param int|null $acquired_at    Unix time the holder took the lock; now
	 *                                 when null.
	 */
	protected function hold_post_lock(
		int $source_post_id,
		?int $acquired_at = null
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => Post_Lock::OPTION_PREFIX . $source_post_id,
				'option_value' => (string) ( $acquired_at ?? time() ),
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Reads the stored lock row for a source post.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return array{option_value: string, autoload: string}|null Lock row, or
	 *                                                            null when free.
	 */
	protected function post_lock_row( int $source_post_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options}"
					. ' WHERE option_name = %s',
				Post_Lock::OPTION_PREFIX . $source_post_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
