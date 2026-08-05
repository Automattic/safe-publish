<?php
/**
 * Integration test for off-domain featured-image sideloading.
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
 * Covers featured images whose source_url is served off-domain (CDN, files
 * service, Photon, www-vs-apex). Resolved from a source media ID, they are
 * owned by provenance and must sideload despite the host mismatch that makes
 * the content-media host guard skip genuine third-party URLs.
 */
class Media_Importer_Off_Domain_Featured_Test extends WP_UnitTestCase {

	use Per_Source_Id_Media_Api_Mock_Trait;
	use Mock_Media_HTTP_Trait;

	/**
	 * Source media ID => the off-domain source_url its REST record serves.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_MEDIA = array(
		9800001 => 'https://cdn.example.net/2025/01/featured.jpg',
		9800002 => 'https://example.files.wordpress.com/2025/01/featured.jpg',
		9800003 => 'https://i0.wp.com/example.com/wp-content/uploads/2025/01/featured.jpg?resize=600%2C400',
		9800004 => 'https://www.example.com/wp-content/uploads/2025/01/featured.jpg',
		9800005 => 'https://cdn.example.net/2025/01/featured-broken.jpg',
		9800006 => 'https://cdn.example.net/2025/01/featured-dedup.jpg',
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
	 * Serves the media record for a known off-domain source media ID.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id( int $source_media_id ): ?array {
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
	 * Off-domain featured-image shapes: [source site, media ID, canonical URL].
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function off_domain_case_provider(): array {
		return array(
			'cdn host'             => array(
				'https://source.example.com',
				9800001,
				'https://cdn.example.net/2025/01/featured.jpg',
			),
			'files service host'   => array(
				'https://source.example.com',
				9800002,
				'https://example.files.wordpress.com/2025/01/featured.jpg',
			),
			'photon host'          => array(
				'https://source.example.com',
				9800003,
				'https://i0.wp.com/example.com/wp-content/uploads/2025/01/featured.jpg',
			),
			'www versus apex host' => array(
				'https://example.com',
				9800004,
				'https://www.example.com/wp-content/uploads/2025/01/featured.jpg',
			),
		);
	}

	/**
	 * Verifies that a featured image served off-domain sideloads into a real
	 * attachment, storing the canonical param-stripped source URL and the
	 * source featured-media ID, rather than aborting on the host mismatch.
	 *
	 * @dataProvider off_domain_case_provider
	 *
	 * @param string $source_site   Connected source site URL.
	 * @param int    $media_id      Source featured-media ID.
	 * @param string $canonical_url Expected META_ORIGINAL_URL (param-stripped).
	 */
	public function test_off_domain_featured_image_sideloads(
		string $source_site,
		int $media_id,
		string $canonical_url
	): void {
		// ARRANGE: Mock the media record and the image bytes.
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: Import the featured image resolved by its source ID.
		try {
			$attachment_id = $importer->import_featured_image( $media_id, $source_site );
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: A real attachment was created, tagged with the canonical
		// source URL and the featured-media ID.
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame(
			$canonical_url,
			get_post_meta( $attachment_id, Options::META_ORIGINAL_URL, true )
		);
		$this->assertSame(
			$media_id,
			(int) get_post_meta( $attachment_id, Options::META_FEATURED_MEDIA_ID, true )
		);
	}

	/**
	 * Verifies that importing the same off-domain featured ID twice returns the
	 * existing attachment without creating a duplicate.
	 */
	public function test_reimporting_same_featured_id_dedupes(): void {
		// ARRANGE: Mock the record and bytes for a single off-domain image.
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: Import the same featured ID twice, counting attachments between.
		try {
			$first  = $importer->import_featured_image( 9800006, 'https://source.example.com' );
			$count  = $this->get_attachment_count();
			$second = $importer->import_featured_image( 9800006, 'https://source.example.com' );
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: The second import reuses the first attachment; no new row.
		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Verifies that a featured image whose download fails aborts the import by
	 * returning false, leaving no attachment behind.
	 */
	public function test_unfetchable_featured_image_returns_false(): void {
		// ARRANGE: Resolve the media record but fail the byte download.
		$this->add_per_source_id_media_api_mock();
		add_filter( 'pre_http_request', array( $this, 'fail_image_byte_download' ), 1, 3 );
		$count = $this->get_attachment_count();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: Import a featured image whose bytes cannot be downloaded.
		try {
			$attachment_id = $importer->import_featured_image( 9800005, 'https://source.example.com' );
		} finally {
			remove_filter( 'pre_http_request', array( $this, 'fail_image_byte_download' ), 1 );
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: The import aborts and creates nothing.
		$this->assertFalse( $attachment_id );
		$this->assert_no_new_attachments( $count );
	}

	/**
	 * Fails byte downloads while letting REST (wp-json) requests through to the
	 * media-record mock, so the import aborts at the download step.
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
