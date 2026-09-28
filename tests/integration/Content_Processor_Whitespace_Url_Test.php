<?php
/**
 * Integration tests for whitespace normalization in media URLs
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
use WP_HTML_Tag_Processor;

/**
 * Exercises the import path with media URLs whose attribute value carries the
 * whitespace a URL parser discards. A browser resolves the stripped form, so
 * each case has to reach the same target the source site serves, record the
 * stripped URL as the attachment origin, and report a genuine failure once.
 *
 * Registers its own pre_http_request filter ahead of the shared fixture mock,
 * which answers any URL whose path ends in an image extension — including the
 * doubled URL a misclassified value produces, turning every case here green
 * regardless of the fix.
 */
class Content_Processor_Whitespace_Url_Test extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;

	private const SOURCE = 'https://source.example.com';
	private const IMAGE  = self::SOURCE . '/a.png';

	/**
	 * The only URLs the strict mock serves fixture bytes for.
	 */
	private const SERVED = array( self::IMAGE );

	/**
	 * Full pipeline under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * Media importer, reused to resolve destination attachments from URLs.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * URLs the pipeline asked for, as WP's HTTP layer would send them.
	 *
	 * @var list<string>
	 */
	private array $requested = array();

	/**
	 * Wires the pipeline and layers the strict mock over the fixture mock.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->media_importer = new Media_Importer( new HTTP_Client() );
		$this->processor      = new Content_Processor(
			$this->media_importer,
			new Content_Media_Processor( $this->media_importer ),
			new Shortcode_ID_Rewriter()
		);

		add_filter( 'pre_http_request', array( $this, 'serve_known_urls_only' ), 0, 3 );
		$this->add_image_byte_response_mock();
	}

	/**
	 * Removes the HTTP mocks.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve_known_urls_only' ), 0 );
		$this->remove_image_byte_response_mock();
		parent::tearDown();
	}

	/**
	 * Records the requested URL and 404s anything outside the served list, so a
	 * misclassified value cannot be answered by the fixture mock behind this.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $args    HTTP arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function serve_known_urls_only(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		// WP's HTTP layer trims this set before sending, so record what the
		// remote host would actually see.
		$sent              = trim( $url, "\x20\x09\x0A\x0C\x0D" );
		$this->requested[] = $sent;

		if ( in_array( $sent, self::SERVED, true ) ) {
			return $preempt;
		}

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Verifies that a src with leading whitespace imports to the URL a browser
	 * resolves, rather than being prefixed with the source site address.
	 */
	public function test_leading_whitespace_src_imports_and_rewrites(): void {
		// ARRANGE: An image whose src attribute opens with a space.
		$html   = '<img src=" ' . self::IMAGE . '">';
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );
		$src    = (string) $this->first_src( $result );

		// ASSERT: One attachment, and the src names it with no whitespace left.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertStringNotContainsString( ' ', $src );
		$this->assertSame(
			wp_get_attachment_url(
				$this->media_importer->get_attachment_id_from_url( $src )
			),
			$src
		);

		// ASSERT: Neither error message fires, so the import no longer aborts,
		// and the doubled URL was never requested.
		$this->assertNull( $this->processor->get_failed_media_error_message() );
		$this->assertNull( $this->processor->get_unprocessable_media_error_message() );
		$this->assertSame( array( self::IMAGE ), $this->requested );
	}

	/**
	 * Verifies that a src with trailing whitespace records the stripped URL as
	 * the attachment origin, so dedup against a re-import matches.
	 */
	public function test_trailing_whitespace_records_clean_origin_url(): void {
		// ARRANGE: An image whose src attribute closes with a space.
		$html = '<img src="' . self::IMAGE . ' ">';

		// ACT: Run the full pipeline and resolve the created attachment.
		$result        = (string) $this->processor->process_content( $html, self::SOURCE );
		$attachment_id = $this->media_importer->get_attachment_id_from_url(
			(string) $this->first_src( $result )
		);

		// ASSERT: The origin meta holds the stripped URL.
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame(
			self::IMAGE,
			get_post_meta( $attachment_id, Options::META_ORIGINAL_URL, true )
		);
	}

	/**
	 * Verifies that query parameters are carried onto the destination URL as a
	 * parser would read them, rather than picking up the stray whitespace.
	 */
	public function test_trailing_whitespace_keeps_query_parameters_clean(): void {
		// ARRANGE: A query-bearing src that closes with a space.
		$html = '<img src="' . self::IMAGE . '?size=large ">';

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The query survives intact, with nothing appended.
		$this->assertStringEndsWith(
			'?size=large',
			(string) $this->first_src( $result )
		);
	}

	/**
	 * Verifies that source library metadata still lands on an attachment whose
	 * src carries whitespace, since the map is keyed on the stripped URL.
	 */
	public function test_whitespace_src_still_receives_library_metadata(): void {
		// ARRANGE: A trailing-space src and a metadata map keyed on the clean URL.
		$html    = '<img src="' . self::IMAGE . ' ">';
		$context = array(
			'library_metadata_map' => array(
				self::IMAGE => array(
					'alt'     => 'Source alt text',
					'title'   => 'Source title',
					'caption' => 'Source caption',
				),
			),
		);

		// ACT: Run the full pipeline and resolve the created attachment.
		$result        = (string) $this->processor->process_content(
			$html,
			self::SOURCE,
			$context
		);
		$attachment_id = $this->media_importer->get_attachment_id_from_url(
			(string) $this->first_src( $result )
		);
		$attachment    = get_post( $attachment_id );

		// ASSERT: Alt, title and caption all carried over.
		$this->assertSame(
			'Source alt text',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
		);
		$this->assertNotNull( $attachment );
		$this->assertSame( 'Source title', $attachment->post_title );
		$this->assertSame( 'Source caption', $attachment->post_excerpt );
	}

	/**
	 * Verifies that a custom block attribute with leading whitespace sideloads
	 * instead of being skipped and then pointed at a destination URL that has
	 * no file behind it.
	 */
	public function test_leading_whitespace_custom_block_attr_imports(): void {
		// ARRANGE: A custom block carrying the media URL in an attribute.
		$html   = '<!-- wp:custom/hero {"bgUrl":" ' . self::IMAGE . '"} -->'
			. '<div class="hero"></div>'
			. '<!-- /wp:custom/hero -->';
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The attribute points at a real destination attachment.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertStringNotContainsString( self::IMAGE, $result );
		$this->assertMatchesRegularExpression(
			'~"bgUrl":"[^"]+/wp-content/uploads/[^"]+\.png"~',
			$result
		);
		$this->assertNull( $this->processor->get_failed_media_error_message() );
	}

	/**
	 * Verifies that a src broken across lines, which a URL parser rejoins,
	 * imports rather than aborting on a truncated URL.
	 */
	public function test_internal_whitespace_src_imports(): void {
		// ARRANGE: A src wrapped mid-URL, as hand-edited markup can be.
		$html = '<img src="' . self::SOURCE . "/a\n\t.png\">";

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The rejoined URL was requested and the import does not abort.
		$this->assertSame( array( self::IMAGE ), $this->requested );
		$this->assertNull( $this->processor->get_failed_media_error_message() );
		$this->assertNull( $this->processor->get_unprocessable_media_error_message() );
		$this->assertGreaterThan(
			0,
			$this->media_importer->get_attachment_id_from_url(
				(string) $this->first_src( $result )
			)
		);
	}

	/**
	 * Verifies that a genuine download failure behind a whitespace-bearing src
	 * is reported once, against the stripped URL.
	 */
	public function test_genuine_failure_is_reported_once_and_trimmed(): void {
		// ARRANGE: A leading-space src for a URL the source does not serve.
		$missing = self::SOURCE . '/missing.png';
		$html    = '<img src=" ' . $missing . '">';

		// ACT: Run the full pipeline.
		$this->processor->process_content( $html, self::SOURCE );

		// ASSERT: Keyed on the stripped URL, with no duplicate markup report.
		$this->assertSame(
			array( $missing ),
			array_keys( $this->processor->get_failed_media() )
		);
		$this->assertSame( array(), $this->processor->get_unprocessable_media() );
		$this->assertNull( $this->processor->get_unprocessable_media_error_message() );

		// ASSERT: The message names the URL an operator can act on.
		$this->assertSame(
			'Import failed: 1 media file(s) could not be downloaded: ' . $missing,
			$this->processor->get_failed_media_error_message()
		);
	}

	/**
	 * Verifies that a non-ASCII space is left in place, since a browser keeps it
	 * too and the source site serves a different path than the stripped form.
	 */
	public function test_non_breaking_space_is_not_stripped(): void {
		// ARRANGE: A src prefixed with U+00A0 rather than an ASCII space.
		$html   = '<img src="' . "\u{00A0}" . self::IMAGE . '">';
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$this->processor->process_content( $html, self::SOURCE );

		// ASSERT: Still treated as relative, so it fails rather than importing.
		$this->assertSame( $before, $this->get_attachment_count() );
		$this->assertNotSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Returns the first image's src in processed content, or null when absent.
	 *
	 * @param string $html Processed content.
	 * @return string|null Attribute value, or null.
	 */
	private function first_src( string $html ): ?string {
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			if ( 'IMG' !== $processor->get_tag() ) {
				continue;
			}

			$src = $processor->get_attribute( 'src' );

			return is_string( $src ) ? $src : null;
		}

		return null;
	}
}
