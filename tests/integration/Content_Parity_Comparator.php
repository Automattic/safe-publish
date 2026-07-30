<?php
/**
 * Source/destination post_content parity comparator for integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_HTML_Tag_Processor;

/**
 * URL/ID-rewrite-aware comparator for post_content parity.
 *
 * Companion to Post_Parity_Asserter: post_content can't be checked with
 * assertSame() because the importer rewrites every media URL and Gutenberg
 * attachment-ID reference. Positive checks lock the rewrites; reverse
 * assertions lock documented gaps and safety invariants. Block-structural and
 * shortcode parity are checked structurally (names, balance, attributes), not
 * for per-block semantic equivalence.
 *
 * Out of scope: block-gallery `attrs.ids` and `data-id` (not seeded today),
 * per-block semantic equivalence.
 */
final class Content_Parity_Comparator {

	/**
	 * URL-bearing tag attributes checked for rewrite parity, keyed by the
	 * uppercase tag name WP_HTML_Tag_Processor::get_tag() returns. Block
	 * comments aren't scanned (the tag processor skips them), so the
	 * `attrs.url` the importer adds to image blocks doesn't show up here;
	 * embed-block `attrs.url` is checked by assert_embed_url_parity().
	 *
	 * `srcset` descriptors are handled by Content_Media_Processor at import
	 * time but the seeder doesn't emit srcset yet, so they're not checked
	 * here either; grow seeder coverage alongside this map.
	 *
	 * @var array<string, list<string>>
	 */
	private const URL_ATTRS_BY_TAG = array(
		'IMG'    => array( 'src' ),
		'VIDEO'  => array( 'src', 'poster' ),
		'AUDIO'  => array( 'src' ),
		'SOURCE' => array( 'src' ),
		'EMBED'  => array( 'src' ),
		'OBJECT' => array( 'data' ),
		'A'      => array( 'href' ),
	);

	/**
	 * Substrings that indicate double-encoded entities; a hit means the
	 * rewrite pipeline re-escaped an already-encoded value.
	 *
	 * @var list<string>
	 */
	private const DOUBLE_ENCODED_ENTITIES = array(
		'&amp;amp;',
		'&amp;quot;',
		'&amp;#0',
		'&amp;lt;',
		'&amp;gt;',
	);

	/**
	 * Gallery/playlist attributes whose CSV values carry attachment IDs.
	 * shortcode_parse_atts() keys them separately from lookalikes such as
	 * data-ids, giving the boundary precision the rewriter's regex has.
	 *
	 * @var list<string>
	 */
	private const MEDIA_SHORTCODE_ID_ATTRS = array( 'ids', 'include', 'exclude' );

	/**
	 * Asserts URL parity per (tag, attr) bucket: source URLs map through the
	 * sideload map and the resulting multiset must equal the dest multiset.
	 * URLs absent from the map round-trip unchanged.
	 *
	 * @param string                $source_content        Source post_content.
	 * @param string                $dest_content          Imported dest post_content.
	 * @param array<string, string> $source_url_to_dest_url Sideload map.
	 * @param TestCase              $test                  Active test case.
	 */
	public static function assert_url_parity(
		string $source_content,
		string $dest_content,
		array $source_url_to_dest_url,
		TestCase $test
	): void {
		$expected = self::collect_url_attrs( $source_content );

		foreach ( $expected as $key => $urls ) {
			$expected[ $key ] = array_map(
				static fn ( string $url ): string =>
					$source_url_to_dest_url[ $url ] ?? $url,
				$urls
			);
			sort( $expected[ $key ] );
		}
		ksort( $expected );

		$actual = self::collect_url_attrs( $dest_content );
		foreach ( array_keys( $actual ) as $key ) {
			sort( $actual[ $key ] );
		}
		ksort( $actual );

		$test->assertSame(
			$expected,
			$actual,
			'Dest content must carry the rewritten URL multiset, per'
			. ' (tag, attribute) bucket'
		);
	}

