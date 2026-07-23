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
 * Covers the WebP upload filters the importer registers around a sideload,
 * ensuring they are removed even when the sideload fails so they cannot leak
 * into later uploads in the same request.
 */
class Media_Importer_Filter_Cleanup_Test extends WP_UnitTestCase {

	/**
	 * Source site the failing media URL belongs to.
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
		// ARRANGE: a same-host media URL whose download will fail.
		add_filter( 'pre_http_request', array( $this, 'fail_download' ), 1, 3 );
		$importer  = new Media_Importer( new HTTP_Client() );
		$media_url = self::SOURCE_URL . '/wp-content/uploads/2025/01/broken.jpg';

		// ACT: attempt the sideload, which aborts at the download step.
		try {
			$result = $importer->import_source_media_as_attachment(
				$media_url,
				self::SOURCE_URL
			);
		} finally {
			remove_filter( 'pre_http_request', array( $this, 'fail_download' ), 1 );
		}

		// ASSERT: the sideload failed and its filetype filter was removed.
		$this->assertFalse( $result );
		$this->assertFalse(
			has_filter(
				'wp_check_filetype_and_ext',
				array( $importer, 'handle_webp_filetype' )
			)
		);
	}
}
