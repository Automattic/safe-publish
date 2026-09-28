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
	 * Resets the get_posts stub between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		reset_test_get_posts_result();
		parent::tearDown();
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
	 * Verifies that the WebP filetype callback stays removed.
	 *
	 * It re-asserted a type after core had already compared the downloaded
	 * bytes with the filename extension, so reintroducing it would override
	 * that check again.
	 */
	public function test_webp_filetype_callback_is_not_reintroduced(): void {
		// ARRANGE: The importer under test.
		$importer = $this->importer;

		// ACT: Look for the removed callback.
		$exists = method_exists( $importer, 'handle_webp_filetype' );

		// ASSERT: It is gone, and the supported override is still present.
		$this->assertFalse( $exists );
		$this->assertTrue( method_exists( $importer, 'add_webp_mime_type' ) );
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
	 * Verifies that import_featured_image returns false for empty source site URLs.
	 */
	public function test_import_featured_image_with_empty_source_site_url_returns_false(): void {
		$result = $this->importer->import_featured_image( 123, '' );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that import_source_media returns null for a URL whose domain
	 * does not match the source site, without recording a failure.
	 */
	public function test_import_source_media_returns_null_for_third_party_domain(): void {
		// ARRANGE: Media on a CDN that is unrelated to the source site.
		$source_site_url = 'https://source.example.com';
		$media_url       = 'https://third-party.example.com/photo-123.jpg';

		// ACT: Call with a third-party URL.
		$result = $this->importer->import_source_media( $media_url, $source_site_url );

		// ASSERT: null signals "skipped, not our domain" — not a failure.
		$this->assertNull( $result );
	}

	/**
	 * Verifies that import_source_media_as_attachment returns null for a
	 * URL whose domain does not match the source site.
	 */
	public function test_import_source_media_as_attachment_returns_null_for_third_party_domain(): void {
		// ARRANGE: Video file hosted on an unrelated CDN.
		$source_site_url = 'https://source.example.com';
		$media_url       = 'https://third-party.example.com/clip.mp4';

		// ACT: Call with a third-party URL.
		$result = $this->importer->import_source_media_as_attachment( $media_url, $source_site_url );

		// ASSERT: null signals "skipped, not our domain" — not a failure.
		$this->assertNull( $result );
	}

	/**
	 * Verifies that import_owned_media_as_attachment sideloads media whose host
	 * differs from the source site, while import_source_media_as_attachment
	 * still skips it — the featured path is owned by provenance, not host.
	 */
	public function test_import_owned_media_as_attachment_bypasses_third_party_guard(): void {
		// ARRANGE: An off-domain CDN URL, with a stubbed dedup hit so the owned
		// path resolves to an existing attachment without a live download.
		$source_site_url = 'https://source.example.com';
		$media_url       = 'https://cdn.example.net/photo-123.jpg';
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );

		// ACT: Call both entrypoints with the same off-domain URL.
		$owned   = $this->importer->import_owned_media_as_attachment( $media_url, $source_site_url );
		$guarded = $this->importer->import_source_media_as_attachment( $media_url, $source_site_url );

		// ASSERT: The owned path bypasses the guard and returns the deduped
		// attachment; the guarded path still returns null for the third party.
		$this->assertSame( 4242, $owned );
		$this->assertNull( $guarded );
	}

	/**
	 * Verifies that reapply_query_parameters returns the clean URL when no
	 * query parameters are present.
	 */
	public function test_reapply_query_parameters_without_parameters_returns_clean_url(): void {
		$original_url = 'https://source.example.com/uploads/photo.jpg';
		$clean_url    = 'https://destination.example.com/wp-content/uploads/photo.jpg';

		$result = Media_Importer::reapply_query_parameters( $original_url, $clean_url );

		$this->assertSame( $clean_url, $result );
	}

	/**
	 * Verifies that reapply_query_parameters reapplies query parameters from
	 * the original URL onto the clean URL.
	 */
	public function test_reapply_query_parameters_with_parameters_reapplies_them_to_clean_url(): void {
		$original_url = 'https://source.example.com/uploads/photo.jpg?w=1200&h=600&crop=1';
		$clean_url    = 'https://destination.example.com/wp-content/uploads/photo.jpg';

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
		$clean_url      = 'https://destination.example.com/wp-content/uploads/photo.jpg';
		$original_small = 'https://source.example.com/uploads/photo.jpg?w=400&h=300';
		$original_large = 'https://source.example.com/uploads/photo.jpg?w=1200&h=800';

		$url_small = Media_Importer::reapply_query_parameters( $original_small, $clean_url );
		$url_large = Media_Importer::reapply_query_parameters( $original_large, $clean_url );

		$this->assertStringContainsString( 'w=400', $url_small );
		$this->assertStringContainsString( 'w=1200', $url_large );
		$this->assertNotSame( $url_small, $url_large );
	}
}
