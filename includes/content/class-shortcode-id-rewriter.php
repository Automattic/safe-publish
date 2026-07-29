<?php
/**
 * Shortcode ID Rewriter class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Content;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites source attachment IDs referenced inside shortcode attributes.
 *
 * The URL rewrite pipeline handles `<img src>` but not shortcode attrs, so
 * source IDs would otherwise leak through to dest and render against the wrong
 * (or no) attachment.
 *
 * Two families, resolved differently:
 *  - Caption (`[caption]`, `[wp_caption]`): the embedded `<img>` gives an
 *    `attachment_url_to_postid()` lookup target.
 *  - Gallery (`[gallery ids=...]`, `[playlist ids=...]`): bare source IDs with
 *    no embedded URL, resolved through an injected source-ID => dest-ID
 *    callable that sideloads the referenced media.
 */
class Shortcode_ID_Rewriter {

	/**
	 * URL => attachment ID lookup. Defaults to WordPress' built-in
	 * attachment_url_to_postid(); tests inject a stub.
	 *
	 * @var callable
	 */
	private $url_to_id_lookup;

	/**
	 * Constructor.
	 *
	 * @param callable|null $url_to_id_lookup URL => attachment ID resolver,
	 *                                        or null for the WP default.
	 */
	public function __construct( ?callable $url_to_id_lookup = null ) {
		$this->url_to_id_lookup = $url_to_id_lookup ?? 'attachment_url_to_postid';
	}

	/**
	 * Rewrites every `[caption id="attachment_N"]<img ...>` so N becomes the
	 * dest attachment ID resolved from the embedded img's src. Captions
	 * whose img doesn't resolve (third-party, sideload failure,
	 * intermediate-size URL) are left unchanged.
	 *
	 * Assumes URL rewriting already ran — the img src is the dest URL by
	 * the time this regex sees it.
	 *
	 * @param string $content Post content with caption shortcodes.
	 * @return string Content with caption IDs rewritten.
	 */
	public function rewrite_caption_ids( string $content ): string {
		if ( '' === $content
			|| ( false === stripos( $content, '[caption' )
				&& false === stripos( $content, '[wp_caption' ) )
		) {
			return $content;
		}

		$result = preg_replace_callback(
			'#\[(caption|wp_caption)\b([^\]]*)\](.*?)\[/\1\]#is',
			array( $this, 'rewrite_caption_match' ),
			$content
		);

		return is_string( $result ) ? $result : $content;
	}

	/**
	 * Handler for a single caption-shortcode regex match.
	 *
	 * @param array<int, string> $matches Regex captures: [full, tag, attrs, body].
	 * @return string Rewritten match, or the original if no rewrite applies.
	 */
	private function rewrite_caption_match( array $matches ): string {
		$tag   = $matches[1];
		$attrs = $matches[2];
		$body  = $matches[3];

		if ( 1 !== preg_match(
			'/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1/i',
			$body,
			$img_match
		) ) {
			return $matches[0];
		}

		$attachment_id = (int) call_user_func(
			$this->url_to_id_lookup,
			$img_match[2]
		);
		if ( $attachment_id <= 0 ) {
			return $matches[0];
		}

		$new_attrs = preg_replace(
			'/(?<![\w-])(id\s*=\s*["\']attachment_)\d+(["\'])/i',
			'${1}' . $attachment_id . '${2}',
			$attrs,
			1
		);

		if ( ! is_string( $new_attrs ) || $new_attrs === $attrs ) {
			return $matches[0];
		}

		return '[' . $tag . $new_attrs . ']' . $body . '[/' . $tag . ']';
	}

