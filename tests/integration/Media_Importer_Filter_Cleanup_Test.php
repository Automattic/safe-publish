<?php
/**
 * Integration test for media sideload filter cleanup.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Media\Media_Importer;
use WP_UnitTestCase;

/**
 * Covers the upload filters the importer registers around a sideload, ensuring
 * each is removed on every exit path so none leaks into later uploads in the
 * same request.
 */
class Media_Importer_Filter_Cleanup_Test extends WP_UnitTestCase {

	use Image_Byte_Mock_Trait;

	/**
	 * Source site the fixture media URLs belong to.
	 */
	private const SOURCE_URL = 'https://source.example.com';

	/**
	 * Runs as administrator so the sideload reaches the download step.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
	}

	/**
	 * Fails the download for the fixture media URL.
	 *
	 * @param false|array|\WP_Error $preempt Preemptive return value.
	 * @param array                 $_args   HTTP arguments (unused).
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function fail_download(
		false|array|\WP_Error $preempt,
		array $_args,
		string $url
	): false|array|\WP_Error {
		if ( str_contains( $url, 'broken.jpg' ) ) {
			return new \WP_Error( 'http_request_failed', 'Simulated download failure' );
		}

		return $preempt;
	}

	/**
	 * Verifies that a failed sideload removes the WebP filetype filter it
	 * registered, leaving no hook behind for later uploads in the request.
	 */
	public function test_failed_sideload_removes_webp_filetype_filter(): void {
		// ARRANGE: A same-host media URL whose download will fail.
		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 1, 3 );
		$importer  = new Media_Importer( new HTTP_Client() );
		$media_url = self::SOURCE_URL . '/wp-content/uploads/2025/01/broken.jpg';

		// ACT: Attempt the sideload, which aborts at the download step.
		try {
			$result = $importer->import_source_media_as_attachment(
				$media_url,
				self::SOURCE_URL
			);
		} finally {
			remove_filter( 'pre_http_request', array( $this, 'fail_download' ), 1 );
		}

		// ASSERT: The sideload failed and its filetype filter was removed.
		$this->assertFalse( $result );
		$this->assertFalse(
			has_filter(
				'wp_check_filetype_and_ext',
				array( $importer, 'handle_webp_filetype' )
			)
		);
	}

	/**
	 * Returns a successful, empty response for the unsupported-type fixture URL
	 * so the download step passes and the file-type check is reached.
	 *
	 * @param false|array|\WP_Error $preempt Preemptive return value.
	 * @param array                 $_args   HTTP arguments (unused).
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function succeed_download(
		false|array|\WP_Error $preempt,
		array $_args,
		string $url
	): false|array|\WP_Error {
		if ( str_contains( $url, 'report.xyz' ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
				'headers'  => array(),
			);
		}

		return $preempt;
	}

	/**
	 * Verifies that an unsupported file type removes the WebP filetype filter it
	 * registered, closing the leak the previous success-only removal left on
	 * that branch.
	 */
	public function test_unsupported_file_type_removes_webp_filetype_filter(): void {
		// ARRANGE: A same-host URL that downloads but is not an allowed type.
		add_filter( 'pre_http_request', array( $this, 'succeed_download' ), 1, 3 );
		$importer  = new Media_Importer( new HTTP_Client() );
		$media_url = self::SOURCE_URL . '/wp-content/uploads/2025/01/report.xyz';

		// ACT: Attempt the sideload, which aborts at the file-type check.
		try {
			$result = $importer->import_source_media_as_attachment(
				$media_url,
				self::SOURCE_URL
			);
		} finally {
			remove_filter( 'pre_http_request', array( $this, 'succeed_download' ), 1 );
		}

		// ASSERT: The sideload failed and its filetype filter was removed.
		$this->assertFalse( $result );
		$this->assertFalse(
			has_filter(
				'wp_check_filetype_and_ext',
				array( $importer, 'handle_webp_filetype' )
			)
		);
	}

	/**
	 * Verifies that removing the big-image scaling filter after a sideload does
	 * not detach an identical big_image_size_threshold filter another plugin
	 * registered, which the shared '__return_false' callback would have.
	 */
	public function test_sideload_preserves_foreign_big_image_filter(): void {
		// ARRANGE: A foreign big-image filter and mocked image bytes.
		add_filter( 'big_image_size_threshold', '__return_false' );
		$this->add_image_byte_response_mock();
		$importer  = new Media_Importer( new HTTP_Client() );
		$media_url = self::SOURCE_URL . '/wp-content/uploads/2025/01/photo.jpg';

		try {
			// ACT: Sideload the image.
			$result = $importer->import_source_media_as_attachment(
				$media_url,
				self::SOURCE_URL
			);

			// ASSERT: Succeeded, our callback gone, foreign filter kept.
			$this->assertIsInt( $result );
			$this->assertFalse(
				has_filter(
					'big_image_size_threshold',
					array( $importer, 'disable_big_image_scaling' )
				)
			);
			$this->assertNotFalse(
				has_filter( 'big_image_size_threshold', '__return_false' )
			);
		} finally {
			$this->remove_image_byte_response_mock();
			remove_filter( 'big_image_size_threshold', '__return_false' );
		}
	}

	/**
	 * Verifies that cleanup reports a newly imported attachment deletion veto.
	 */
	public function test_cleanup_reports_attachment_that_survives_deletion(): void {
		// ARRANGE: Sideload an image, then make WordPress veto its deletion.
		$this->add_image_byte_response_mock();
		$importer      = new Media_Importer( new HTTP_Client() );
		$attachment_id = $importer->import_source_media_as_attachment(
			self::SOURCE_URL . '/wp-content/uploads/2025/01/cleanup.jpg',
			self::SOURCE_URL
		);
		$this->remove_image_byte_response_mock();
		$this->assertIsInt( $attachment_id );
		$veto_delete = static fn(): bool => false;
		add_filter( 'pre_delete_attachment', $veto_delete );

		try {
			// ACT: Clean up the attachment created during this import run.
			$surviving_ids = $importer->delete_newly_created_attachments();
		} finally {
			remove_filter( 'pre_delete_attachment', $veto_delete );
		}

		// ASSERT: The contract identifies the attachment that still exists.
		$this->assertSame( array( $attachment_id ), $surviving_ids );
		$this->assertNotNull( get_post( $attachment_id ) );
		wp_delete_attachment( $attachment_id, true );
	}
}