	/**
	 * Asserts inline <img alt> parity. The importer rewrites images surgically
	 * (URL/ID swap, no tag regeneration), so every source alt survives
	 * verbatim; the source and dest alt multisets must be equal. A change that
	 * regenerated the <img> and dropped alt fails here.
	 *
	 * @param string   $source_content Source post_content.
	 * @param string   $dest_content   Imported dest post_content.
	 * @param TestCase $test           Active test case.
	 */
	public static function assert_inline_img_alt_parity(
		string $source_content,
		string $dest_content,
		TestCase $test
	): void {
		$expected = self::collect_img_alts( $source_content );
		$actual   = self::collect_img_alts( $dest_content );

		sort( $expected );
		sort( $actual );

		$test->assertSame(
			$expected,
			$actual,
			'Dest content must preserve the inline <img alt> multiset verbatim'
		);
	}

	/**
	 * Asserts embed-block url parity: each core/embed (and legacy core-embed/*)
	 * block's url attribute maps through the sideload map and the resulting
	 * multiset must equal the dest multiset. External provider URLs are absent
	 * from the map, so this reverse-asserts the importer leaves them verbatim.
	 *
	 * The url lives in the block-comment JSON, which WP_HTML_Tag_Processor
	 * skips, so assert_url_parity() never sees it; this covers it explicitly.
	 *
	 * @param string                $source_content        Source post_content.
	 * @param string                $dest_content          Imported dest post_content.
	 * @param array<string, string> $source_url_to_dest_url Sideload map.
	 * @param TestCase              $test                  Active test case.
	 */
	public static function assert_embed_url_parity(
		string $source_content,
		string $dest_content,
		array $source_url_to_dest_url,
		TestCase $test
	): void {
		$expected = array_map(
			static fn ( string $url ): string =>
				$source_url_to_dest_url[ $url ] ?? $url,
			self::collect_embed_urls( parse_blocks( $source_content ) )
		);
		$actual   = self::collect_embed_urls( parse_blocks( $dest_content ) );

		sort( $expected );
		sort( $actual );

		$test->assertSame(
			$expected,
			$actual,
			'Dest content must carry the embed url multiset, with external'
			. ' provider URLs preserved verbatim'
		);
	}

	/**
	 * Asserts that attachment-ID references (Gutenberg block-comment
	 * {"id":N}, wp-image-N classnames, and caption-shortcode
	 * attachment_N IDs) rewrite to dest IDs as multisets, so leaks and
	 * phantoms both surface.
	 *
	 * @param string          $source_content       Source post_content.
	 * @param string          $dest_content         Imported dest post_content.
	 * @param array<int, int> $source_id_to_dest_id Source ID => dest ID.
	 * @param TestCase        $test                 Active test case.
	 */
	public static function assert_attachment_id_parity(
		string $source_content,
		string $dest_content,
		array $source_id_to_dest_id,
		TestCase $test
	): void {
		self::assert_id_multiset_parity(
			self::collect_block_attr_ids( $source_content ),
			self::collect_block_attr_ids( $dest_content ),
			$source_id_to_dest_id,
			'Gutenberg block-comment {"id":N} references',
			$test
		);

		self::assert_id_multiset_parity(
			self::collect_wp_image_class_ids( $source_content ),
			self::collect_wp_image_class_ids( $dest_content ),
			$source_id_to_dest_id,
			'wp-image-N classnames',
			$test
		);

		self::assert_id_multiset_parity(
			self::collect_caption_shortcode_ids( $source_content ),
			self::collect_caption_shortcode_ids( $dest_content ),
			$source_id_to_dest_id,
			'caption shortcode attachment_N IDs',
			$test
		);
	}

