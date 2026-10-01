<?php
/**
 * Integration tests for relative media URLs in block content
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
 * Exercises block content whose media is referenced without a scheme. A
 * browser resolves those against the page the source renders, so the
 * import must reach that target rather than leave an unusable reference.
 *
 * Registers its own pre_http_request filter ahead of the shared fixture mock,
 * which answers any URL whose path ends in an image extension — including a
 * wrongly resolved one, turning every case here green regardless of the fix.
 */
class Content_Processor_Relative_Media_Url_Test extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;

	private const SOURCE         = 'https://source.example.com';
	private const SUBSITE        = 'https://source.example.com/blog';
	private const THIRD_PARTY    = '//cdn.other.example/x.jpg';
	private const SUBSITE_UPLOAD = '/wp-content/uploads/sites/2/s1.jpg';

	/**
	 * The only URLs the strict mock serves fixture bytes for.
	 *
	 * @var list<string>
	 */
	private const SERVED = array(
		self::SOURCE . '/g1.jpg',
		self::SOURCE . '/wp-content/uploads/2024/01/r1.jpg',
		self::SOURCE . '/cover.jpg',
		self::SOURCE . '/p1.jpg',
		self::SOURCE . '/p2.jpg',
		self::SOURCE . self::SUBSITE_UPLOAD,
		self::SUBSITE . '/bare.jpg',
		self::SOURCE . '/combo.jpg',
	);

	/**
	 * Full pipeline under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * URLs the pipeline asked for.
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

		$media_importer  = new Media_Importer( new HTTP_Client() );
		$this->processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
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
	 * wrongly resolved value cannot be answered by the fixture mock behind this.
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

		$this->requested[] = $url;

		if ( in_array( $url, self::SERVED, true ) ) {
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
	 * Verifies that a protocol-relative image inside a block imports even
	 * though the content holds no http substring to trigger the media pass.
	 */
	public function test_protocol_relative_block_image_imports(): void {
		// ARRANGE: A gallery image that is protocol-relative, no "http" at all.
		$html = '<!-- wp:gallery --><figure class="wp-block-gallery">'
			. '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="//source.example.com/g1.jpg" alt=""/>'
			. '</figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
		$this->assertStringNotContainsString( 'http', $html );
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The scheme-less reference resolved to the source and imported.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertSame( array( self::SOURCE . '/g1.jpg' ), $this->requested );
		$this->assertStringNotContainsString( '//source.example.com', $result );
		$this->assertNull( $this->processor->get_failed_media_error_message() );
		$this->assertNull( $this->processor->get_unprocessable_media_error_message() );
	}

	/**
	 * Verifies that a root-relative image inside a block imports, covering the
	 * form that carries neither an http substring nor a double slash.
	 */
	public function test_root_relative_block_image_imports(): void {
		// ARRANGE: An image block whose src is root-relative only.
		$html = '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="/wp-content/uploads/2024/01/r1.jpg" alt=""/>'
			. '</figure><!-- /wp:image -->';
		$this->assertStringNotContainsString( 'http', $html );
		$this->assertStringNotContainsString( '//', $html );
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The path resolved against the source host and imported.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertSame(
			array( self::SOURCE . '/wp-content/uploads/2024/01/r1.jpg' ),
			$this->requested
		);
		$this->assertStringContainsString( wp_upload_dir()['baseurl'], $result );
		$this->assertStringNotContainsString( 'src="/wp-content', $result );
	}

	/**
	 * Verifies that a bare relative image inside a block imports, resolving
	 * against the source address in full rather than its host alone.
	 */
	public function test_bare_relative_block_image_imports(): void {
		// ARRANGE: An image block whose src carries no leading slash.
		$html   = '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="bare.jpg" alt=""/>'
			. '</figure><!-- /wp:image -->';
		$before = $this->get_attachment_count();

		// ACT: Run the pipeline against a source address bearing a path.
		$this->processor->process_content( $html, self::SUBSITE );

		// ASSERT: The base path was kept, unlike the root-relative form.
		$this->assertSame(
			array( self::SUBSITE . '/bare.jpg' ),
			$this->requested
		);
		$this->assertSame( $before + 1, $this->get_attachment_count() );
	}

	/**
	 * Verifies that a protocol-relative URL held only in a block attribute
	 * imports, with no markup attribute to trigger the pass.
	 */
	public function test_protocol_relative_block_attr_media_imports(): void {
		// ARRANGE: A cover block whose media lives in its attributes alone.
		$html = '<!-- wp:cover {"url":"//source.example.com/cover.jpg"} -->'
			. '<div class="wp-block-cover"></div>'
			. '<!-- /wp:cover -->';
		$this->assertStringNotContainsString( 'http', $html );
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The attribute now names a destination attachment.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertSame( array( self::SOURCE . '/cover.jpg' ), $this->requested );
		$this->assertStringNotContainsString( '//source.example.com', $result );
		$this->assertStringContainsString( wp_upload_dir()['baseurl'], $result );
	}

	/**
	 * Verifies that the same protocol-relative markup imports whether it sits
	 * inside a block or in classic content, which is the gap the gate left.
	 */
	public function test_block_and_classic_import_identically(): void {
		// ARRANGE: The same figure as a block and as classic content, each with
		// its own file so dedup cannot mask a skipped import.
		$block   = '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="//source.example.com/p1.jpg" alt=""/>'
			. '</figure><!-- /wp:image -->';
		$classic = '<figure class="wp-block-image">'
			. '<img src="//source.example.com/p2.jpg" alt=""/>'
			. '</figure>';

		// ACT: Run both through the full pipeline.
		$block_result   = (string) $this->processor->process_content(
			$block,
			self::SOURCE
		);
		$classic_result = (string) $this->processor->process_content(
			$classic,
			self::SOURCE
		);

		// ASSERT: Both point at the destination, neither keeps the source.
		$uploads = wp_upload_dir()['baseurl'];
		$this->assertStringContainsString( $uploads, $block_result );
		$this->assertStringContainsString( $uploads, $classic_result );
		$this->assertStringNotContainsString( '//source.example.com', $block_result );
		$this->assertStringNotContainsString( '//source.example.com', $classic_result );
	}

	/**
	 * Verifies that protocol-relative media on another host is left alone, so
	 * the media pass does not sideload third-party files or report a failure.
	 */
	public function test_protocol_relative_third_party_media_untouched(): void {
		// ARRANGE: A block image hosted somewhere other than the source.
		$html   = '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="' . self::THIRD_PARTY . '" alt=""/>'
			. '</figure><!-- /wp:image -->';
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: Nothing fetched or imported, and the src stands untouched.
		$this->assertSame( array(), $this->requested );
		$this->assertSame( $before, $this->get_attachment_count() );
		$this->assertStringContainsString( self::THIRD_PARTY, $result );
		$this->assertNull( $this->processor->get_failed_media_error_message() );
		$this->assertNull( $this->processor->get_unprocessable_media_error_message() );
	}

	/**
	 * Verifies that block content the media pass does not touch comes back byte
	 * for byte, so escaped attributes survive the import unchanged.
	 */
	public function test_untouched_block_content_is_returned_verbatim(): void {
		// ARRANGE: Prose whose block attributes carry escapes Core rewrites on
		// re-serializing, as plain json_encode() writes them, plus a relative
		// anchor that is not a media reference.
		$html = '<!-- wp:paragraph '
			. '{"metadata":{"name":"Caf\u00e9 \"notes\""}} -->' . "\n"
			. '<p>See <a href="/about">about</a>: namespace App\Models; '
			. '$re = "\d+";</p>' . "\n"
			. '<!-- /wp:paragraph -->';
		$this->assertSame(
			'Café "notes"',
			parse_blocks( $html )[0]['attrs']['metadata']['name']
		);
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: Nothing was fetched and the bytes are unchanged.
		$this->assertSame( $before, $this->get_attachment_count() );
		$this->assertSame( array(), $this->requested );
		$this->assertSame( $html, $result );
	}

	/**
	 * Verifies that root-relative media on a source whose address carries a
	 * path resolves against the host root. A subsite serves its uploads from
	 * the host root, so prefixing the subsite path would miss them.
	 */
	public function test_root_relative_resolves_against_host_root(): void {
		// ARRANGE: A subsite uploads path, as a subsite source would store it.
		$html   = '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="' . self::SUBSITE_UPLOAD . '" alt=""/>'
			. '</figure><!-- /wp:image -->';
		$before = $this->get_attachment_count();

		// ACT: Run the pipeline against the path-bearing source address.
		$this->processor->process_content( $html, self::SUBSITE );

		// ASSERT: The base path was dropped rather than prefixed twice.
		$this->assertSame(
			array( self::SOURCE . self::SUBSITE_UPLOAD ),
			$this->requested
		);
		$this->assertSame( $before + 1, $this->get_attachment_count() );
	}

	/**
	 * Verifies that relative media imports beside an ID-reference block whose
	 * ID is remapped in the same run. Such a block used to be the only reason
	 * http-free content was processed at all, and it drove the ID remap rather
	 * than the media pass.
	 */
	public function test_relative_media_imports_beside_id_reference_block(): void {
		// ARRANGE: A navigation link mapped to a destination post, and a
		// protocol-relative image, no "http".
		$dest_post = self::factory()->post->create();
		$source_id = 99001; // Clear of auto-incremented post IDs.
		$html      = '<!-- wp:navigation-link {"id":' . $source_id
			. ',"kind":"post-type"} --><!-- /wp:navigation-link -->'
			. '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="//source.example.com/combo.jpg" alt=""/>'
			. '</figure><!-- /wp:image -->';
		$this->assertStringNotContainsString( 'http', $html );
		$before = $this->get_attachment_count();

		// ACT: Run the full pipeline with the link's mapping in the session.
		$result = (string) $this->processor->process_content(
			$html,
			self::SOURCE,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: The image imported and the link ID was remapped.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$this->assertSame( array( self::SOURCE . '/combo.jpg' ), $this->requested );
		$this->assertStringNotContainsString( '//source.example.com', $result );
		$this->assertSame(
			$dest_post,
			parse_blocks( $result )[0]['attrs']['id']
		);
	}

	/**
	 * Verifies that a relative reference the source cannot serve is reported
	 * rather than left in place as a silently broken link.
	 */
	public function test_unreachable_relative_media_is_reported(): void {
		// ARRANGE: A protocol-relative image the strict mock 404s.
		$html = '<!-- wp:image --><figure class="wp-block-image">'
			. '<img src="//source.example.com/gone.jpg" alt=""/>'
			. '</figure><!-- /wp:image -->';

		// ACT: Run the full pipeline.
		$this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The failure surfaces instead of the import reporting success.
		$this->assertStringContainsString(
			'gone.jpg',
			(string) $this->processor->get_failed_media_error_message()
		);
	}

	/**
	 * Verifies that an escaped absolute source URL in a block attribute still
	 * gets its host swapped when the media pass changes nothing, since the
	 * swap reads the unescaped slashes that re-serializing the block writes.
	 */
	public function test_escaped_attr_url_still_gets_host_swapped(): void {
		// ARRANGE: A non-media source URL held as an escaped block attribute,
		// which leaves the media pass with nothing to rewrite.
		$html = '<!-- wp:button {"url":"https:\/\/source.example.com\/about"} -->'
			. '<div class="wp-block-button"></div>'
			. '<!-- /wp:button -->';

		// ACT: Run the full pipeline.
		$result = (string) $this->processor->process_content( $html, self::SOURCE );

		// ASSERT: The destination host replaced the source host.
		$this->assertStringNotContainsString( 'source.example.com', $result );
		$this->assertStringContainsString( home_url( '/about' ), $result );
	}
}
