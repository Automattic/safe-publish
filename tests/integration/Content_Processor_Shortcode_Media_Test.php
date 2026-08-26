<?php
/**
 * Integration tests for classic [audio]/[video] shortcode media import.
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
use WP_Error;

/**
 * Exercises shortcode media import through the full Content_Processor pipeline,
 * asserting that URLs inside [audio]/[video] shortcodes are sideloaded and
 * repointed at the destination attachment, that third-party URLs are left as-is,
 * and that download failures are recorded.
 *
 * The 3-argument constructor is used deliberately: Content_Processor defaults the
 * shortcode media rewriter to wrap its injected Media_Importer, so this exercises
 * the real production wiring.
 */
class Content_Processor_Shortcode_Media_Test extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;

	private const SOURCE      = 'https://source.example.com';
	private const MP4_FIXTURE = '/fixtures/media/test-tiny.mp4';

	/**
	 * System under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * Builds a Content_Processor with real dependencies and wires the fixture
	 * HTTP mocks that stand in for live media downloads.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$media_importer  = new Media_Importer( new HTTP_Client() );
		$this->processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		add_filter( 'pre_http_request', array( $this, 'serve_source_fixture' ), 10, 3 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'fix_empty_temp_files' ), 10, 1 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'fill_empty_mp4_temp' ), 10, 1 );
	}

	/**
	 * Removes the HTTP mocks.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve_source_fixture' ), 10 );
		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'fix_empty_temp_files' ), 10 );
		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'fill_empty_mp4_temp' ), 10 );
		parent::tearDown();
	}

	/**
	 * Verifies that an [audio] src URL is sideloaded and localized.
	 */
	public function test_audio_shortcode_src_imported(): void {
		// ARRANGE: Classic content with an [audio] shortcode on the source host.
		$url     = self::SOURCE . '/podcast.jpg';
		$content = '[audio src="' . $url . '"]';
		$before  = $this->get_attachment_count();

		// ACT: Run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: One attachment created; src localized; no failures.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertStringContainsString( 'wp-content/uploads', $result );
		$this->assertStringNotContainsString( $url, $result );
		$this->assertSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that a [video] shortcode has both its src and poster URLs
	 * sideloaded and localized.
	 */
	public function test_video_shortcode_src_and_poster_imported(): void {
		// ARRANGE: A [video] shortcode with a source src and poster.
		$src     = self::SOURCE . '/clip.mp4';
		$poster  = self::SOURCE . '/thumb.jpg';
		$content = '[video src="' . $src . '" poster="' . $poster . '"]';
		$before  = $this->get_attachment_count();

		// ACT: Run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: Two attachments; both URLs localized; no failures.
		$this->assertSame( $before + 2, $this->get_attachment_count() );
		$this->assertStringContainsString( 'wp-content/uploads', $result );
		$this->assertStringNotContainsString( $src, $result );
		$this->assertStringNotContainsString( $poster, $result );
		$this->assertSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that a third-party [video] src is left untouched and imports
	 * nothing.
	 */
	public function test_third_party_video_shortcode_left_untouched(): void {
		// ARRANGE: A [video] whose src is a third-party embed.
		$url     = 'https://www.youtube.com/watch?v=abcd1234';
		$content = '[video src="' . $url . '"]';
		$before  = $this->get_attachment_count();

		// ACT: Run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: Nothing imported; the third-party URL is preserved.
		$this->assertSame( $before, $this->get_attachment_count() );
		$this->assertStringContainsString( $url, $result );
		$this->assertSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that a failed shortcode media download is recorded as a failure so
	 * the import aborts rather than persisting a host-swapped dead URL.
	 */
	public function test_failed_shortcode_media_recorded(): void {
		// ARRANGE: An [audio] src forced to fail on download.
		$url     = self::SOURCE . '/broken.jpg';
		$content = '[audio src="' . $url . '"]';

		$fail = static function ( $preempt, array $args, string $requested ) use ( $url ) {
			unset( $args );
			return $requested === $url
				? new WP_Error( 'http_request_failed', 'forced failure for test' )
				: $preempt;
		};
		add_filter( 'pre_http_request', $fail, 5, 3 );
		$before = $this->get_attachment_count();

		try {
			// ACT: Run the full processing pipeline.
			$this->processor->process_content( $content, self::SOURCE );
		} finally {
			remove_filter( 'pre_http_request', $fail, 5 );
		}

		// ASSERT: No attachment; the source URL is a recorded failure.
		$this->assertSame( $before, $this->get_attachment_count() );
		$this->assertArrayHasKey( $url, $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that a shortcode in Gutenberg content is imported by the single
	 * top-level pass, which runs on the serialized block output.
	 */
	public function test_shortcode_in_gutenberg_content_imported(): void {
		// ARRANGE: Block content with a top-level (freeform) [audio] shortcode.
		$url     = self::SOURCE . '/episode.jpg';
		$content = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n"
			. '[audio src="' . $url . '"]';
		$before  = $this->get_attachment_count();

		// ACT: Run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: The shortcode media was imported and localized.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertStringContainsString( 'wp-content/uploads', $result );
		$this->assertStringNotContainsString( $url, $result );
		$this->assertSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Serves fixture bytes for source-host image and mp4 URLs; other URLs fall
	 * through so downloads of them fail rather than hit the network.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $args    HTTP arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function serve_source_fixture(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( false !== $preempt || ! str_contains( $url, 'source.example.com' ) ) {
			return $preempt;
		}

		$extension = strtolower(
			pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION )
		);

		if ( in_array( $extension, array( 'jpg', 'jpeg' ), true ) ) {
			return $this->get_fixture_response( 'test-1x1.jpg', 'image/jpeg' );
		}

		if ( 'mp4' === $extension ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$bytes = (string) file_get_contents( dirname( __DIR__ ) . self::MP4_FIXTURE );
			return array(
				'headers'       => array(
					'content-type'   => 'video/mp4',
					'content-length' => (string) strlen( $bytes ),
				),
				'body'          => $bytes,
				'response'      => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'       => array(),
				'filename'      => null,
				'http_response' => null,
			);
		}

		return $preempt;
	}

	/**
	 * Populates the empty temp file download_url() leaves behind for an mp4
	 * sideload with the fixture's bytes.
	 *
	 * @param array $file File array with 'tmp_name' and 'name' keys.
	 * @return array File array.
	 */
	public function fill_empty_mp4_temp( array $file ): array {
		$temp_path = $file['tmp_name'] ?? '';

		if ( ! file_exists( $temp_path ) || filesize( $temp_path ) > 0 ) {
			return $file;
		}

		if ( 'mp4' !== strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) ) ) {
			return $file;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents(
			$temp_path,
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			(string) file_get_contents( dirname( __DIR__ ) . self::MP4_FIXTURE )
		);
		clearstatcache( true, $temp_path );

		return $file;
	}
}
