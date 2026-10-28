<?php
/**
 * Integration tests for the wp-image class written into core/image blocks
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
use WP_HTML_Tag_Processor;

/**
 * Exercises the wp-image class process_image_block() writes into a core/image
 * block: It names the destination attachment exactly once, whether the source
 * markup already named that ID, named a different one, or carried no class.
 * Only class tokens are repointed, so a file name containing wp-image-N stays.
 */
class Content_Processor_Image_Block_Class_Test extends Integration_Test_Case {

	use Image_Byte_Mock_Trait;

	private const SOURCE = 'https://source.example.com';

	/**
	 * System under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * Media importer, reused to seed an attachment before the block runs.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Wires the processor with a real importer and fixture HTTP mocks.
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
	 * Verifies that markup whose class already names the destination attachment
	 * keeps a single wp-image token instead of gaining a duplicate.
	 */
	public function test_keeps_one_class_when_source_id_matches_dest_id(): void {
		// ARRANGE: Seed the attachment so the markup can name its own dest ID.
		$url     = self::SOURCE . '/matching.jpg';
		$dest_id = $this->import( $url );
		$content = $this->image_block( $url, $dest_id, 'wp-image-' . $dest_id );

		// ACT: Process the block, which dedupes onto the seeded attachment.
		$result = $this->process( $content );

		// ASSERT: The class still names the attachment exactly once.
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
	}

	/**
	 * Verifies that a class naming a different attachment is repointed at the
	 * destination while the other class tokens and their order are preserved.
	 */
	public function test_repoints_class_naming_a_different_attachment(): void {
		// ARRANGE: Seed the attachment, then name an unrelated source ID among
		// look-alike tokens.
		$url     = self::SOURCE . '/repointed.jpg';
		$dest_id = $this->import( $url );
		$content = $this->image_block(
			$url,
			900705,
			'alignnone is-wp-image-7 wp-image-7-crop wp-image-900705'
		);

		// ACT: Process the block.
		$result = $this->process( $content );

		// ASSERT: Only the wp-image token changed.
		$this->assertNotSame( 900705, $dest_id );
		$this->assertSame(
			"alignnone is-wp-image-7 wp-image-7-crop wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
	}

	/**
	 * Verifies that markup carrying no class gains one naming the destination
	 * attachment, without disturbing the tag's self-closing form.
	 */
	public function test_adds_class_to_markup_that_carries_none(): void {
		// ARRANGE: An image block whose img has no class attribute.
		$url     = self::SOURCE . '/classless.jpg';
		$content = $this->image_block( $url, null, null );

		// ACT: Process the block.
		$result = $this->process( $content );

		// ASSERT: The fabricated class sits inside a still self-closing tag.
		$dest_id = $this->media_importer->get_attachment_id_from_url(
			(string) $this->first_img_attr( $result, 'src' )
		);
		$this->assertGreaterThan( 0, $dest_id );
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
		$this->assertStringContainsString(
			' class="wp-image-' . $dest_id . '"/>',
			$result
		);
	}

	/**
	 * Verifies that a file name containing wp-image-N is kept in the src and
	 * link, which point at the attachment the class names.
	 */
	public function test_keeps_wp_image_file_name_in_src_and_link(): void {
		// ARRANGE: Seed a linked image named like a class token. Its classed
		// caption follows the img, so the token count must span tags.
		$url     = self::SOURCE . '/wp-image-424242.jpg';
		$dest_id = $this->import( $url );
		$content = '<!-- wp:image {"id":900705,"linkDestination":"media"} -->'
			. "\n" . '<figure class="wp-block-image size-large">'
			. '<a href="' . $url . '"><img src="' . $url . '" alt=""'
			. ' class="wp-image-900705"/></a>'
			. '<figcaption class="wp-element-caption">Lake</figcaption>'
			. "</figure>\n<!-- /wp:image -->";

		// ACT: Process the block.
		$result = $this->process( $content );

		// ASSERT: The src, link, and single class token name one attachment.
		$local_url = wp_get_attachment_url( $dest_id );
		$link      = new WP_HTML_Tag_Processor( $result );
		$link->next_tag( array( 'tag_name' => 'A' ) );
		$this->assertNotSame( 424242, $dest_id );
		$this->assertSame(
			$local_url,
			$this->first_img_attr( $result, 'src' )
		);
		$this->assertSame( $local_url, $link->get_attribute( 'href' ) );
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
	}

	/**
	 * Verifies that markup carrying no class gains one when its file name
	 * contains wp-image-N, and keeps that file name.
	 */
	public function test_adds_class_when_file_name_contains_wp_image(): void {
		// ARRANGE: Seed a class-less image named like a class token.
		$url     = self::SOURCE . '/wp-image-424243.jpg';
		$dest_id = $this->import( $url );
		$content = $this->image_block( $url, null, null );

		// ACT: Process the block.
		$result = $this->process( $content );

		// ASSERT: The class is added, and the src keeps the file name.
		$this->assertSame(
			"wp-image-{$dest_id}",
			$this->first_img_attr( $result, 'class' )
		);
		$this->assertSame(
			wp_get_attachment_url( $dest_id ),
			$this->first_img_attr( $result, 'src' )
		);
	}

	/**
	 * Runs content through the processor.
	 *
	 * @param string $content Block markup to process.
	 * @return string Processed content.
	 */
	private function process( string $content ): string {
		return (string) $this->processor->process_content(
			$content,
			self::SOURCE
		);
	}

	/**
	 * Builds core/image block markup for a source URL.
	 *
	 * @param string      $url         Source image URL.
	 * @param int|null    $block_id    Attachment ID for the block attrs, or null
	 *                                 to serialize the block without attrs.
	 * @param string|null $class_value Value for the img class attribute, or null
	 *                                 to omit the attribute.
	 * @return string Serialized block markup.
	 */
	private function image_block(
		string $url,
		?int $block_id,
		?string $class_value
	): string {
		$attrs      = null === $block_id ? '' : ' {"id":' . $block_id . '}';
		$class_attr = null === $class_value
			? ''
			: ' class="' . $class_value . '"';

		return "<!-- wp:image{$attrs} -->\n"
			. '<figure class="wp-block-image size-large">'
			. '<img src="' . $url . '" alt=""' . $class_attr . '/>'
			. "</figure>\n<!-- /wp:image -->";
	}

	/**
	 * Imports a source URL and returns the destination attachment ID.
	 *
	 * @param string $url Source image URL.
	 * @return int Destination attachment ID.
	 */
	private function import( string $url ): int {
		$attachment_id = $this->media_importer->import_source_media_as_attachment(
			$url,
			self::SOURCE
		);

		$this->assertIsInt( $attachment_id, 'Fixture image should import.' );

		return (int) $attachment_id;
	}

	/**
	 * Returns the first image's value for an attribute, or null when absent.
	 *
	 * @param string $html Content to scan.
	 * @param string $attr Attribute name.
	 * @return string|null Attribute value, or null.
	 */
	private function first_img_attr( string $html, string $attr ): ?string {
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			if ( 'IMG' !== $processor->get_tag() ) {
				continue;
			}

			$value = $processor->get_attribute( $attr );

			return is_string( $value ) ? $value : null;
		}

		return null;
	}
}
