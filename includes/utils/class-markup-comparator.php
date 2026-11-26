<?php
/**
 * Markup Comparator utility class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

use WP_HTML_Tag_Processor;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares markup by the blocks and HTML it holds, not by how it is written.
 *
 * Save-time filters such as kses rewrite the markup they keep: They reorder
 * attributes, requote values, respace inline styles, drop void-element
 * slashes, and change how characters are referenced.
 */
final class Markup_Comparator {

	/**
	 * Tags that browsers parse differently from the tag processor: SVG and
	 * MathML hold foreign content, NOSCRIPT holds text where scripts run, and
	 * PLAINTEXT makes the rest of the markup text.
	 *
	 * @var string[]
	 */
	private const DIVERGENT_TAGS = array(
		'MATH',
		'NOSCRIPT',
		'PLAINTEXT',
		'SVG',
	);

	/**
	 * Checks whether two markup strings hold the same blocks and HTML.
	 *
	 * @param string $a One markup string.
	 * @param string $b The other markup string.
	 * @return bool Whether the markup is equivalent.
	 */
	public static function equivalent( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}

		$key = self::key( $a );

		return null !== $key && self::key( $b ) === $key;
	}

	/**
	 * Builds a key that only equivalent markup shares.
	 *
	 * Blocks compare by name and parsed attributes, and HTML token by token,
	 * with attributes in name order, style declarations trimmed, and character
	 * references decoded. Tokens are hashed with SHA-256 as they are read, so
	 * memory stays flat and distinct markup cannot be made to share a key.
	 *
	 * @param string $markup Markup to read.
	 * @return string|null Hex digest, or null when the markup ends inside a
	 *                     token or holds one of the DIVERGENT_TAGS.
	 */
	public static function key( string $markup ): ?string {
		if ( has_blocks( $markup ) ) {
			$markup = serialize_blocks( parse_blocks( $markup ) );
		}

		$processor = new WP_HTML_Tag_Processor( $markup );
		$context   = hash_init( 'sha256' );

		while ( $processor->next_token() ) {
			$tag = $processor->get_tag();

			if ( in_array( $tag, self::DIVERGENT_TAGS, true ) ) {
				return null;
			}

			$attributes = array();
			$names      = $processor->get_attribute_names_with_prefix( '' );
			foreach ( $names ?? array() as $name ) {
				$value               = $processor->get_attribute( $name );
				$attributes[ $name ] = 'style' === $name && is_string( $value )
					? self::join_declarations( $value )
					: $value;
			}
			ksort( $attributes, SORT_STRING );

			$token = wp_json_encode(
				array(
					$processor->get_token_type(),
					$tag,
					$processor->is_tag_closer(),
					$attributes,
					$processor->get_full_comment_text(),
					$processor->get_modifiable_text(),
				)
			);

			if ( false === $token ) {
				return null;
			}

			hash_update( $context, $token );
		}

		if ( $processor->paused_at_incomplete_token() ) {
			return null;
		}

		return hash_final( $context );
	}

	/**
	 * Joins style declarations without the spacing and empty declarations CSS
	 * ignores, as kses rejoins the declarations it keeps.
	 *
	 * @param string $style Style attribute value.
	 * @return string Declarations joined by semicolons.
	 */
	private static function join_declarations( string $style ): string {
		$declarations = array_map( 'trim', explode( ';', $style ) );

		return implode( ';', array_diff( $declarations, array( '' ) ) );
	}
}
