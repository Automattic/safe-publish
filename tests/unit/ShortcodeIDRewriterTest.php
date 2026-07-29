<?php
/**
 * Shortcode_ID_Rewriter unit tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Content\Shortcode_ID_Rewriter;

/**
 * Tests the Shortcode_ID_Rewriter caption and gallery/playlist rewriting logic.
 */
class ShortcodeIDRewriterTest extends TestCase {

	/**
	 * Builds a rewriter with a static URL => ID lookup map.
	 *
	 * @param array<string, int> $map URL => attachment ID.
	 * @return Shortcode_ID_Rewriter
	 */
	private function build_rewriter( array $map = array() ): Shortcode_ID_Rewriter {
		return new Shortcode_ID_Rewriter(
			static fn ( string $url ): int => $map[ $url ] ?? 0
		);
	}

	/**
	 * Builds a source-ID => dest-ID resolver from a map, optionally recording
	 * each looked-up source ID so tests can assert memoization.
	 *
	 * @param array<int, int> $map   Source ID => dest ID (0 when unmapped).
	 * @param array<int, int> $calls Filled with each source ID passed in.
	 * @return callable
	 */
	private function id_resolver( array $map, ?array &$calls = null ): callable {
		return static function ( int $source_id ) use ( $map, &$calls ): int {
			if ( is_array( $calls ) ) {
				$calls[] = $source_id;
			}

			return $map[ $source_id ] ?? 0;
		};
	}

