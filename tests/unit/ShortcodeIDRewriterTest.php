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
	 * Verifies that an unquoted ids CSV, a form WordPress' shortcode parser
	 * accepts, is rewritten and left unquoted.
	 */
	public function test_unquoted_ids_rewritten(): void {
		// ARRANGE: gallery with a bare, unquoted ids list.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver(
			array(
				705 => 12,
				704 => 13,
			)
		);
		$content  = '[gallery ids=705,704 columns="3"]';

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: IDs mapped; the value stays unquoted, columns untouched.
		$this->assertSame( '[gallery ids=12,13 columns="3"]', $result );
	}

	/**
	 * Verifies that a single-quoted ids CSV is rewritten with its single quotes
	 * preserved.
	 */
	public function test_single_quoted_ids_rewritten(): void {
		// ARRANGE: gallery with single-quoted ids.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 7 => 70 ) );
		$content  = "[gallery ids='7']";

		// ACT: rewrite the media shortcode IDs.
		$result = $rewriter->rewrite_media_shortcode_ids( $content, $resolver );

		// ASSERT: rewritten; single quoting preserved.
		$this->assertSame( "[gallery ids='70']", $result );
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

	/**
	 * Verifies that a cross-post gallery id is remapped to its destination post
	 * id, leaving the rest of the shortcode intact.
	 */
	public function test_gallery_post_id_remapped_to_dest(): void {
		// ARRANGE: A gallery referencing another post by id.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 123 => 999 ) );
		$content  = '[gallery id="123" columns="3"]';

		// ACT: Rewrite the singular post reference; the post is not self.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: The id maps to dest; other attributes are byte-identical.
		$this->assertSame( '[gallery id="999" columns="3"]', $result );
	}

	/**
	 * Verifies that a playlist post reference is remapped and its type attribute
	 * preserved.
	 */
	public function test_playlist_post_id_remapped_type_preserved(): void {
		// ARRANGE: A video playlist referencing another post.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 55 => 88 ) );
		$content  = '[playlist type="video" id="55"]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: Id remapped; type preserved.
		$this->assertSame( '[playlist type="video" id="88"]', $result );
	}

	/**
	 * Verifies that a gallery id naming the importing post's own set is
	 * stripped so the shortcode falls back to core's current-post default,
	 * without consulting the resolver.
	 */
	public function test_self_post_id_stripped_to_bare(): void {
		// ARRANGE: An explicit self reference and a resolver that would map it.
		$rewriter = $this->build_rewriter();
		$calls    = array();
		$resolver = $this->id_resolver( array( 7001 => 7001 ), $calls );
		$content  = '[gallery id="7001"]';

		// ACT: Rewrite with self = 7001.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 7001 );

		// ASSERT: The id is stripped, and the resolver was never consulted.
		$this->assertSame( '[gallery]', $result );
		$this->assertSame( array(), $calls );
	}

	/**
	 * Verifies that stripping a self id preserves the shortcode's other
	 * attributes and their spacing, whether the id leads or trails.
	 */
	public function test_self_post_id_strip_preserves_other_attrs(): void {
		// ARRANGE: A resolver that maps nothing (self is stripped, not mapped).
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array() );

		// ACT + ASSERT: Trailing id stripped, leaving the leading attribute.
		$this->assertSame(
			'[gallery columns="3"]',
			$rewriter->rewrite_gallery_post_reference(
				'[gallery columns="3" id="7001"]',
				$resolver,
				7001
			)
		);

		// ACT + ASSERT: Leading id stripped, leaving the trailing attribute.
		$this->assertSame(
			'[gallery columns="3"]',
			$rewriter->rewrite_gallery_post_reference(
				'[gallery id="7001" columns="3"]',
				$resolver,
				7001
			)
		);
	}

	/**
	 * Verifies that a cross-post id the resolver cannot map is left in place.
	 */
	public function test_unresolved_post_id_left_in_place(): void {
		// ARRANGE: A resolver that maps nothing.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array() );
		$content  = '[gallery id="404"]';

		// ACT: Rewrite; 404 is neither self nor resolvable.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 7001 );

		// ASSERT: The shortcode is byte-for-byte unchanged.
		$this->assertSame( '[gallery id="404"]', $result );
	}

	/**
	 * Verifies that the attachment-list attributes (ids/include/exclude) are
	 * not touched by the singular post-reference rewrite.
	 */
	public function test_attachment_lists_untouched_by_post_reference(): void {
		// ARRANGE: A resolver that would map the numbers if they were consulted.
		$rewriter = $this->build_rewriter();
		$calls    = array();
		$resolver = $this->id_resolver(
			array(
				1 => 91,
				2 => 92,
				3 => 93,
			),
			$calls
		);
		$content  = '[gallery ids="1,2" include="3"]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: No id attribute present, so nothing changes and the resolver
		// is never consulted.
		$this->assertSame( '[gallery ids="1,2" include="3"]', $result );
		$this->assertSame( array(), $calls );
	}

	/**
	 * Verifies that a data-id attribute is not mistaken for the singular id.
	 */
	public function test_data_id_not_mistaken_for_post_id(): void {
		// ARRANGE: A data-id precedes the real id.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 5 => 50 ) );
		$content  = '[gallery data-id="9" id="5"]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: The real id is remapped; data-id is left untouched.
		$this->assertSame( '[gallery data-id="9" id="50"]', $result );
	}

	/**
	 * Verifies that a cross-post id repeated across shortcodes is resolved only
	 * once per run.
	 */
	public function test_repeated_post_id_resolved_once(): void {
		// ARRANGE: The same post id appears in a gallery and a playlist.
		$rewriter = $this->build_rewriter();
		$calls    = array();
		$resolver = $this->id_resolver( array( 7 => 70 ), $calls );
		$content  = '[gallery id="7"] and [playlist id="7"]';

		// ACT: Rewrite the singular post references.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: Both rewritten, but the resolver ran once for id 7.
		$this->assertSame( '[gallery id="70"] and [playlist id="70"]', $result );
		$this->assertSame( array( 7 ), $calls );
	}

	/**
	 * Verifies that a deliberately escaped [[gallery]] literal is left untouched.
	 */
	public function test_escaped_shortcode_post_reference_untouched(): void {
		// ARRANGE: An escaped gallery literal referencing a post.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 5 => 50 ) );
		$content  = '[[gallery id="5"]]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: The escaped literal is preserved verbatim.
		$this->assertSame( '[[gallery id="5"]]', $result );
	}

	/**
	 * Verifies that unquoted and single-quoted id values are remapped with
	 * their quoting style preserved.
	 */
	public function test_unquoted_and_single_quoted_post_id_remapped(): void {
		// ARRANGE: A resolver mapping the referenced post.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 5 => 99 ) );

		// ACT + ASSERT: Unquoted value stays unquoted.
		$this->assertSame(
			'[gallery id=99]',
			$rewriter->rewrite_gallery_post_reference( '[gallery id=5]', $resolver, 0 )
		);

		// ACT + ASSERT: Single-quoted value keeps single quotes.
		$this->assertSame(
			"[gallery id='99']",
			$rewriter->rewrite_gallery_post_reference( "[gallery id='5']", $resolver, 0 )
		);
	}

	/**
	 * Verifies that the singular id is left untouched when an ids/include
	 * selector is present, since core renders that set and ignores the id.
	 */
	public function test_id_with_ids_selector_left_untouched(): void {
		// ARRANGE: A resolver that would map the id if it were consulted.
		$rewriter = $this->build_rewriter();
		$calls    = array();
		$resolver = $this->id_resolver( array( 909 => 42 ), $calls );
		$content  = '[gallery ids="1,2" id="909"]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: The shortcode is unchanged and the resolver was never consulted.
		$this->assertSame( '[gallery ids="1,2" id="909"]', $result );
		$this->assertSame( array(), $calls );
	}

	/**
	 * Verifies that an exclude selector does not suppress the id remap, since
	 * core still renders the id's children minus the exclusions.
	 */
	public function test_id_with_exclude_selector_still_remapped(): void {
		// ARRANGE: A gallery excluding one attachment while referencing a post.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 909 => 42 ) );
		$content  = '[gallery id="909" exclude="3"]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: The id is remapped; exclude is preserved.
		$this->assertSame( '[gallery id="42" exclude="3"]', $result );
	}

	/**
	 * Verifies that an unquoted id whose value is not a bare integer (a numeric
	 * prefix followed by letters) is left untouched, matching the CSV rewriter.
	 */
	public function test_unquoted_id_with_trailing_word_left_untouched(): void {
		// ARRANGE: A malformed unquoted id value.
		$rewriter = $this->build_rewriter();
		$resolver = $this->id_resolver( array( 5 => 900 ) );
		$content  = '[gallery id=5abc]';

		// ACT: Rewrite the singular post reference.
		$result = $rewriter->rewrite_gallery_post_reference( $content, $resolver, 0 );

		// ASSERT: The malformed token is left verbatim.
		$this->assertSame( '[gallery id=5abc]', $result );
	}

	/**
	 * Verifies that collect_shortcode_attachment_ids gathers the ids CSV of a
	 * gallery, across quoting styles.
	 */
	public function test_collect_gathers_gallery_ids(): void {
		// ARRANGE: galleries with the three ids quoting forms.
		$rewriter = $this->build_rewriter();

		// ACT + ASSERT: each quoting form yields the numeric tokens.
		$this->assertSame(
			array( 10, 20, 30 ),
			$rewriter->collect_shortcode_attachment_ids( '[gallery ids="10,20,30"]' )
		);
		$this->assertSame(
			array( 10, 20 ),
			$rewriter->collect_shortcode_attachment_ids( '[gallery ids=10,20]' )
		);
		$this->assertSame(
			array( 7 ),
			$rewriter->collect_shortcode_attachment_ids( "[playlist ids='7']" )
		);
	}

	/**
	 * Verifies that include is collected but exclude is omitted, since exclude
	 * removes an attachment from the set rather than referencing it.
	 */
	public function test_collect_includes_include_omits_exclude(): void {
		// ARRANGE: a shortcode carrying all three id-bearing attributes.
		$rewriter = $this->build_rewriter();

		// ACT: collect the referenced IDs.
		$ids = $rewriter->collect_shortcode_attachment_ids(
			'[gallery ids="1,2" include="3" exclude="4"]'
		);

		// ASSERT: ids and include are gathered; exclude is left out.
		$this->assertSame( array( 1, 2, 3 ), $ids );
	}

	/**
	 * Verifies that the singular id post reference is not collected, being a
	 * post ID rather than an attachment list.
	 */
	public function test_collect_ignores_singular_post_id(): void {
		// ARRANGE: a gallery referencing a parent post by id.
		$rewriter = $this->build_rewriter();

		// ACT + ASSERT: the singular id yields nothing.
		$this->assertSame(
			array(),
			$rewriter->collect_shortcode_attachment_ids( '[gallery id="123"]' )
		);
	}

	/**
	 * Verifies that an id repeated across shortcodes is collected once.
	 */
	public function test_collect_deduplicates_ids(): void {
		// ARRANGE: the same id appears in a gallery and a playlist.
		$rewriter = $this->build_rewriter();

		// ACT: collect across both shortcodes.
		$ids = $rewriter->collect_shortcode_attachment_ids(
			'[gallery ids="7"] and [playlist ids="7,8"]'
		);

		// ASSERT: the shared id appears once.
		$this->assertSame( array( 7, 8 ), $ids );
	}

	/**
	 * Verifies that a similarly named attribute such as data-ids is not
	 * mistaken for the ids attribute.
	 */
	public function test_collect_ignores_similar_named_attribute(): void {
		// ARRANGE: a real ids attribute alongside a data-ids attribute.
		$rewriter = $this->build_rewriter();

		// ACT: collect the referenced IDs.
		$ids = $rewriter->collect_shortcode_attachment_ids(
			'[gallery ids="1" data-ids="9"]'
		);

		// ASSERT: only the real ids attribute is read.
		$this->assertSame( array( 1 ), $ids );
	}

	/**
	 * Verifies that an escaped [[gallery]] literal contributes no IDs.
	 */
	public function test_collect_skips_escaped_shortcode(): void {
		// ARRANGE: an escaped gallery literal.
		$rewriter = $this->build_rewriter();

		// ACT + ASSERT: the escaped literal is not read.
		$this->assertSame(
			array(),
			$rewriter->collect_shortcode_attachment_ids( '[[gallery ids="1"]]' )
		);
	}

	/**
	 * Verifies that content without a gallery or playlist shortcode yields no
	 * IDs.
	 */
	public function test_collect_returns_empty_without_media_shortcodes(): void {
		// ARRANGE: prose plus a caption, but no gallery/playlist.
		$rewriter = $this->build_rewriter();

		// ACT + ASSERT: nothing to collect.
		$this->assertSame(
			array(),
			$rewriter->collect_shortcode_attachment_ids(
				'<p>Text</p>[caption id="attachment_1"]<img src="x" />[/caption]'
			)
		);
	}

	/**
	 * Verifies that a cross-post gallery reference is collected with its tag
	 * and source id, and no playlist type.
	 */
	public function test_collect_returns_cross_post_gallery_reference(): void {
		// ARRANGE: A gallery referencing another post.
		$rewriter = $this->build_rewriter();

		// ACT: Collect references, self = 700.
		$refs = $rewriter->collect_cross_post_references( '[gallery id="500"]', 700 );

		// ASSERT: One gallery reference to source post 500.
		$this->assertSame(
			array(
				array(
					'tag'       => 'gallery',
					'type'      => '',
					'source_id' => 500,
				),
			),
			$refs
		);
	}

	/**
	 * Verifies that a playlist reference carries its type, so the consumer
	 * pulls the matching media group.
	 */
	public function test_collect_reads_playlist_type(): void {
		// ARRANGE: A video playlist referencing another post.
		$rewriter = $this->build_rewriter();

		// ACT: Collect references.
		$refs = $rewriter->collect_cross_post_references(
			'[playlist type="video" id="55"]',
			0
		);

		// ASSERT: The playlist reference carries type "video".
		$this->assertSame(
			array(
				array(
					'tag'       => 'playlist',
					'type'      => 'video',
					'source_id' => 55,
				),
			),
			$refs
		);
	}

	/**
	 * Verifies that a reference naming the importing post's own set is not
	 * collected, since it is not a cross-post reference.
	 */
	public function test_collect_skips_self_reference(): void {
		// ARRANGE + ACT: The id equals the importing post's source id.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'[gallery id="700"]',
			700
		);

		// ASSERT: No references collected.
		$this->assertSame( array(), $refs );
	}

	/**
	 * Verifies that a shortcode with an ids/include selector is skipped, since
	 * core ignores its singular id.
	 */
	public function test_collect_skips_reference_with_ids_selector(): void {
		// ARRANGE + ACT: An ids selector accompanies the singular id.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'[gallery ids="1,2" id="500"]',
			0
		);

		// ASSERT: No references collected.
		$this->assertSame( array(), $refs );
	}

	/**
	 * Verifies that an escaped [[gallery]] literal is not collected.
	 */
	public function test_collect_skips_escaped_literal(): void {
		// ARRANGE + ACT: An escaped literal referencing a post.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'[[gallery id="5"]]',
			0
		);

		// ASSERT: No references collected.
		$this->assertSame( array(), $refs );
	}

	/**
	 * Verifies that a data-id attribute is not mistaken for the singular id.
	 */
	public function test_collect_ignores_data_id(): void {
		// ARRANGE + ACT: A data-id precedes the real id.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'[gallery data-id="9" id="5"]',
			0
		);

		// ASSERT: The real id is collected, not the data-id.
		$this->assertSame( 5, $refs[0]['source_id'] );
	}

	/**
	 * Verifies that unquoted and single-quoted id values are collected,
	 * matching the forms the singular-id rewrite accepts.
	 */
	public function test_collect_reads_unquoted_and_single_quoted_id(): void {
		// ARRANGE + ACT: An unquoted id and a single-quoted id.
		$unquoted = $this->build_rewriter()->collect_cross_post_references(
			'[gallery id=5]',
			0
		);
		$single   = $this->build_rewriter()->collect_cross_post_references(
			"[gallery id='7']",
			0
		);

		// ASSERT: Both are collected with their numeric source id.
		$this->assertSame( 5, $unquoted[0]['source_id'] );
		$this->assertSame( 7, $single[0]['source_id'] );
	}

	/**
	 * Verifies that multiple cross-post references are each collected.
	 */
	public function test_collect_multiple_references(): void {
		// ARRANGE + ACT: A gallery and a playlist reference in one post.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'[gallery id="10"] and [playlist id="20"]',
			0
		);

		// ASSERT: Both references collected in order.
		$this->assertSame( array( 10, 20 ), array_column( $refs, 'source_id' ) );
		$this->assertSame(
			array( 'gallery', 'playlist' ),
			array_column( $refs, 'tag' )
		);
	}

	/**
	 * Verifies that content without a live gallery/playlist collects nothing.
	 */
	public function test_collect_empty_without_shortcodes(): void {
		// ARRANGE + ACT: Prose with no gallery/playlist shortcode.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'<p>No shortcodes here.</p>',
			0
		);

		// ASSERT: No references collected.
		$this->assertSame( array(), $refs );
	}

	/**
	 * Verifies that a zero or non-numeric id is not collected.
	 */
	public function test_collect_skips_zero_and_non_numeric_id(): void {
		// ARRANGE + ACT: A zero id and a non-numeric id.
		$refs = $this->build_rewriter()->collect_cross_post_references(
			'[gallery id="0"] [playlist id="abc"]',
			0
		);

		// ASSERT: Neither is collected.
		$this->assertSame( array(), $refs );
	}
}
