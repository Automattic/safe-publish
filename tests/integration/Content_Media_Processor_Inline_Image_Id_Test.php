<?php
/**
 * Integration tests for inline-image attachment-ID reference rewriting.
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
use WP_HTML_Tag_Processor;

/**
 * Exercises Content_Media_Processor's inline-image ID rewriting: After a
 * successful src import, an existing wp-image-{n} class token and data-id
 * attribute are repointed at the destination attachment, restoring core's
 * runtime srcset injection. Covers the classic/inner-HTML path (including a
 * legacy v1 gallery) that process_image_block() never reaches.
 */
class Content_Media_Processor_Inline_Image_Id_Test extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;

	private const SOURCE      = 'https://source.example.com';
	private const THIRD_PARTY = 'https://cdn.example.org';

	/**
	 * System under test.
	 *
	 * @var Content_Media_Processor
	 */
	private Content_Media_Processor $processor;

	/**
	 * Media importer, reused to resolve destination attachment IDs from URLs.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Full pipeline, used to drive the legacy v1 gallery through block routing.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $content_processor;

	/**
	 * Wires the processor with a real importer and the fixture HTTP mocks that
	 * stand in for live image downloads.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->media_importer    = new Media_Importer( new HTTP_Client() );
		$this->processor         = new Content_Media_Processor( $this->media_importer );
		$this->content_processor = new Content_Processor(
			$this->media_importer,
			$this->processor,
			new Shortcode_ID_Rewriter()
		);

		$this->add_image_byte_response_mock();
	}

	/**
	 * Removes the HTTP mocks.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_image_byte_response_mock();
		parent::tearDown();
	}

	/**
	 * Verifies that a stale wp-image-{n} class token is repointed at the
	 * destination attachment while the other class tokens and their order are
	 * preserved.
	 */
	public function test_rewrites_stale_wp_image_class_to_dest_id(): void {
		// ARRANGE: An inline image whose class names the source attachment.
		$html = '<p><img src="' . self::SOURCE . '/photo.jpg"'
			. ' class="alignnone size-large wp-image-900705"/></p>';

		// ACT: Import the src and rewrite the ID references.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$dest_id = $this->dest_id_of( $result );

		// ASSERT: Only the wp-image token changed; the rest is preserved in order.
		$this->assertGreaterThan( 0, $dest_id );
		$this->assertNotSame( 900705, $dest_id );
		$this->assertSame(
			"alignnone size-large wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
	}

	/**
	 * Verifies that a present data-id attribute is repointed at the destination
	 * attachment alongside the class.
	 */
	public function test_rewrites_present_data_id_to_dest_id(): void {
		// ARRANGE: An inline image carrying both a wp-image class and a data-id.
		$html = '<img src="' . self::SOURCE . '/photo.jpg"'
			. ' data-id="900705" class="wp-image-900705"/>';

		// ACT: Import the src and rewrite the ID references.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$dest_id = $this->dest_id_of( $result );

		// ASSERT: Both the class and the data-id point at the destination.
		$this->assertGreaterThan( 0, $dest_id );
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
		$this->assertSame(
			(string) $dest_id,
			$this->first_img_attr( $result, 'data-id' )
		);
	}

	/**
	 * Verifies that an image without a data-id has only its class rewritten and
	 * that no data-id is fabricated.
	 */
	public function test_absent_data_id_is_not_fabricated(): void {
		// ARRANGE: An inline image with a wp-image class but no data-id.
		$html = '<img src="' . self::SOURCE . '/photo.jpg" class="wp-image-900705"/>';

		// ACT: Import the src and rewrite the ID references.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$dest_id = $this->dest_id_of( $result );

		// ASSERT: The class is repointed and no data-id was added.
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
		$this->assertStringNotContainsString( 'data-id', $result );
	}

	/**
	 * Verifies that a data-id which does not name the class's source attachment
	 * (e.g. a slider index) is left intact, so only a genuine attachment
	 * reference is rewritten.
	 */
	public function test_data_id_not_matching_class_is_left_intact(): void {
		// ARRANGE: The class names attachment 900705, but data-id is unrelated.
		$html = '<img src="' . self::SOURCE . '/photo.jpg"'
			. ' data-id="2" class="wp-image-900705"/>';

		// ACT: Import the src and rewrite the ID references.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$dest_id = $this->dest_id_of( $result );

		// ASSERT: The class is repointed, but the unrelated data-id is preserved.
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
		$this->assertSame( '2', $this->first_img_attr( $result, 'data-id' ) );
	}

	/**
	 * Verifies that a third-party image (which the importer leaves as null) keeps
	 * its class and data-id untouched and creates no attachment.
	 */
	public function test_third_party_src_leaves_id_refs_untouched(): void {
		// ARRANGE: An inline image served from a third-party domain.
		$html   = '<img src="' . self::THIRD_PARTY . '/photo.jpg"'
			. ' data-id="900705" class="wp-image-900705"/>';
		$before = $this->get_attachment_count();

		// ACT: Run the processor.
		$result = $this->processor->process_content( $html, self::SOURCE, '' );

		// ASSERT: Nothing imported and the stale references are preserved.
		$this->assertSame( $before, $this->get_attachment_count() );
		$this->assertSame( 'wp-image-900705', $this->first_img_attr( $result, 'class' ) );
		$this->assertSame( '900705', $this->first_img_attr( $result, 'data-id' ) );
	}

	/**
	 * Verifies that a failed download (which the importer reports as false) leaves
	 * the class and data-id untouched and records the source URL as a failure.
	 */
	public function test_failed_import_leaves_id_refs_untouched(): void {
		// ARRANGE: An inline image whose download is forced to fail.
		$broken = self::SOURCE . '/broken.jpg';
		$html   = '<img src="' . $broken . '" data-id="900705" class="wp-image-900705"/>';

		$fail = static function ( $preempt, array $args, string $url ) use ( $broken ) {
			unset( $args );
			return $url === $broken
				? new WP_Error( 'http_request_failed', 'forced failure for test' )
				: $preempt;
		};
		add_filter( 'pre_http_request', $fail, 0, 3 );

		try {
			// ACT: Run the processor.
			$result = $this->processor->process_content( $html, self::SOURCE, '' );
		} finally {
			remove_filter( 'pre_http_request', $fail, 0 );
		}

		// ASSERT: The references are preserved and the URL is a recorded failure.
		$this->assertSame( 'wp-image-900705', $this->first_img_attr( $result, 'class' ) );
		$this->assertSame( '900705', $this->first_img_attr( $result, 'data-id' ) );
		$this->assertArrayHasKey( $broken, $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that an already-local src (which the importer skips as null) leaves
	 * the class untouched, so an image already fixed by process_image_block() is
	 * not double-processed.
	 */
	public function test_already_local_src_leaves_class_untouched(): void {
		// ARRANGE: Import a source image, then reference its now-local URL from a
		// second image still carrying a stale class — the shape a core/image
		// already fixed by process_image_block() presents to the classic pass.
		$seed      = $this->processor->process_content(
			'<img src="' . self::SOURCE . '/seed.jpg" class="wp-image-900705"/>',
			self::SOURCE,
			''
		);
		$local_src = (string) $this->first_img_attr( $seed, 'src' );
		$local_id  = $this->media_importer->get_attachment_id_from_url( $local_src );
		$html      = '<img src="' . $local_src . '" class="wp-image-900705"/>';

		// ACT: Run the processor over the already-local image.
		$result = $this->processor->process_content( $html, self::SOURCE, '' );

		// ASSERT: The src resolves to a real attachment, yet the stale class is
		// left untouched because the importer skips an already-local src.
		$this->assertGreaterThan( 0, $local_id );
		$this->assertSame( 'wp-image-900705', $this->first_img_attr( $result, 'class' ) );
	}

	/**
	 * Verifies that an image with no wp-image class gets none fabricated, even
	 * after its src is imported.
	 */
	public function test_missing_wp_image_class_is_not_fabricated(): void {
		// ARRANGE: An inline image with no class attribute at all.
		$html = '<img src="' . self::SOURCE . '/photo.jpg" alt="Seeded"/>';

		// ACT: Import the src and rewrite the ID references.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$dest_id = $this->dest_id_of( $result );

		// ASSERT: The src imported but no class or wp-image token was introduced.
		$this->assertGreaterThan( 0, $dest_id );
		$this->assertStringNotContainsString( 'wp-image-', $result );
		$this->assertStringNotContainsString( 'class=', $result );
	}

	/**
	 * Verifies that several inline images in one blob are each repointed at their
	 * own destination attachment.
	 */
	public function test_multiple_images_are_each_mapped_independently(): void {
		// ARRANGE: Two inline images with distinct sources and stale classes.
		$html = '<img src="' . self::SOURCE . '/a.jpg" class="wp-image-900705"/>'
			. '<img src="' . self::SOURCE . '/b.jpg" class="wp-image-900706"/>';

		// ACT: Import both and rewrite their ID references.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$srcs    = $this->img_attrs( $result, 'src' );
		$classes = $this->img_attrs( $result, 'class' );

		// ASSERT: Each class names the attachment resolved from its own src, and
		// the two resolve to different attachments.
		$dest_a = $this->media_importer->get_attachment_id_from_url( (string) $srcs[0] );
		$dest_b = $this->media_importer->get_attachment_id_from_url( (string) $srcs[1] );
		$this->assertGreaterThan( 0, $dest_a );
		$this->assertGreaterThan( 0, $dest_b );
		$this->assertNotSame( $dest_a, $dest_b );
		$this->assertSame( "wp-image-{$dest_a}", $classes[0] );
		$this->assertSame( "wp-image-{$dest_b}", $classes[1] );
	}

	/**
	 * Verifies that a repeated source image, whose second occurrence is a
	 * deduplication hit, still has its class repointed, and that only one
	 * attachment is created.
	 */
	public function test_deduplicated_image_still_repointed(): void {
		// ARRANGE: The same source image referenced twice, each with a stale class.
		$html   = '<img src="' . self::SOURCE . '/dup.jpg" class="wp-image-900705"/>'
			. '<img src="' . self::SOURCE . '/dup.jpg" class="wp-image-900705"/>';
		$before = $this->get_attachment_count();

		// ACT: The first occurrence sideloads, the second dedups; rewrite both.
		$result  = $this->processor->process_content( $html, self::SOURCE, '' );
		$srcs    = $this->img_attrs( $result, 'src' );
		$classes = $this->img_attrs( $result, 'class' );

		// ASSERT: One attachment created, and both classes name it.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$dest_id = $this->media_importer->get_attachment_id_from_url( (string) $srcs[0] );
		$this->assertGreaterThan( 0, $dest_id );
		$this->assertSame( "wp-image-{$dest_id}", $classes[0] );
		$this->assertSame( "wp-image-{$dest_id}", $classes[1] );
	}

	/**
	 * Verifies that a legacy v1 gallery, routed through the full block pipeline,
	 * has each inner image's class and data-id repointed, while the vestigial
	 * parent attrs.ids is left untouched (out of scope).
	 */
	public function test_legacy_v1_gallery_inner_images_repointed(): void {
		// ARRANGE: A pre-5.9 gallery block whose images live in its innerHTML,
		// carrying data-id and wp-image classes, with no inner image blocks.
		$gallery = '<!-- wp:gallery {"ids":[900705,900706],"linkTo":"none"} -->' . "\n"
			. '<figure class="wp-block-gallery columns-2 is-cropped">'
			. '<ul class="blocks-gallery-grid">'
			. '<li class="blocks-gallery-item"><figure><img'
			. ' src="' . self::SOURCE . '/a.jpg" alt="" data-id="900705"'
			. ' class="wp-image-900705"/></figure></li>'
			. '<li class="blocks-gallery-item"><figure><img'
			. ' src="' . self::SOURCE . '/b.jpg" alt="" data-id="900706"'
			. ' class="wp-image-900706"/></figure></li>'
			. '</ul></figure>' . "\n"
			. '<!-- /wp:gallery -->';

		// ACT: Run the full processing pipeline.
		$result  = (string) $this->content_processor->process_content(
			$gallery,
			self::SOURCE
		);
		$srcs    = $this->img_attrs( $result, 'src' );
		$classes = $this->img_attrs( $result, 'class' );
		$data    = $this->img_attrs( $result, 'data-id' );

		// ASSERT: Both inner images are repointed at their destination.
		$dest_a = $this->media_importer->get_attachment_id_from_url( (string) $srcs[0] );
		$dest_b = $this->media_importer->get_attachment_id_from_url( (string) $srcs[1] );
		$this->assertGreaterThan( 0, $dest_a );
		$this->assertNotSame( $dest_a, $dest_b );
		$this->assertSame( "wp-image-{$dest_a}", $classes[0] );
		$this->assertSame( "wp-image-{$dest_b}", $classes[1] );
		$this->assertSame( (string) $dest_a, $data[0] );
		$this->assertSame( (string) $dest_b, $data[1] );

		// ASSERT: The parent gallery ids attr is left as-is.
		$this->assertStringContainsString( '"ids":[900705,900706]', $result );
	}

	/**
	 * Verifies the end-to-end payoff: Once the class is repointed, core's
	 * wp_filter_content_tags() injects a srcset referencing the destination
	 * attachment's sizes, whereas the same src with a stale class gets none.
	 */
	public function test_repointed_class_restores_runtime_srcset(): void {
		// ARRANGE: Import an inline image, then give its destination attachment
		// synthetic sub-sizes so core has srcset candidates to offer.
		$html     = '<img src="' . self::SOURCE . '/photo.jpg" class="wp-image-900888"/>';
		$result   = $this->processor->process_content( $html, self::SOURCE, '' );
		$dest_id  = $this->dest_id_of( $result );
		$dest_src = (string) $this->first_img_attr( $result, 'src' );
		$stem     = $this->give_synthetic_sizes( $dest_id );

		// ACT: Run the repointed image and a stale-class control through core's
		// content-tag filter.
		$repointed = wp_filter_content_tags( $result );
		$control   = wp_filter_content_tags(
			'<img src="' . $dest_src . '" class="wp-image-900888"/>'
		);

		// ASSERT: The repointed image gains a srcset naming a dest size; the
		// stale-class control gains none.
		$this->assertStringContainsString( 'srcset=', $repointed );
		$this->assertStringContainsString( "{$stem}-1024x768", $repointed );
		$this->assertStringNotContainsString( 'srcset=', $control );
	}

	/**
	 * Resolves the destination attachment ID from the first image's src in
	 * processed content.
	 *
	 * @param string $html Processed content.
	 * @return int Destination attachment ID, or 0 when unresolved.
	 */
	private function dest_id_of( string $html ): int {
		return $this->media_importer->get_attachment_id_from_url(
			(string) $this->first_img_attr( $html, 'src' )
		);
	}

	/**
	 * Returns the first image's value for an attribute, or null when absent.
	 *
	 * @param string $html Content to scan.
	 * @param string $attr Attribute name.
	 * @return string|null Attribute value, or null.
	 */
	private function first_img_attr( string $html, string $attr ): ?string {
		return $this->img_attrs( $html, $attr )[0] ?? null;
	}

	/**
	 * Returns every image's value for an attribute in document order; a missing
	 * value is recorded as null.
	 *
	 * @param string $html Content to scan.
	 * @param string $attr Attribute name.
	 * @return list<string|null> Attribute values per image.
	 */
	private function img_attrs( string $html, string $attr ): array {
		$values    = array();
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			if ( 'IMG' !== $processor->get_tag() ) {
				continue;
			}

			$value    = $processor->get_attribute( $attr );
			$values[] = is_string( $value ) ? $value : null;
		}

		return $values;
	}

	/**
	 * Overwrites an attachment's metadata with two synthetic 4:3 sub-sizes so
	 * wp_calculate_image_srcset() has multiple candidates. Returns the full
	 * file's extension-less basename for building expected size filenames.
	 *
	 * @param int $attachment_id Destination attachment.
	 * @return string Extension-less basename of the full-size file.
	 */
	private function give_synthetic_sizes( int $attachment_id ): string {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$file = is_array( $meta ) && isset( $meta['file'] )
			? (string) $meta['file']
			: '';
		$ext  = pathinfo( $file, PATHINFO_EXTENSION );
		$stem = wp_basename( $file, ".{$ext}" );

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'       => $file,
				'width'      => 2000,
				'height'     => 1500,
				'image_meta' => array(),
				'sizes'      => array(
					'medium' => array(
						'file'      => "{$stem}-300x225.{$ext}",
						'width'     => 300,
						'height'    => 225,
						'mime-type' => 'image/jpeg',
					),
					'large'  => array(
						'file'      => "{$stem}-1024x768.{$ext}",
						'width'     => 1024,
						'height'    => 768,
						'mime-type' => 'image/jpeg',
					),
				),
			)
		);

		return $stem;
	}
}
