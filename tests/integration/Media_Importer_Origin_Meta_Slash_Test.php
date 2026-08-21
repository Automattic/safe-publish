<?php
/**
 * Integration test for backslashes in attachment origin meta.
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
 * Covers origin meta whose value carries a backslash. Core unslashes on save, so
 * an unslashed write strips it; the dedupe lookups compare the raw value, miss,
 * and sideload the same file again.
 */
class Media_Importer_Origin_Meta_Slash_Test extends WP_UnitTestCase {

	use Per_Source_Id_Media_Api_Mock_Trait;
	use Mock_Media_HTTP_Trait;

	/**
	 * Connected source site whose path carries a backslash.
	 *
	 * @var string
	 */
	private const SOURCE_SITE = 'https://source.example.com/bl\og';

	/**
	 * Inline media URL whose path carries a backslash.
	 *
	 * @var string
	 */
	private const INLINE_URL = 'https://source.example.com/wp-content/up\loads/inline.jpg';

	/**
	 * Source media ID resolved by the featured-image path.
	 *
	 * @var int
	 */
	private const FEATURED_MEDIA_ID = 9810001;

	/**
	 * The source_url the featured media record serves.
	 *
	 * @var string
	 */
	private const FEATURED_URL = 'https://source.example.com/wp-content/up\loads/featured.jpg';

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
	 * Serves the media record for the featured source media ID.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id( int $source_media_id ): ?array {
		if ( self::FEATURED_MEDIA_ID !== $source_media_id ) {
			return null;
		}

		return array(
			'id'         => $source_media_id,
			'source_url' => self::FEATURED_URL,
			'media_type' => 'image',
			'mime_type'  => 'image/jpeg',
			'alt_text'   => '',
		);
	}

	/**
	 * Verifies that inline media whose URL carries a backslash stores its origin
	 * meta intact and dedupes on re-import, covering the by-URL lookup that
	 * reading the meta back does not.
	 */
	public function test_inline_backslash_url_stores_origin_meta_and_dedupes(): void {
		// ARRANGE: Serve fixture bytes for the backslash-bearing media URL.
		$this->add_image_byte_response_mock();

		$importer      = new Media_Importer( new HTTP_Client() );
		$attachment_id = null;

		// ACT: Import the same URL twice, counting attachments between.
		try {
			$first  = $importer->import_source_media(
				self::INLINE_URL,
				self::SOURCE_SITE,
				false,
				$attachment_id
			);
			$count  = $this->get_attachment_count();
			$second = $importer->import_source_media(
				self::INLINE_URL,
				self::SOURCE_SITE
			);
		} finally {
			$this->remove_image_byte_response_mock();
		}

		// ASSERT: The re-import reused the attachment rather than sideloading a
		// second copy, and both origin values round-tripped.
		$this->assertIsString( $first );
		$this->assertIsInt( $attachment_id );
		$this->assertSame( $first, $second );
		$this->assert_no_new_attachments( $count );
		$this->assertSame(
			self::INLINE_URL,
			get_post_meta( $attachment_id, Options::META_ORIGINAL_URL, true )
		);
		$this->assertSame(
			self::SOURCE_SITE,
			get_post_meta( $attachment_id, Options::META_IMPORTED_FROM, true )
		);
	}

	/**
	 * Verifies that a featured image resolved from a backslash-bearing source
	 * site stores its origin meta intact and dedupes on re-import, covering the
	 * source-identity lookup that reading the meta back does not.
	 */
	public function test_featured_backslash_source_stores_origin_meta_and_dedupes(): void {
		// ARRANGE: Mock the media record and its fixture bytes.
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		$importer = new Media_Importer( new HTTP_Client() );

		// ACT: Import the same featured ID twice, counting attachments between.
		try {
			$first  = $importer->import_featured_image(
				self::FEATURED_MEDIA_ID,
				self::SOURCE_SITE
			);
			$count  = $this->get_attachment_count();
			$second = $importer->import_featured_image(
				self::FEATURED_MEDIA_ID,
				self::SOURCE_SITE
			);
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		// ASSERT: The re-import resolved the existing attachment by source
		// identity, and both origin values round-tripped.
		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assert_no_new_attachments( $count );
		$this->assertSame(
			self::FEATURED_URL,
			get_post_meta( $first, Options::META_ORIGINAL_URL, true )
		);
		$this->assertSame(
			self::SOURCE_SITE,
			get_post_meta( $first, Options::META_IMPORTED_FROM, true )
		);
	}
}
