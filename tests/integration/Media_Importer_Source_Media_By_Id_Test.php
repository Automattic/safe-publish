<?php
/**
 * Integration tests for Media_Importer::import_source_media_by_id().
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use WP_UnitTestCase;

/**
 * Exercises the shared source-ID resolver that both featured-image import and
 * shortcode ID rewriting rely on, asserting its three outcomes: a resolved and
 * sideloaded attachment ID, null for a dangling reference (unreachable record,
 * or a record with a missing or non-string source_url), and false for a
 * resolved URL whose bytes fail to download.
 */
class Media_Importer_Source_Media_By_Id_Test extends WP_UnitTestCase {

	use Per_Source_Id_Media_Api_Mock_Trait;
	use Mock_Media_HTTP_Trait;

	private const SOURCE = 'https://source.example.com';

	/**
	 * Source media ID => the source_url its REST record serves. The missing-url
	 * ID (9910002) and non-string-url ID (9910004) are handled in the mock body,
	 * not here.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_MEDIA = array(
		9910001 => 'https://source.example.com/wp-content/uploads/2025/01/gallery.jpg',
		9910003 => 'https://source.example.com/wp-content/uploads/2025/01/broken.jpg',
	);

	/**
	 * Runs each import as an administrator so the sideload has upload caps.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
	}

	/**
	 * Serves the media record for a known source media ID. ID 9910002 returns a
	 * record without source_url to exercise the dangling-reference path.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id( int $source_media_id ): ?array {
		if ( 9910002 === $source_media_id ) {
			return array(
				'id'         => $source_media_id,
				'media_type' => 'image',
				'mime_type'  => 'image/jpeg',
				'alt_text'   => '',
			);
		}

		if ( 9910004 === $source_media_id ) {
			// Malformed record: a non-string source_url the guard must reject.
			return array(
				'id'         => $source_media_id,
				'source_url' => array( 'unexpected' ),
				'media_type' => 'image',
				'mime_type'  => 'image/jpeg',
				'alt_text'   => '',
			);
		}

		if ( ! isset( self::SOURCE_MEDIA[ $source_media_id ] ) ) {
			return null;
		}

		return array(
			'id'         => $source_media_id,
			'source_url' => self::SOURCE_MEDIA[ $source_media_id ],
			'media_type' => 'image',
			'mime_type'  => 'image/jpeg',
			'alt_text'   => '',
		);
	}

	/**
	 * Verifies that a resolvable source ID is sideloaded into a real attachment
	 * tagged with the canonical source URL.
	 */
	public function test_resolves_source_id_to_dest_attachment(): void {
		// ARRANGE: mock the media record and the image bytes.
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: resolve the source ID to a destination attachment.
		try {
			$attachment_id = $importer->import_source_media_by_id( 9910001, self::SOURCE );
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: a real attachment was created for the canonical source URL.
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame(
			self::SOURCE_MEDIA[9910001],
			get_post_meta( $attachment_id, Options::META_ORIGINAL_URL, true )
		);
	}

	/**
	 * Verifies that resolving the same source ID twice reuses the first
	 * attachment instead of creating a duplicate.
	 */
	public function test_repeat_id_dedupes(): void {
		// ARRANGE: mock the record and bytes for a single image.
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: resolve the same source ID twice, counting attachments between.
		try {
			$first  = $importer->import_source_media_by_id( 9910001, self::SOURCE );
			$count  = $this->get_attachment_count();
			$second = $importer->import_source_media_by_id( 9910001, self::SOURCE );
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: the second resolution reuses the first attachment; no new row.
		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Verifies that a record carrying no source_url is treated as a dangling
	 * reference: null is returned and no attachment is created.
	 */
	public function test_missing_source_url_returns_null(): void {
		// ARRANGE: mock a record that lacks source_url.
		$this->add_per_source_id_media_api_mock();
		$count = $this->get_attachment_count();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: attempt to resolve the ID with no downloadable URL.
		try {
			$result = $importer->import_source_media_by_id( 9910002, self::SOURCE );
		} finally {
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: dangling reference signalled by null; nothing sideloaded.
		$this->assertNull( $result );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Verifies that a record whose source_url is a non-string (a malformed
	 * source response) is treated as a dangling reference rather than fataling
	 * the string-typed sideload: null is returned and nothing is created.
	 */
	public function test_non_string_source_url_returns_null(): void {
		// ARRANGE: mock a record whose source_url is not a string.
		$this->add_per_source_id_media_api_mock();
		$count = $this->get_attachment_count();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: attempt to resolve a record with a non-string source_url.
		try {
			$result = $importer->import_source_media_by_id( 9910004, self::SOURCE );
		} finally {
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: malformed URL treated as dangling; null, nothing sideloaded.
		$this->assertNull( $result );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Verifies that an unreachable media record (request error) is treated as a
	 * dangling reference and returns null.
	 */
	public function test_fetch_error_returns_null(): void {
		// ARRANGE: no mock registered for this ID, so the request errors.
		$this->add_per_source_id_media_api_mock();
		$count = $this->get_attachment_count();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: attempt to resolve an unregistered source ID.
		try {
			$result = $importer->import_source_media_by_id( 9999999, self::SOURCE );
		} finally {
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: unreachable record signalled by null; nothing sideloaded.
		$this->assertNull( $result );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Verifies that a resolved URL whose bytes fail to download returns false,
	 * distinguishing a genuine sideload failure from a dangling reference.
	 */
	public function test_sideload_failure_returns_false(): void {
		// ARRANGE: resolve the media record but fail the byte download.
		$this->add_per_source_id_media_api_mock();
		add_filter( 'pre_http_request', array( $this, 'fail_image_byte_download' ), 1, 3 );
		$count = $this->get_attachment_count();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: resolve a source ID whose bytes cannot be downloaded.
		try {
			$result = $importer->import_source_media_by_id( 9910003, self::SOURCE );
		} finally {
			remove_filter( 'pre_http_request', array( $this, 'fail_image_byte_download' ), 1 );
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: genuine sideload failure signalled by false; nothing created.
		$this->assertFalse( $result );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Fails byte downloads while letting REST (wp-json) requests through to the
	 * media-record mock, so resolution reaches the download step and fails there.
	 *
	 * @param false|array|\WP_Error $preempt Preemptive return value.
	 * @param array                 $_args   HTTP arguments (unused).
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function fail_image_byte_download(
		false|array|\WP_Error $preempt,
		array $_args,
		string $url
	): false|array|\WP_Error {
		if ( false !== $preempt || str_contains( $url, '/wp-json/' ) ) {
			return $preempt;
		}

		return new \WP_Error( 'http_request_failed', 'Simulated download failure' );
	}
}
