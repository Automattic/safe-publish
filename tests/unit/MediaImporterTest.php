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
 * Tests the WebP upload logic, the ownership guards, and the reserved-range
 * media host guard in the Media_Importer class.
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
		reset_test_http_response();
		reset_test_filters();
		reset_test_actions();
		reset_test_home_url();
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
	 * Builds the source media record that a source ID resolves through.
	 *
	 * @param string $source_url source_url the mocked record serves.
	 * @return array<string, mixed> Stub HTTP response.
	 */
	private static function media_record_response( string $source_url ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'         => 991,
					'source_url' => $source_url,
				)
			),
		);
	}

	/**
	 * Hosts in an address range the media guard refuses, in the forms a host
	 * survives wp_parse_url() in. Every literal is drawn from a reserved
	 * range, never from a live service on one.
	 *
	 * @return array<string, array{host: string}>
	 */
	public static function reserved_media_host_provider(): array {
		return array(
			'link-local'         => array( 'host' => '169.254.1.1' ),
			'carrier-grade NAT'  => array( 'host' => '100.100.100.200' ),
			'loopback literal'   => array( 'host' => '127.0.0.1' ),
			'RFC1918'            => array( 'host' => '10.1.2.3' ),
			'IPv6 unique-local'  => array( 'host' => '[fd00::1]' ),
			'IPv4-mapped IPv6'   => array( 'host' => '[::ffff:169.254.1.1]' ),
			'root dot'           => array( 'host' => '169.254.1.1.' ),
			'leading space'      => array( 'host' => ' 169.254.1.1' ),
			'trailing space'     => array( 'host' => '169.254.1.1 ' ),
			'name that resolves' => array( 'host' => 'localhost' ),
		);
	}

	/**
	 * Verifies that a source media record pointing into a reserved address
	 * range is not fetched, whatever form its host takes.
	 *
	 * @dataProvider reserved_media_host_provider
	 *
	 * @param string $host Host the mocked source_url is served from.
	 */
	public function test_import_source_media_by_id_refuses_reserved_host(
		string $host
	): void {
		// ARRANGE: A record on that host, a source site and a destination
		// elsewhere, and a deduplication hit that would otherwise return an
		// attachment without any download.
		set_test_home_url( 'https://destination.example.com' );
		set_test_http_response(
			self::media_record_response( 'https://' . $host . '/photo-123.jpg' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );

		// ACT: Resolve the ID the featured and shortcode paths both use.
		$result = $this->importer->import_source_media_by_id(
			991,
			'https://source.example.com'
		);

		// ASSERT: Refused before the deduplication lookup, and refused
		// distinctly from the null a dangling record returns.
		$this->assertFalse( $result );
	}

	/**
	 * Verifies that a refused media host is recorded in the audit trail.
	 */
	public function test_import_source_media_by_id_records_a_refused_host(): void {
		// ARRANGE: The same reserved-range record, with a deduplication hit
		// standing in for the download that must not happen.
		set_test_home_url( 'https://destination.example.com' );
		set_test_http_response(
			self::media_record_response( 'https://169.254.1.1/photo-123.jpg' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );
		reset_test_actions();

		// ACT: Resolve the ID.
		$this->importer->import_source_media_by_id(
			991,
			'https://source.example.com'
		);

		// ASSERT: One media-channel MEDIA_HOST_NOT_ALLOWED event carrying the
		// refused URL and the source it came from.
		$events = array_values(
			array_filter(
				get_test_actions(),
				static fn( array $action ): bool =>
					'safe_publish_event_logged' === $action['hook']
					&& 'MEDIA_HOST_NOT_ALLOWED' === $action['args'][1]
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( 'media', $events[0]['args'][0] );
		$this->assertSame(
			'https://169.254.1.1/photo-123.jpg',
			$events[0]['args'][2]['url']
		);
		$this->assertSame(
			'https://source.example.com',
			$events[0]['args'][2]['source_site_url']
		);
	}

	/**
	 * Verifies that a source media record whose source_url carries no host is
	 * refused rather than handed to the downloader.
	 */
	public function test_import_source_media_by_id_refuses_a_url_with_no_host(): void {
		// ARRANGE: A record serving a data URI, plus the deduplication hit.
		set_test_home_url( 'https://destination.example.com' );
		set_test_http_response(
			self::media_record_response( 'data:image/png;base64,iVBORw0KGgo=' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );

		// ACT: Resolve the ID.
		$result = $this->importer->import_source_media_by_id(
			991,
			'https://source.example.com'
		);

		// ASSERT: A URL with no host to judge is refused.
		$this->assertFalse( $result );
	}

	/**
	 * Hosts outside every range the guard refuses, including the addresses on
	 * either side of carrier-grade NAT space and a routable IPv6 literal.
	 *
	 * @return array<string, array{host: string}>
	 */
	public static function fetchable_media_host_provider(): array {
		return array(
			'documentation literal'   => array( 'host' => '203.0.113.10' ),
			'unresolvable name'       => array( 'host' => 'cdn.example.net' ),
			'below carrier-grade NAT' => array( 'host' => '100.63.255.255' ),
			'above carrier-grade NAT' => array( 'host' => '100.128.0.1' ),
			'routable IPv6'           => array( 'host' => '[2001:db8::1]' ),
		);
	}

	/**
	 * Verifies that a source media record on a routable host still resolves to
	 * an attachment through the same entry point.
	 *
	 * @dataProvider fetchable_media_host_provider
	 *
	 * @param string $host Host the mocked source_url is served from.
	 */
	public function test_import_source_media_by_id_allows_routable_host(
		string $host
	): void {
		// ARRANGE: The same deduplication hit, behind a routable host.
		set_test_home_url( 'https://destination.example.com' );
		set_test_http_response(
			self::media_record_response( 'https://' . $host . '/photo-123.jpg' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );

		// ACT: Resolve the ID the featured and shortcode paths both use.
		$result = $this->importer->import_source_media_by_id(
			991,
			'https://source.example.com'
		);

		// ASSERT: The host passes and the deduplicated attachment comes back.
		$this->assertSame( 4242, $result );
	}

	/**
	 * Verifies that media served from this site's own host is fetchable even
	 * though it resolves into a reserved range, and that the host comparison
	 * ignores case the way DNS does.
	 */
	public function test_import_source_media_by_id_allows_this_sites_own_host(): void {
		// ARRANGE: A destination on localhost and a record naming that host in
		// upper case, with a deduplication hit standing in for the download.
		set_test_home_url( 'http://localhost' );
		set_test_http_response(
			self::media_record_response( 'http://LOCALHOST/photo-123.jpg' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );

		// ACT: Resolve the ID.
		$result = $this->importer->import_source_media_by_id(
			991,
			'https://source.example.com'
		);

		// ASSERT: This site's own host is exempt, as it is in core's
		// wp_http_validate_url().
		$this->assertSame( 4242, $result );
	}

	/**
	 * Verifies that media served from the connected source site's own host is
	 * fetchable even when that host is a private address, so a migration over
	 * a private network keeps working.
	 */
	public function test_import_source_media_by_id_allows_the_source_sites_host(): void {
		// ARRANGE: A source site on a private address serving its own media,
		// with a deduplication hit standing in for the download.
		set_test_home_url( 'https://destination.example.com' );
		set_test_http_response(
			self::media_record_response( 'http://10.1.2.3/photo-123.jpg' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );

		// ACT: Resolve the ID against that source site.
		$result = $this->importer->import_source_media_by_id(
			991,
			'http://10.1.2.3'
		);

		// ASSERT: The configured source host is exempt.
		$this->assertSame( 4242, $result );
	}

	/**
	 * Verifies that http_request_host_is_external opts a third host in a
	 * reserved range back in, which is the documented escape hatch and the one
	 * the local development mu-plugin uses.
	 */
	public function test_import_source_media_by_id_allows_a_host_opted_in_by_filter(): void {
		// ARRANGE: A reserved-range record on neither this site's host nor the
		// source's, with a filter opting that one host back in.
		set_test_home_url( 'https://destination.example.com' );
		set_test_http_response(
			self::media_record_response( 'https://169.254.1.1/photo-123.jpg' )
		);
		set_test_get_posts_result( array( (object) array( 'ID' => 4242 ) ) );
		set_test_filter(
			'http_request_host_is_external',
			static fn( bool $external, string $host ): bool =>
				'169.254.1.1' === $host ? true : $external
		);

		// ACT: Resolve the ID.
		$result = $this->importer->import_source_media_by_id(
			991,
			'https://source.example.com'
		);

		// ASSERT: The opted-in host is fetched and deduplicates as usual.
		$this->assertSame( 4242, $result );
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