	/**
	 * Reverse-asserts the source host is absent from dest content. The
	 * importer's catch-all replace_source_urls() rewrites any URL the
	 * structured paths missed, so any residue is a regression.
	 *
	 * @param string   $dest_content    Imported dest post_content.
	 * @param string   $source_base_url Source site URL.
	 * @param TestCase $test            Active test case.
	 */
	public static function assert_no_source_host_leak(
		string $dest_content,
		string $source_base_url,
		TestCase $test
	): void {
		$source_host = wp_parse_url( $source_base_url, PHP_URL_HOST );

		if ( ! is_string( $source_host ) || '' === $source_host ) {
			return;
		}

		$test->assertStringNotContainsString(
			$source_host,
			$dest_content,
			"Dest content must not contain source host '{$source_host}'"
		);
	}

	/**
	 * Reverse-asserts no double-encoded HTML entities on dest. Cheap canary
	 * for accidental double-`esc_*()` paths.
	 *
	 * @param string   $dest_content Imported dest post_content.
	 * @param TestCase $test         Active test case.
	 */
	public static function assert_no_double_encoded_entities(
		string $dest_content,
		TestCase $test
	): void {
		foreach ( self::DOUBLE_ENCODED_ENTITIES as $needle ) {
			$test->assertStringNotContainsString(
				$needle,
				$dest_content,
				"Dest content must not contain double-encoded '{$needle}'"
			);
		}
	}

	/**
	 * Asserts Gutenberg block-structural parity. The importer rewrites block
	 * attributes by string replacement and never restructures blocks, so the
	 * multiset of block names must be identical between source and dest;
	 * corrupted attribute JSON would demote a block to freeform and drop it from
	 * the dest multiset. Also checks dest parses into at least one block and
	 * carries no orphaned block-comment delimiters.
	 *
	 * @param string   $source_content Source post_content.
	 * @param string   $dest_content   Imported dest post_content.
	 * @param TestCase $test           Active test case.
	 */
	public static function assert_block_structural_parity(
		string $source_content,
		string $dest_content,
		TestCase $test
	): void {
		// Empty source has no blocks to mirror, so the importer must leave the
		// dest empty too. parse_blocks('') yields zero blocks, so the >=1 rule
		// below would otherwise misfire on a legitimately empty body.
		if ( '' === trim( $source_content ) ) {
			$test->assertSame(
				'',
				trim( $dest_content ),
				'Empty source content must import to empty dest content'
			);
			return;
		}

		$dest_blocks = parse_blocks( $dest_content );

		$test->assertGreaterThanOrEqual(
			1,
			count( $dest_blocks ),
			'Dest content must parse into at least one block'
		);

		$source_names = self::collect_block_names( parse_blocks( $source_content ) );
		$dest_names   = self::collect_block_names( $dest_blocks );
		sort( $source_names );
		sort( $dest_names );

		$test->assertSame(
			$source_names,
			$dest_names,
			'Dest must carry the same multiset of Gutenberg block names as source'
		);

		self::assert_no_residual_block_delimiters( $dest_blocks, $test );
	}

	/**
	 * Asserts caption-shortcode parity. The importer preserves every source
	 * caption verbatim apart from rewriting its attachment id, so dest must keep
	 * the same caption count, balanced open/close tags (no half-tag leftovers),
	 * and identical non-id attributes. Count parity doubles as a reverse-
	 * assertion: a future importer that transformed captions into blocks would
	 * drop the dest count and fail here, forcing an explicit decision.
	 *
	 * @param string   $source_content Source post_content.
	 * @param string   $dest_content   Imported dest post_content.
	 * @param TestCase $test           Active test case.
	 */
	public static function assert_shortcode_parity(
		string $source_content,
		string $dest_content,
		TestCase $test
	): void {
		$source_captions = self::collect_caption_attrs( $source_content );
		$dest_captions   = self::collect_caption_attrs( $dest_content );

		$test->assertSame(
			count( $source_captions ),
			count( $dest_captions ),
			'Dest must preserve every source caption shortcode'
		);

		$dest_closers = preg_match_all(
			'#\[/(?:caption|wp_caption)\]#i',
			$dest_content
		);
		$test->assertSame(
			count( $dest_captions ),
			(int) $dest_closers,
			'Dest caption shortcodes must be balanced (no half-tag leftovers)'
		);

		foreach ( $source_captions as $i => $source_atts ) {
			// Non-id attributes survive verbatim; the id is rewritten to the
			// dest attachment, so its value parity is left to
			// assert_attachment_id_parity() and only its presence is checked.
			$test->assertSame(
				self::without_id( $source_atts ),
				self::without_id( $dest_captions[ $i ] ),
				'Dest caption must preserve its non-id attributes verbatim'
			);
			$test->assertArrayHasKey(
				'id',
				$dest_captions[ $i ],
				'Dest caption must keep its id attribute'
			);
		}
	}

