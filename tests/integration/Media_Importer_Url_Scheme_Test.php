<?php
/**
 * Integration tests for media URL copies that differ only in scheme
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers copies of one source file that differ only in http or https. The
 * fixture mock answers any image URL, so tests that import media assert the
 * exact list of requested URLs.
 */
class Media_Importer_Url_Scheme_Test extends WP_UnitTestCase {

	use Per_Source_Id_Media_Api_Mock_Trait;
	use Mock_Media_HTTP_Trait;

	private const SOURCE    = 'https://source.example.com';
	private const PATH      = '/wp-content/uploads/2024/01/photo.jpg';
	private const HTTPS_URL = self::SOURCE . self::PATH;
	private const HTTP_URL  = 'http://source.example.com' . self::PATH;

	/**
	 * Source media ID of the featured image, whose record serves the https URL.
	 *
	 * @var int
	 */
	private const MEDIA_ID = 9810760;

	/**
	 * URLs the import requested.
	 *
	 * @var list<string>
	 */
	private array $requested = array();

	/**
	 * Registers the HTTP mocks, with the request recorder ahead of them.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		add_filter(
			'pre_http_request',
			array( $this, 'record_request' ),
			0,
			3
		);
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();
	}

	/**
	 * Removes the HTTP mocks and the request recorder.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_image_byte_response_mock();
		$this->remove_per_source_id_media_api_mock();
		remove_filter(
			'pre_http_request',
			array( $this, 'record_request' ),
			0
		);

		parent::tearDown();
	}

	/**
	 * Records the requested URL and leaves the response to the mocks.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function record_request(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		$this->requested[] = $url;

		return $preempt;
	}

	/**
	 * Serves the media record for the featured source media ID.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id(
		int $source_media_id
	): ?array {
		return self::MEDIA_ID === $source_media_id
			? array( 'source_url' => self::HTTPS_URL )
			: null;
	}

	/**
	 * Verifies that copies of a file that differ only in scheme, letter case
	 * included, import once and all point at that attachment.
	 */
	public function test_scheme_copies_in_markup_import_once(): void {
		// ARRANGE: Classic content holding the file under three scheme forms.
		$html   = '<p><img src="' . self::HTTPS_URL . '"></p>'
			. '<p><img src="' . self::HTTP_URL . '"></p>'
			. '<p><img src="HTTP://source.example.com' . self::PATH . '"></p>';
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = $this->process( $html );

		// ASSERT: One download created one attachment, which every copy names.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertSame( array( self::HTTPS_URL ), $this->requested );
		$url = wp_get_attachment_url( $this->newest_attachment_id() );
		$this->assertSame(
			str_repeat( '<p><img src="' . $url . '"></p>', 3 ),
			$result
		);
	}

	/**
	 * Verifies that an image block whose url attribute and src name the file
	 * under different schemes imports it once, and that its ID, src, and image
	 * class all name that attachment.
	 */
	public function test_image_block_with_mixed_schemes_names_one_attachment(): void {
		// ARRANGE: A relative url attribute, which resolves to https, beside an
		// http src.
		$html   = '<!-- wp:image {"id":123,"url":"' . self::PATH . '"} -->'
			. '<figure class="wp-block-image"><img src="' . self::HTTP_URL
			. '" alt="" class="wp-image-123"/></figure><!-- /wp:image -->';
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = $this->process( $html );

		// ASSERT: One download created one attachment, which the block names
		// throughout.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertSame( array( self::HTTPS_URL ), $this->requested );
		$id  = $this->newest_attachment_id();
		$url = wp_get_attachment_url( $id );
		$this->assertSame(
			'<!-- wp:image {"id":' . $id . ',"url":"' . $url . '"} -->'
				. '<figure class="wp-block-image"><img src="' . $url
				. '" alt="" class="wp-image-' . $id . '"/></figure>'
				. '<!-- /wp:image -->',
			$result
		);
	}

	/**
	 * Verifies that a featured image whose media record serves the https URL
	 * reuses the attachment that an http copy in the content imported.
	 */
	public function test_featured_image_reuses_inline_copy_under_other_scheme(): void {
		// ARRANGE: Import the http copy, as the content pass does first.
		$importer  = new Media_Importer( new HTTP_Client() );
		$inline_id = null;
		$importer->import_source_media(
			self::HTTP_URL,
			self::SOURCE,
			false,
			$inline_id
		);
		$before          = $this->get_attachment_count();
		$this->requested = array();

		// ACT: Import the featured image.
		$featured_id = $importer->import_featured_image(
			self::MEDIA_ID,
			self::SOURCE
		);

		// ASSERT: Only the media record was fetched, and the inline attachment
		// was reused.
		$this->assertIsInt( $inline_id );
		$this->assertSame( $inline_id, $featured_id );
		$this->assert_no_new_attachments( $before );
		$this->assertSame(
			array( self::SOURCE . '/wp-json/wp/v2/media/' . self::MEDIA_ID ),
			$this->requested
		);
	}

	/**
	 * Verifies that an exact match wins over a copy imported under the other
	 * scheme, so each copy keeps its own earlier import.
	 */
	public function test_exact_url_match_wins_over_other_scheme(): void {
		// ARRANGE: One earlier import per scheme, the https one older.
		$https_id = $this->seed_imported_attachment( self::HTTPS_URL );
		$http_id  = $this->seed_imported_attachment( self::HTTP_URL );

		// ACT: Resolve each copy.
		$https_match = Media_Importer::find_imported_attachment_id(
			self::MEDIA_ID,
			self::SOURCE,
			self::HTTPS_URL
		);
		$http_match  = Media_Importer::find_imported_attachment_id(
			self::MEDIA_ID,
			self::SOURCE,
			self::HTTP_URL
		);

		// ASSERT: Each copy resolved to the attachment recorded for it.
		$this->assertSame( $https_id, $https_match );
		$this->assertSame( $http_id, $http_match );
	}

	/**
	 * Runs content through the full import pipeline.
	 *
	 * @param string $content Source content.
	 * @return string Processed content.
	 */
	private function process( string $content ): string {
		$importer = new Media_Importer( new HTTP_Client() );
		$result   = ( new Content_Processor(
			$importer,
			new Content_Media_Processor( $importer ),
			new Shortcode_ID_Rewriter()
		) )->process_content( $content, self::SOURCE );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * Returns the most recently created attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function newest_attachment_id(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		return (int) $ids[0];
	}

	/**
	 * Seeds an attachment recorded as imported from a source URL.
	 *
	 * @param string $source_url Source URL recorded on the attachment.
	 * @return int Attachment ID.
	 */
	private function seed_imported_attachment( string $source_url ): int {
		$id = self::factory()->attachment->create(
			array( 'post_mime_type' => 'image/jpeg' )
		);
		$this->assertIsInt( $id );
		update_post_meta( $id, Options::META_ORIGINAL_URL, $source_url );

		return $id;
	}
}
