<?php
/**
 * Shared HTTP mocking utilities for media integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Provides reusable helpers for mocking HTTP media downloads in integration tests.
 *
 * Composes Image_Byte_Mock_Trait for the wp_remote_get() interception and adds
 * attachment-count assertions for tests that need them.
 */
trait Mock_Media_HTTP_Trait {

	use Image_Byte_Mock_Trait;

	/**
	 * Gets the total count of attachments in the database.
	 *
	 * @return int Total attachment count.
	 */
	protected function get_attachment_count(): int {
		return count(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'posts_per_page' => -1,
					'post_status'    => 'any',
				)
			)
		);
	}

	/**
	 * Asserts that no new attachments were created since a reference count.
	 *
	 * @param int    $expected_count Expected attachment count.
	 * @param string $message        Optional assertion message.
	 */
	protected function assert_no_new_attachments( int $expected_count, string $message = '' ): void {
		$actual_count = $this->get_attachment_count();
		$this->assertSame(
			$expected_count,
			$actual_count,
			'' !== $message ? $message : 'No new attachments should be created'
		);
	}
}
