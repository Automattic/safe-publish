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
 * assertions lock documented gaps and safety invariants.
 *
 * Out of scope: gallery `attrs.ids` and `data-id` (not seeded today),
 * block-structural validity (parse_blocks round-trip).
 */
final class Content_Parity_Comparator {

	/**
	 * URL-bearing tag attributes checked for rewrite parity, keyed by the
	 * uppercase tag name WP_HTML_Tag_Processor::get_tag() returns. Block
	 * comments aren't scanned (the tag processor skips them), so the
	 * `attrs.url` the importer adds to image blocks doesn't show up here.
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
	 * Asserts that attachment-ID references (Gutenberg block-comment
	 * `{"id":N}`, `wp-image-N` classnames, and caption-shortcode
	 * `attachment_N` IDs) rewrite to dest IDs as multisets, so leaks and
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
	 * Returns IDs from Gutenberg block-comment JSON attrs (e.g. `{"id":42}`).
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
	 * Returns the integer IDs found in `wp-image-N` classnames.
	 *
	 * @param string $content Content to scan.
	 * @return list<int>
	 */
	private static function collect_wp_image_class_ids( string $content ): array {
		preg_match_all( '/wp-image-(\d+)/', $content, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Returns the integer IDs found in caption-family shortcode `id`
	 * attributes (`[caption id="attachment_N"]`, `[wp_caption id=...]`).
	 *
	 * @param string $content Content to scan.
	 * @return list<int>
	 */
	private static function collect_caption_shortcode_ids( string $content ): array {
		preg_match_all(
			'/\[(?:caption|wp_caption)\b[^\]]*\bid\s*=\s*["\']attachment_(\d+)["\']/i',
			$content,
			$matches
		);

		return array_map( 'intval', $matches[1] );
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
