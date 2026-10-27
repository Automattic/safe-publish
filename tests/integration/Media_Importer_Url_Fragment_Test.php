<?php
/**
 * Integration tests for media URLs that carry a fragment
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
 * Covers media URLs whose fragment, such as #page=2, a browser keeps but never
 * sends to the server, so it names the same file as the plain URL.
 */
class Media_Importer_Url_Fragment_Test extends WP_UnitTestCase {

	use Mock_Media_HTTP_Trait;

	/**
	 * Source site URL.
	 *
	 * @var string
	 */
	private const SOURCE_SITE = 'https://source.example.com';

	/**
	 * Plain source media URL.
	 *
	 * @var string
	 */
	private const PLAIN_URL = 'https://source.example.com/wp-content/uploads/2024/01/guide.jpg';

	/**
	 * Source media ID that no attachment records, so lookups fall back to the
	 * source URL.
	 *
	 * @var int
	 */
	private const UNKNOWN_MEDIA_ID = 9810726;

	/**
	 * Runs each import as an administrator so the sideload has upload caps.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
		$this->add_image_byte_response_mock();
	}

	/**
	 * Removes the HTTP mock.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_image_byte_response_mock();
		parent::tearDown();
	}

	/**
	 * Verifies that a URL with a fragment imports under the plain URL, so the
	 * file name carries no fragment, and the plain URL and the lookup without
	 * an import both find the attachment.
	 */
	public function test_fragment_url_imports_as_plain_url(): void {
		// ARRANGE: An importer and the fragment form of the plain URL.
		$importer      = new Media_Importer( new HTTP_Client() );
		$attachment_id = null;

		// ACT: Import the fragment form and the plain URL, then look it up.
		$first  = $importer->import_source_media(
			self::PLAIN_URL . '#page=2',
			self::SOURCE_SITE,
			false,
			$attachment_id
		);
		$count  = $this->get_attachment_count();
		$second = $importer->import_source_media(
			self::PLAIN_URL,
			self::SOURCE_SITE
		);
		$found  = Media_Importer::find_imported_attachment_id(
			self::UNKNOWN_MEDIA_ID,
			self::SOURCE_SITE,
			self::PLAIN_URL . '#page=2'
		);

		// ASSERT: One attachment, recorded and named by the plain URL.
		$this->assertIsInt( $attachment_id );
		$this->assertMatchesRegularExpression(
			'/^guide(-\d+)?\.jpg$/',
			wp_basename( (string) get_attached_file( $attachment_id ) )
		);
		$this->assertSame(
			self::PLAIN_URL,
			get_post_meta( $attachment_id, Options::META_ORIGINAL_URL, true )
		);
		$this->assertSame( $first, $second );
		$this->assert_no_new_attachments( $count );
		$this->assertSame( $attachment_id, $found );
	}
}
