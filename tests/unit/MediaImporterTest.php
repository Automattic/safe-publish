<?php
/**
 * Media Importer Test
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\API\HTTP_Client;

/**
 * Media Importer Test.
 *
 * Tests complex WebP logic in the Media_Importer class.
 */
class MediaImporterTest extends TestCase {

	/**
	 * Media Importer instance.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $importer;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Create real HTTP_Client instance (WebP methods don't use it).
		$http_client    = new HTTP_Client();
		$this->importer = new Media_Importer( $http_client );
	}

	/**
	 * Verifies that WebP MIME type is added to allowed uploads.
	 */
	public function test_add_webp_mime_type_adds_webp_to_allowed_types(): void {
		$mime_types = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
		);

		$result = $this->importer->add_webp_mime_type( $mime_types );

		$this->assertArrayHasKey( 'webp', $result );
		$this->assertSame( 'image/webp', $result['webp'] );

		// Verify original MIME types are preserved.
		$this->assertArrayHasKey( 'jpg|jpeg|jpe', $result );
		$this->assertArrayHasKey( 'png', $result );
	}

	/**
	 * Data provider for WebP case variations.
	 *
	 * @return array<string, array{filename: string, description: string}>
	 */
	public static function webp_case_variations_provider(): array {
		return array(
			'lowercase'  => array(
				'filename'    => 'test-image.webp',
				'description' => 'lowercase extension',
			),
			'uppercase'  => array(
				'filename'    => 'test-image.WEBP',
				'description' => 'uppercase extension',
			),
			'mixed-case' => array(
				'filename'    => 'test-image.WebP',
				'description' => 'mixed-case extension',
			),
		);
	}

	/**
	 * Verifies that WebP detection is case-insensitive.
	 *
	 * @dataProvider webp_case_variations_provider
	 *
	 * @param string $filename    Filename to test.
	 * @param string $description Test case description.
	 */
	public function test_handle_webp_filetype_is_case_insensitive(
		string $filename,
		string $description
	): void {
		$wp_check_filetype_and_ext = array(
			'ext'             => false,
			'type'            => false,
			'proper_filename' => false,
		);

		$file = '/tmp/' . $filename;

		$result = $this->importer->handle_webp_filetype(
			$wp_check_filetype_and_ext,
			$file,
			$filename
		);

		$this->assertSame( 'webp', $result['ext'], "Should handle {$description}" );
		$this->assertSame( 'image/webp', $result['type'], "Should handle {$description}" );
	}

	/**
	 * Verifies that non-WebP files are not modified.
	 */
	public function test_handle_webp_filetype_preserves_non_webp_files(): void {
		$wp_check_filetype_and_ext = array(
			'ext'             => 'jpg',
			'type'            => 'image/jpeg',
			'proper_filename' => false,
		);

		$filename = 'test-image.jpg';
		$file     = '/tmp/test-image.jpg';

		$result = $this->importer->handle_webp_filetype(
			$wp_check_filetype_and_ext,
			$file,
			$filename
		);

		// Should not modify already-valid file types.
		$this->assertSame( 'jpg', $result['ext'] );
		$this->assertSame( 'image/jpeg', $result['type'] );
	}

	/**
	 * Verifies that non-WebP unrecognized files are left unmodified.
	 */
	public function test_handle_webp_filetype_only_affects_webp_extension(): void {
		$wp_check_filetype_and_ext = array(
			'ext'             => false,
			'type'            => false,
			'proper_filename' => false,
		);

		$filename = 'test-image.png';
		$file     = '/tmp/test-image.png';

		$result = $this->importer->handle_webp_filetype(
			$wp_check_filetype_and_ext,
			$file,
			$filename
		);

		// Should not modify non-WebP files.
		$this->assertFalse( $result['ext'] );
		$this->assertFalse( $result['type'] );
	}

	/**
	 * Verifies that WebP handling does not override existing type.
	 */
	public function test_handle_webp_filetype_does_not_override_existing_type(): void {
		$wp_check_filetype_and_ext = array(
			'ext'             => false,
			'type'            => 'image/jpeg', // Type already set.
			'proper_filename' => false,
		);

		$filename = 'test.webp';
		$file     = '/tmp/test.webp';

		$result = $this->importer->handle_webp_filetype(
			$wp_check_filetype_and_ext,
			$file,
			$filename
		);

		// Should NOT override existing type, even for .webp files.
		$this->assertFalse( $result['ext'] );
		$this->assertSame( 'image/jpeg', $result['type'] );
	}

	/**
	 * Verifies that WebP handling does not override existing extension.
	 */
	public function test_handle_webp_filetype_does_not_override_existing_ext(): void {
		$wp_check_filetype_and_ext = array(
			'ext'             => 'jpg', // Extension already set.
			'type'            => false,
			'proper_filename' => false,
		);

		$filename = 'test.webp';
		$file     = '/tmp/test.webp';

		$result = $this->importer->handle_webp_filetype(
			$wp_check_filetype_and_ext,
			$file,
			$filename
		);

		// Should NOT override existing extension, even for .webp files.
		$this->assertSame( 'jpg', $result['ext'] );
		$this->assertFalse( $result['type'] );
	}

	/**
	 * Verifies that proper_filename field is preserved during WebP handling.
	 */
	public function test_handle_webp_filetype_preserves_proper_filename(): void {
		$wp_check_filetype_and_ext = array(
			'ext'             => false,
			'type'            => false,
			'proper_filename' => 'sanitized-name.webp',
		);

		$filename = 'test.webp';
		$file     = '/tmp/test.webp';

		$result = $this->importer->handle_webp_filetype(
			$wp_check_filetype_and_ext,
			$file,
			$filename
		);

		// Should preserve proper_filename without modification.
		$this->assertSame( 'sanitized-name.webp', $result['proper_filename'] );
	}

	/**
	 * Verifies that get_attachment_id_from_url returns an integer.
	 */
	public function test_get_attachment_id_from_url_returns_int(): void {
		$url    = 'https://example.com/wp-content/uploads/2024/01/image.jpg';
		$result = $this->importer->get_attachment_id_from_url( $url );

		$this->assertIsInt( $result );
	}

	/**
	 * Verifies that import_featured_image returns false for empty media IDs.
	 */
	public function test_import_featured_image_with_empty_id_returns_false(): void {
		$result = $this->importer->import_featured_image( 0, 'https://example.com' );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that import_featured_image returns false for empty site URLs.
	 */
	public function test_import_featured_image_with_empty_site_url_returns_false(): void {
		$result = $this->importer->import_featured_image( 123, '' );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that reapply_query_parameters returns the clean URL when no
	 * query parameters are present.
	 */
	public function test_reapply_query_parameters_without_parameters_returns_clean_url(): void {
		$original_url = 'https://source.example.com/uploads/photo.jpg';
		$clean_url    = 'https://target.example.com/wp-content/uploads/photo.jpg';

		$result = Media_Importer::reapply_query_parameters( $original_url, $clean_url );

		$this->assertSame( $clean_url, $result );
	}

	/**
	 * Verifies that reapply_query_parameters reapplies query parameters from
	 * the original URL onto the clean URL.
	 */
	public function test_reapply_query_parameters_with_parameters_reapplies_them_to_clean_url(): void {
		$original_url = 'https://source.example.com/uploads/photo.jpg?w=1200&h=600&crop=1';
		$clean_url    = 'https://target.example.com/wp-content/uploads/photo.jpg';

		$result = Media_Importer::reapply_query_parameters( $original_url, $clean_url );

		$this->assertStringContainsString( 'w=1200', $result );
		$this->assertStringContainsString( 'h=600', $result );
		$this->assertStringContainsString( 'crop=1', $result );
		$this->assertStringStartsWith( $clean_url . '?', $result );
	}

	/**
	 * Verifies that reapply_query_parameters preserves per-occurrence
	 * parameters independently.
	 *
	 * The same base image may appear at different sizes in the same post.
	 * Each occurrence should restore its own parameters onto the same clean URL.
	 */
	public function test_reapply_query_parameters_different_parameters_same_clean_url(): void {
		$clean_url      = 'https://target.example.com/wp-content/uploads/photo.jpg';
		$original_small = 'https://source.example.com/uploads/photo.jpg?w=400&h=300';
		$original_large = 'https://source.example.com/uploads/photo.jpg?w=1200&h=800';

		$url_small = Media_Importer::reapply_query_parameters( $original_small, $clean_url );
		$url_large = Media_Importer::reapply_query_parameters( $original_large, $clean_url );

		$this->assertStringContainsString( 'w=400', $url_small );
		$this->assertStringContainsString( 'w=1200', $url_large );
		$this->assertNotSame( $url_small, $url_large );
	}
}
