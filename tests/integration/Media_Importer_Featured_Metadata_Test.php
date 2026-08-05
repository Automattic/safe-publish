<?php
/**
 * Integration test for featured-image library metadata propagation.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Media\Media_Importer;
use WP_UnitTestCase;

/**
 * Covers the featured-image path in isolation, where the image is resolved by
 * ID rather than found inline. The seeder batch embeds every image inline, so
 * this is the only place the by-ID edit-context fetch and apply runs unmasked.
 */
class Media_Importer_Featured_Metadata_Test extends WP_UnitTestCase {

	use Per_Source_Id_Media_Api_Mock_Trait;
	use Image_Byte_Mock_Trait;

	/**
	 * Source site URL the featured image is fetched from.
	 */
	private const SOURCE_URL = 'https://source.example.com';

	/**
	 * Source featured-media ID served by the mock.
	 */
	private const MEDIA_ID = 9700001;

	/**
	 * Source featured-media ID whose record carries no library metadata.
	 */
	private const EMPTY_MEDIA_ID = 9700002;

	/**
	 * Media REST URL captured from the outbound request.
	 *
	 * @var string
	 */
	private string $requested_url = '';

	/**
	 * Serves the edit-context media record for the seeded featured-media ID.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id( int $source_media_id ): ?array {
		if ( self::MEDIA_ID === $source_media_id ) {
			return array(
				'id'          => $source_media_id,
				'source_url'  => self::SOURCE_URL . '/wp-content/uploads/2025/01/featured.jpg',
				'media_type'  => 'image',
				'mime_type'   => 'image/jpeg',
				'alt_text'    => 'Featured alt <b>bold</b>',
				'title'       => array( 'raw' => 'Featured <i>title</i>' ),
				'caption'     => array( 'raw' => 'Featured <em>caption</em>' ),
				'description' => array( 'raw' => 'Featured <strong>desc</strong>' ),
			);
		}

		if ( self::EMPTY_MEDIA_ID === $source_media_id ) {
			return array(
				'id'         => $source_media_id,
				'source_url' => self::SOURCE_URL . '/wp-content/uploads/2025/01/featured-empty.jpg',
				'media_type' => 'image',
				'mime_type'  => 'image/jpeg',
				'alt_text'   => '',
			);
		}

		return null;
	}

	/**
	 * Records the outbound media REST URL so the test can assert edit context.
	 *
	 * @param false|array|\WP_Error $preempt Preemptive return value.
	 * @param array                 $_args   HTTP arguments (unused).
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function capture_media_url(
		false|array|\WP_Error $preempt,
		array $_args,
		string $url
	): false|array|\WP_Error {
		if ( str_contains( $url, '/wp/v2/media/' ) ) {
			$this->requested_url = $url;
		}

		return $preempt;
	}

	/**
	 * Verifies that a featured image resolved by ID carries the source library
	 * metadata (alt, title, caption, description) fetched in edit context, with
	 * the importer's per-field sanitization applied.
	 */
	public function test_featured_image_by_id_applies_library_metadata(): void {
		// ARRANGE: Run as admin and mock the media record, image bytes, and URL.
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();
		add_filter( 'pre_http_request', array( $this, 'capture_media_url' ), 1, 3 );

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: Import the featured image with valid credentials so the fetch
		// uses edit context.
		try {
			$attachment_id = $importer->import_featured_image(
				self::MEDIA_ID,
				self::SOURCE_URL,
				array( 'shared_secret' => str_repeat( 'a', 32 ) )
			);
		} finally {
			remove_filter(
				'pre_http_request',
				array( $this, 'capture_media_url' ),
				1
			);
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: The attachment carries the sanitized source metadata, fetched
		// in edit context.
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertStringContainsString( 'context=edit', $this->requested_url );

		// alt and title strip tags; caption and description keep the safe HTML
		// wp_kses_post allows.
		$attachment = get_post( $attachment_id );
		$this->assertSame(
			'Featured alt bold',
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
		);
		$this->assertSame( 'Featured title', $attachment->post_title );
		$this->assertSame( 'Featured <em>caption</em>', $attachment->post_excerpt );
		$this->assertSame( 'Featured <strong>desc</strong>', $attachment->post_content );
	}

	/**
	 * Verifies that a media record with no library metadata leaves the
	 * attachment on WordPress' native upload defaults: No alt meta row, a
	 * filename-derived title, and empty caption and description.
	 */
	public function test_empty_source_metadata_leaves_native_defaults(): void {
		// ARRANGE: Run as admin and mock a metadata-less record plus its bytes.
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: Import the featured image.
		try {
			$attachment_id = $importer->import_featured_image(
				self::EMPTY_MEDIA_ID,
				self::SOURCE_URL,
				array( 'shared_secret' => str_repeat( 'a', 32 ) )
			);
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: Nothing was written; the attachment keeps native defaults.
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertFalse(
			metadata_exists( 'post', $attachment_id, '_wp_attachment_image_alt' ),
			'Empty alt should leave the meta absent, like a native upload.'
		);

		$attachment = get_post( $attachment_id );
		$this->assertNotSame(
			'',
			$attachment->post_title,
			'Title should stay the filename-derived default.'
		);
		$this->assertSame( '', $attachment->post_excerpt );
		$this->assertSame( '', $attachment->post_content );
	}
}