	/**
	 * Verifies that a [caption] shortcode wrapping a resolvable img has its
	 * attachment_N rewritten to the dest attachment ID.
	 */
	public function test_caption_id_rewrites_to_dest_attachment_id(): void {
		// ARRANGE: caption with embedded img the lookup resolves.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 42 )
		);
		$content  = '[caption id="attachment_5001" align="aligncenter" width="800"]'
			. '<img src="http://dest.example.com/image.jpg" alt="x" />'
			. ' Caption.[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced with the dest ID.
		$this->assertStringContainsString( 'id="attachment_42"', $result );
		$this->assertStringNotContainsString( 'attachment_5001', $result );
	}

	/**
	 * Verifies that [wp_caption] (the alias of [caption]) gets the same
	 * treatment.
	 */
	public function test_wp_caption_alias_rewrites(): void {
		// ARRANGE: wp_caption form of the shortcode.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 7 )
		);
		$content  = '[wp_caption id="attachment_99" align="left"]'
			. '<img src="http://dest.example.com/image.jpg" />[/wp_caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced with the dest ID.
		$this->assertStringContainsString( 'id="attachment_7"', $result );
	}

	/**
	 * Verifies that a data-id attribute is not mistaken for the caption's id
	 * attribute when it precedes it, so only the real id is rewritten.
	 */
	public function test_caption_data_id_not_mistaken_for_id(): void {
		// ARRANGE: a data-id="attachment_N" appears before the real id.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/x.jpg' => 42 )
		);
		$content  = '[caption data-id="attachment_9" align="center" id="attachment_5"]'
			. '<img src="http://dest.example.com/x.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the real id is rewritten; data-id is left untouched.
		$this->assertStringContainsString( 'id="attachment_42"', $result );
		$this->assertStringContainsString( 'data-id="attachment_9"', $result );
	}

	/**
	 * Verifies that a caption whose img can't be resolved on dest is left
	 * untouched.
	 */
	public function test_unresolvable_img_leaves_caption_id_unchanged(): void {
		// ARRANGE: lookup returns 0 for the third-party URL.
		$rewriter = $this->build_rewriter();
		$content  = '[caption id="attachment_5001" align="aligncenter"]'
			. '<img src="http://third-party.example.com/x.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that a caption with no embedded img is left untouched (nothing
	 * to look up against).
	 */
	public function test_caption_without_img_left_unchanged(): void {
		// ARRANGE: caption body has no img.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 42 )
		);
		$content  = '[caption id="attachment_5001"]Just text.[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that the id attribute can appear in any position within the
	 * shortcode's attribute list and still get rewritten.
	 */
	public function test_id_attribute_order_does_not_matter(): void {
		// ARRANGE: id appears at the end of the attribute list.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 99 )
		);
		$content  = '[caption align="aligncenter" width="800" id="attachment_5001"]'
			. '<img src="http://dest.example.com/image.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced regardless of position.
		$this->assertStringContainsString( 'id="attachment_99"', $result );
	}

	/**
	 * Verifies that single-quoted id and img-src attributes are handled the
	 * same as double-quoted ones.
	 */
	public function test_single_quoted_attributes_supported(): void {
		// ARRANGE: shortcode uses single quotes throughout.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 12 )
		);
		$content  = "[caption id='attachment_5001' align='aligncenter']"
			. "<img src='http://dest.example.com/image.jpg' />[/caption]";

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced; quoting style preserved.
		$this->assertStringContainsString( "id='attachment_12'", $result );
	}

	/**
	 * Verifies that multiple caption shortcodes in the same content are each
	 * rewritten independently.
	 */
	public function test_multiple_captions_all_rewritten(): void {
		// ARRANGE: two captions pointing at different dest IDs.
		$rewriter = $this->build_rewriter(
			array(
				'http://dest.example.com/a.jpg' => 1,
				'http://dest.example.com/b.jpg' => 2,
			)
		);
		$content  = '[caption id="attachment_5001"]'
			. '<img src="http://dest.example.com/a.jpg" />[/caption]'
			. ' between '
			. '[caption id="attachment_5002"]'
			. '<img src="http://dest.example.com/b.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: both source IDs are rewritten to their dest counterparts.
		$this->assertStringContainsString( 'id="attachment_1"', $result );
		$this->assertStringContainsString( 'id="attachment_2"', $result );
		$this->assertStringNotContainsString( 'attachment_5001', $result );
		$this->assertStringNotContainsString( 'attachment_5002', $result );
	}

	/**
	 * Verifies that content with no caption shortcodes is returned unchanged.
	 */
	public function test_content_without_captions_unchanged(): void {
		// ARRANGE: prose only, with an img but no caption wrapper.
		$rewriter = $this->build_rewriter();
		$content  = '<p>Hello.</p><img src="http://example.com/x.jpg" />';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that empty content short-circuits cleanly.
	 */
	public function test_empty_content_unchanged(): void {
		// ARRANGE + ACT: empty input.
		$result = $this->build_rewriter()->rewrite_caption_ids( '' );

		// ASSERT: empty output.
		$this->assertSame( '', $result );
	}

	/**
	 * Verifies that a [gallery ids] shortcode has each source ID rewritten to
	 * its dest ID in order, leaving the other attributes untouched.
	 */
	public function test_gallery_ids_rewritten_in_order(): void {
		// ARRANGE: gallery with three source IDs and unrelated attributes.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver(
			array(
				705 => 12,
				704 => 13,
				703 => 14,
			) 
		);
		$content  = '[gallery ids="705,704,703" columns="3" link="file"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: IDs map to dest in order; other attributes are byte-identical.
		$this->assertSame(
			'[gallery ids="12,13,14" columns="3" link="file"]',
			$result
		);
	}

	/**
	 * Verifies that ids, include, and exclude are all rewritten within one
	 * shortcode.
	 */
	public function test_include_and_exclude_rewritten(): void {
		// ARRANGE: shortcode carrying all three id-bearing attributes.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver(
			array(
				1 => 91,
				2 => 92,
				3 => 93,
				4 => 94,
			)
		);
		$content  = '[gallery ids="1,2" include="3" exclude="4"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: every id-bearing attribute is rewritten.
		$this->assertSame(
			'[gallery ids="91,92" include="93" exclude="94"]',
			$result
		);
	}

	/**
	 * Verifies that a source ID the resolver cannot map (returns 0) is left in
	 * place while its resolvable siblings are rewritten.
	 */
	public function test_unresolved_id_left_in_place(): void {
		// ARRANGE: only the middle ID resolves.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 20 => 200 ) );
		$content  = '[gallery ids="10,20,30"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: resolved token changes, unresolved tokens stay verbatim.
		$this->assertSame( '[gallery ids="10,200,30"]', $result );
	}

	/**
	 * Verifies that whitespace-padded CSVs and non-numeric tokens are handled:
	 * numeric tokens are rewritten, spacing and non-numeric tokens preserved.
	 */
	public function test_whitespace_and_non_numeric_tokens_handled(): void {
		// ARRANGE: padded CSV with a stray non-numeric token.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver(
			array(
				1 => 5,
				2 => 6,
				3 => 7,
			)
		);
		$content  = '[gallery ids="1, 2, foo, 3"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: numeric tokens rewritten; spacing and the word token kept.
		$this->assertSame( '[gallery ids="5, 6, foo, 7"]', $result );
	}

	/**
	 * Verifies that a [playlist] shortcode is rewritten and its type attribute
	 * is preserved.
	 */
	public function test_playlist_ids_rewritten_type_preserved(): void {
		// ARRANGE: an audio playlist.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 55 => 88 ) );
		$content  = '[playlist type="audio" ids="55"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: ids rewritten; type preserved.
		$this->assertSame( '[playlist type="audio" ids="88"]', $result );
	}

	/**
	 * Verifies that the singular id attribute (a post reference, out of scope)
	 * is left untouched.
	 */
	public function test_post_id_attribute_left_untouched(): void {
		// ARRANGE: gallery referencing a parent post by id.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 123 => 999 ) );
		$content  = '[gallery id="123"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: the id attribute is not a media-ID list, so it is unchanged.
		$this->assertSame( '[gallery id="123"]', $result );
	}

	/**
	 * Verifies that content with no gallery/playlist shortcode is returned
	 * unchanged.
	 */
	public function test_content_without_media_shortcodes_unchanged(): void {
		// ARRANGE: prose plus a caption shortcode, but no gallery/playlist.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 1 => 2 ) );
		$content  = '<p>Text</p>[caption id="attachment_1"]<img src="x" />[/caption]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that a source ID repeated across shortcodes is resolved only
	 * once per run.
	 */
	public function test_repeated_id_resolved_once(): void {
		// ARRANGE: the same source ID appears in two shortcodes.
		$rewriter = $this->build_rewriter();
		$calls    = array();
		$resolver = $this->id_resolver( array( 7 => 70 ), $calls );
		$content  = '[gallery ids="7"] and [playlist ids="7"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: both rewritten, but the resolver ran once for ID 7.
		$this->assertSame( '[gallery ids="70"] and [playlist ids="70"]', $result );
		$this->assertSame( array( 7 ), $calls );
	}

	/**
	 * Verifies that a deliberately escaped [[gallery]] literal is left
	 * untouched.
	 */
	public function test_escaped_shortcode_left_untouched(): void {
		// ARRANGE: an escaped gallery literal.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 1 => 9 ) );
		$content  = '[[gallery ids="1"]]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: the escaped literal is preserved verbatim.
		$this->assertSame( '[[gallery ids="1"]]', $result );
	}

	/**
	 * Verifies that an attribute whose name merely ends in a rewritten name
	 * (e.g. a hyphen-prefixed data-ids) is not mistaken for the ids attribute.
	 */
	public function test_similar_named_attribute_not_rewritten(): void {
		// ARRANGE: a real ids attribute alongside a data-ids attribute.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver(
			array(
				1 => 50,
				9 => 90,
			)
		);
		$content  = '[gallery ids="1" data-ids="9"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: only the real ids attribute is rewritten.
		$this->assertSame( '[gallery ids="50" data-ids="9"]', $result );
	}
}