	/**
	 * Asserts gallery/playlist shortcode parity. The importer rewrites the bare
	 * source attachment ids in ids/include/exclude to dest ids and preserves
	 * everything else, so dest must keep the same shortcode count, document
	 * order, and non-id attributes; the id list must be the source ids mapped to
	 * dest, in order; and every dest id must resolve to a local attachment.
	 *
	 * @param string          $source_content       Source post_content.
	 * @param string          $dest_content         Imported dest post_content.
	 * @param array<int, int> $source_id_to_dest_id Source attachment ID => dest ID.
	 * @param TestCase        $test                 Active test case.
	 */
	public static function assert_media_shortcode_parity(
		string $source_content,
		string $dest_content,
		array $source_id_to_dest_id,
		TestCase $test
	): void {
		$source = self::collect_media_shortcodes( $source_content );
		$dest   = self::collect_media_shortcodes( $dest_content );

		$test->assertSame(
			count( $source ),
			count( $dest ),
			'Dest must preserve every source gallery/playlist shortcode'
		);

		foreach ( $source as $i => $shortcode ) {
			$test->assertSame(
				$shortcode['tag'],
				$dest[ $i ]['tag'],
				'Dest shortcode must keep its tag and document order'
			);
			// Non-id attributes survive verbatim; the id CSVs are rewritten and
			// checked below.
			$test->assertSame(
				self::without_media_ids( $shortcode['atts'] ),
				self::without_media_ids( $dest[ $i ]['atts'] ),
				'Dest shortcode must preserve its non-id attributes verbatim'
			);
		}

		$expected = array_map(
			static fn ( int $id ): int => $source_id_to_dest_id[ $id ] ?? $id,
			self::collect_media_shortcode_ids( $source_content )
		);
		$dest_ids = self::collect_media_shortcode_ids( $dest_content );

		// In order, not as a multiset: galleries render in id order, so a
		// reorder is a real regression.
		$test->assertSame(
			$expected,
			$dest_ids,
			'Dest shortcode ids must be the source ids rewritten to dest, in order'
		);

		// Every dest id resolves to a local attachment, so a source id left
		// behind (high, non-local) or any garbage id is caught here too.
		foreach ( $dest_ids as $dest_id ) {
			$test->assertSame(
				'attachment',
				get_post_type( $dest_id ),
				"Dest shortcode id {$dest_id} must be a local attachment"
			);
		}
	}