	/**
	 * Rewrites the source attachment IDs in `[gallery]` and `[playlist]`
	 * shortcodes to their destination IDs, resolving each through $resolver.
	 *
	 * Only the CSV values of the `ids`, `include`, and `exclude` attributes are
	 * touched; order, whitespace, quoting, and every other byte are preserved.
	 * A token the resolver cannot map (it returns 0) is left in place. Each
	 * distinct source ID is resolved once per run.
	 *
	 * @param string   $content  Post content with gallery/playlist shortcodes.
	 * @param callable $resolver Source attachment ID => dest attachment ID, or 0
	 *                           when unresolved.
	 * @return string Content with the shortcode IDs rewritten.
	 */
	public function rewrite_media_shortcode_ids(
		string $content,
		callable $resolver
	): string {
		if ( '' === $content
			|| ( false === stripos( $content, '[gallery' )
				&& false === stripos( $content, '[playlist' ) )
		) {
			return $content;
		}

		$memo = array();

		$result = preg_replace_callback(
			'/' . get_shortcode_regex( array( 'gallery', 'playlist' ) ) . '/s',
			function ( array $matches ) use ( $resolver, &$memo ): string {
				// Escaped shortcode ([[gallery ...]]): leave the literal alone.
				if ( '[' === $matches[1] && ']' === $matches[6] ) {
					return $matches[0];
				}

				$attrs     = $matches[3];
				$new_attrs = $this->rewrite_id_attr_csvs(
					$attrs,
					$resolver,
					$memo
				);

				if ( $new_attrs === $attrs ) {
					return $matches[0];
				}

				// Splice the rewritten attributes back in at their known offset
				// (after the opening bracket, escape char, and tag name),
				// leaving the rest of the match byte-for-byte.
				$attrs_offset = 1 + strlen( $matches[1] ) + strlen( $matches[2] );

				return substr_replace(
					$matches[0],
					$new_attrs,
					$attrs_offset,
					strlen( $attrs )
				);
			},
			$content
		);

		return is_string( $result ) ? $result : $content;
	}

	/**
	 * Rewrites the CSV values of the id-bearing shortcode attributes (`ids`,
	 * `include`, `exclude`) within a shortcode's attribute string, preserving
	 * the attribute name, separator, and quoting.
	 *
	 * @param string          $attrs    Shortcode attribute string.
	 * @param callable        $resolver Source ID => dest ID resolver.
	 * @param array<int, int> $memo     Source ID => resolved dest ID cache.
	 * @return string Attribute string with the id CSVs rewritten.
	 */
	private function rewrite_id_attr_csvs(
		string $attrs,
		callable $resolver,
		array &$memo
	): string {
		$result = preg_replace_callback(
			'/(?<![\w-])(ids|include|exclude)(\s*=\s*)(["\'])([^"\']*)\3/i',
			function ( array $matches ) use ( $resolver, &$memo ): string {
				$csv = $this->rewrite_csv_ids( $matches[4], $resolver, $memo );

				return $matches[1] . $matches[2] . $matches[3] . $csv . $matches[3];
			},
			$attrs
		);

		return is_string( $result ) ? $result : $attrs;
	}

	/**
	 * Rewrites each purely numeric token in a comma-separated ID list to its
	 * resolved destination ID, leaving separators, whitespace, and any
	 * non-numeric or unresolved token untouched.
	 *
	 * @param string          $csv      Comma-separated attachment ID list.
	 * @param callable        $resolver Source ID => dest ID resolver.
	 * @param array<int, int> $memo     Source ID => resolved dest ID cache.
	 * @return string The rewritten list.
	 */
	private function rewrite_csv_ids(
		string $csv,
		callable $resolver,
		array &$memo
	): string {
		$tokens = explode( ',', $csv );

		foreach ( $tokens as $index => $token ) {
			if ( 1 !== preg_match( '/^(\s*)(\d+)(\s*)$/', $token, $parts ) ) {
				continue;
			}

			$source_id = (int) $parts[2];

			if ( ! isset( $memo[ $source_id ] ) ) {
				$memo[ $source_id ] = (int) call_user_func( $resolver, $source_id );
			}

			if ( $memo[ $source_id ] > 0 ) {
				$tokens[ $index ] = $parts[1] . $memo[ $source_id ] . $parts[3];
			}
		}

		return implode( ',', $tokens );
	}
}
