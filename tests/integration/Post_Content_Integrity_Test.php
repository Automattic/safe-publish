<?php
/**
 * Post content integrity integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Post_Content_Integrity;
use WP_Error;

/**
 * Post Content Integrity Test Class.
 */
class Post_Content_Integrity_Test extends Integration_Test_Case {

	/**
	 * Verifies that raw database values compare without slashing false positives.
	 */
	public function test_verify_compares_unslashed_values(): void {
		// ARRANGE: Persist content containing backslashes through WordPress.
		$content = '<p>namespace App\Models; $pattern = "\d+";</p>';
		$post_id = wp_insert_post(
			wp_slash( array( 'post_content' => $content ) ),
			true
		);
		$this->assertIsInt( $post_id );

		// ACT: Compare the same unslashed value supplied to the write.
		$result = Post_Content_Integrity::verify(
			$post_id,
			array( 'post_content' => $content ),
			Post_Content_Integrity::OPERATION_IMPORT
		);

		// ASSERT: Normal WordPress slashing does not produce a mismatch.
		$this->assertNull( $result );
	}

	/**
	 * Verifies that fields omitted from a write are not compared.
	 */
	public function test_verify_ignores_omitted_content_fields(): void {
		// ARRANGE: A post has content, but the simulated write supplies only title.
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Existing content.' )
		);

		// ACT: Verify the fields supplied by the simulated write.
		$result = Post_Content_Integrity::verify(
			$post_id,
			array( 'post_title' => 'Updated title' ),
			Post_Content_Integrity::OPERATION_IMPORT
		);

		// ASSERT: Existing content outside the write is ignored.
		$this->assertNull( $result );
	}

	/**
	 * Verifies that every changed supplied content field is identified.
	 */
	public function test_verify_reports_supplied_content_mismatches(): void {
		// ARRANGE: A post differs from both requested content fields.
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Filtered content.',
				'post_excerpt' => 'Filtered excerpt.',
			)
		);

		// ACT: Compare the requested raw fields with the stored post.
		$result = Post_Content_Integrity::verify(
			$post_id,
			array(
				'post_content' => 'Requested content.',
				'post_excerpt' => 'Requested excerpt.',
			),
			Post_Content_Integrity::OPERATION_IMPORT
		);

		// ASSERT: Both supplied mismatches are present in the error data.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'content_filtered', $result->get_error_code() );
		$this->assertSame(
			array( 'post_content', 'post_excerpt' ),
			$result->get_error_data()['fields']
		);
	}
}