	/**
	 * Collects non-empty block names from a parse_blocks() tree, recursing into
	 * inner blocks. Freeform (null-name) blocks are skipped.
	 *
	 * @param array<array-key, array<string, mixed>> $blocks Parsed block tree.
	 * @return list<string>
	 */
	private static function collect_block_names( array $blocks ): array {
		$names = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && '' !== $name ) {
				$names[] = $name;
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) && array() !== $inner ) {
				$names = array_merge( $names, self::collect_block_names( $inner ) );
			}
		}

		return $names;
	}

	/**
	 * Reverse-asserts no freeform block carries a residual block-comment
	 * delimiter. The parser consumes well-formed delimiters, so a leftover only
	 * survives as literal text in a freeform block — the signature of an
	 * orphaned or malformed-attribute block.
	 *
	 * @param array<array-key, array<string, mixed>> $blocks Parsed block tree.
	 * @param TestCase                               $test   Active test case.
	 */
	private static function assert_no_residual_block_delimiters(
		array $blocks,
		TestCase $test
	): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				$html = (string) ( $block['innerHTML'] ?? '' );
				$test->assertStringNotContainsString(
					'<!-- wp:',
					$html,
					'Freeform block must not contain an orphaned block opener'
				);
				$test->assertStringNotContainsString(
					'<!-- /wp:',
					$html,
					'Freeform block must not contain an orphaned block closer'
				);
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) && array() !== $inner ) {
				self::assert_no_residual_block_delimiters( $inner, $test );
			}
		}
	}

	/**
	 * Returns each caption-family shortcode's parsed attributes in document
	 * order.
	 *
	 * @param string $content Content to scan.
	 * @return list<array<string, string>>
	 */
	private static function collect_caption_attrs( string $content ): array {
		if ( ! preg_match_all(
			'#\[(?:caption|wp_caption)\b([^\]]*)\]#i',
			$content,
			$matches
		) ) {
			return array();
		}

		$captions = array();
		foreach ( $matches[1] as $attr_string ) {
			$atts       = shortcode_parse_atts( trim( $attr_string ) );
			$captions[] = is_array( $atts ) ? $atts : array();
		}

		return $captions;
	}

	/**
	 * Returns each live [gallery]/[playlist] shortcode's tag and parsed
	 * attributes in document order, skipping escaped [[gallery]] literals as the
	 * rewriter does.
	 *
	 * @param string $content Content to scan.
	 * @return list<array{tag: string, atts: array<string, string>}>
	 */
	private static function collect_media_shortcodes( string $content ): array {
		$count = preg_match_all(
			'/' . get_shortcode_regex( array( 'gallery', 'playlist' ) ) . '/s',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		if ( ! is_int( $count ) || 0 === $count ) {
			return array();
		}

		$shortcodes = array();
		foreach ( $matches as $match ) {
			// Escaped literal ([[gallery ...]]): not a live shortcode.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}

			$atts         = shortcode_parse_atts( trim( $match[3] ) );
			$shortcodes[] = array(
				'tag'  => $match[2],
				'atts' => is_array( $atts ) ? $atts : array(),
			);
		}

		return $shortcodes;
	}

	/**
	 * Returns a shortcode-attribute array with the id entry removed.
	 *
	 * @param array<string, string> $atts Parsed shortcode attributes.
	 * @return array<string, string>
	 */
	private static function without_id( array $atts ): array {
		unset( $atts['id'] );

		return $atts;
	}

	/**
	 * Returns a shortcode-attribute array with the id-bearing entries removed.
	 *
	 * @param array<string, string> $atts Parsed shortcode attributes.
	 * @return array<string, string>
	 */
	private static function without_media_ids( array $atts ): array {
		foreach ( self::MEDIA_SHORTCODE_ID_ATTRS as $attr ) {
			unset( $atts[ $attr ] );
		}

		return $atts;
	}

	/**
	 * Walks content and returns per-(tag, attribute) attribute-value lists,
	 * keyed "TAG.attr".
	 *
	 * @param string $content Content to scan.
	 * @return array<string, list<string>>
	 */
	private static function collect_url_attrs( string $content ): array {
		$result = array();

		if ( '' === $content ) {
			return $result;
		}

		$processor = new WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( ! is_string( $tag ) || ! isset( self::URL_ATTRS_BY_TAG[ $tag ] ) ) {
				continue;
			}

			foreach ( self::URL_ATTRS_BY_TAG[ $tag ] as $attr ) {
				$value = $processor->get_attribute( $attr );

				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				$result[ "{$tag}.{$attr}" ][] = $value;
			}
		}

		return $result;
	}

	/**
	 * Returns the alt value of every <img> in document order. Empty-string
	 * alts are kept (a decorative image is a meaningful value); an img whose
	 * alt is missing or valueless is skipped.
	 *
	 * @param string $content Content to scan.
	 * @return list<string>
	 */
	private static function collect_img_alts( string $content ): array {
		$alts = array();

		if ( '' === $content ) {
			return $alts;
		}

		$processor = new WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag() ) {
			if ( 'IMG' !== $processor->get_tag() ) {
				continue;
			}

			$alt = $processor->get_attribute( 'alt' );
			if ( is_string( $alt ) ) {
				$alts[] = $alt;
			}
		}

		return $alts;
	}

	/**
	 * Collects core/embed (and legacy core-embed/*) url attributes from a
	 * parse_blocks() tree, recursing into inner blocks.
	 *
	 * @param array<array-key, array<string, mixed>> $blocks Parsed block tree.
	 * @return list<string>
	 */
	private static function collect_embed_urls( array $blocks ): array {
		$urls = array();

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			$url  = $block['attrs']['url'] ?? null;

			if (
				is_string( $name )
				&& ( 'core/embed' === $name
					|| str_starts_with( $name, 'core-embed/' ) )
				&& is_string( $url ) && '' !== $url
			) {
				$urls[] = $url;
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) && array() !== $inner ) {
				$urls = array_merge( $urls, self::collect_embed_urls( $inner ) );
			}
		}

		return $urls;
	}

	/**
	 * Returns IDs from Gutenberg block-comment JSON attrs (e.g. {"id":42}).
	 * Uses a regex because WP_HTML_Tag_Processor skips comments.
	 *
	 * @param string $content Content to scan.
	 * @return list<int>
	 */
	private static function collect_block_attr_ids( string $content ): array {
		preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Returns the integer IDs found in wp-image-N classnames.
	 *
	 * @param string $content Content to scan.
	 * @return list<int>
	 */
	private static function collect_wp_image_class_ids( string $content ): array {
		preg_match_all( '/wp-image-(\d+)/', $content, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Returns the integer IDs found in caption-family shortcode id
	 * attributes ([caption id="attachment_N"], [wp_caption id=...]).
	 *
	 * @param string $content Content to scan.
	 * @return list<int>
	 */
	private static function collect_caption_shortcode_ids( string $content ): array {
		preg_match_all(
			'/\[(?:caption|wp_caption)\b[^\]]*(?<![\w-])id\s*=\s*["\']attachment_(\d+)["\']/i',
			$content,
			$matches
		);

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Returns the attachment ids found in the ids/include/exclude CSVs of every
	 * live gallery/playlist shortcode, in document order. Non-numeric tokens are
	 * skipped.
	 *
	 * @param string $content Content to scan.
	 * @return list<int>
	 */
	private static function collect_media_shortcode_ids( string $content ): array {
		$ids = array();

		foreach ( self::collect_media_shortcodes( $content ) as $shortcode ) {
			foreach ( self::MEDIA_SHORTCODE_ID_ATTRS as $attr ) {
				if ( ! isset( $shortcode['atts'][ $attr ] ) ) {
					continue;
				}

				foreach ( explode( ',', $shortcode['atts'][ $attr ] ) as $token ) {
					if ( 1 === preg_match( '/^\s*(\d+)\s*$/', $token, $parts ) ) {
						$ids[] = (int) $parts[1];
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * Asserts that mapping source IDs through the sideload map yields the
	 * dest list as a multiset. IDs absent from the map round-trip unchanged.
	 *
	 * @param array<int>      $source_ids        Source IDs in scan order.
	 * @param array<int>      $dest_ids          Dest IDs in scan order.
	 * @param array<int, int> $source_id_to_dest Sideload map.
	 * @param string          $description       Human-readable bucket label.
	 * @param TestCase        $test              Active test case.
	 */
	private static function assert_id_multiset_parity(
		array $source_ids,
		array $dest_ids,
		array $source_id_to_dest,
		string $description,
		TestCase $test
	): void {
		$expected = array_map(
			static fn ( int $id ): int => $source_id_to_dest[ $id ] ?? $id,
			$source_ids
		);

		sort( $expected );
		sort( $dest_ids );

		$test->assertSame(
			$expected,
			$dest_ids,
			"Dest content must carry the rewritten ID multiset for {$description}"
		);
	}
}
