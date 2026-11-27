<?php
/**
 * Reference implementation of the ACF/SCF meta extension recipe
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Registers the destination-side recipe from docs/extending/acf-scf.md.
 *
 * The merge_acf_into_meta body must stay identical to the Step 1 snippet in
 * that document, and allowlist_acf_into_meta to its allowlist variant, so the
 * integration tests exercise the documented recipe rather than an ad hoc
 * reimplementation. The hooks.md safe_publish_source_post_meta example is the
 * allowlist's single-field form.
 */
final class Acf_Recipe_Filters_Fixture {

	/**
	 * Adds the recipe's meta filter.
	 */
	public static function register(): void {
		add_filter(
			'safe_publish_source_post_meta',
			array( self::class, 'merge_acf_into_meta' ),
			10,
			2
		);
	}

	/**
	 * Adds the recipe's meta filter with the allowlist in place of its loop.
	 */
	public static function register_allowlist(): void {
		add_filter(
			'safe_publish_source_post_meta',
			array( self::class, 'allowlist_acf_into_meta' ),
			10,
			2
		);
	}

	/**
	 * Merges scalar values from the REST acf object into the meta array.
	 *
	 * @param array $meta Meta from the REST meta object.
	 * @param array $data Full decoded REST response for the post.
	 * @return array Meta with scalar acf values merged in.
	 */
	public static function merge_acf_into_meta( array $meta, array $data ): array {
		$acf = is_array( $data['acf'] ?? null ) ? $data['acf'] : array();

		foreach ( $acf as $key => $value ) {
			$key = (string) $key;

			// Skip protected and Safe Publish-reserved keys.
			if (
				str_starts_with( $key, '_' ) ||
				str_starts_with( $key, 'safe_publish_' )
			) {
				continue;
			}

			// Scalars map straight to meta. Repeater, group, and
			// flexible-content fields need update_field() instead.
			if ( is_scalar( $value ) ) {
				// Write the key last, so it replaces the source's value.
				unset( $meta[ $key ] );
				$meta[ $key ] = $value;
			}
		}

		return $meta;
	}

	/**
	 * Writes each allowlisted field into the meta array, sanitized.
	 *
	 * @param array $meta Meta from the REST meta object.
	 * @param array $data Full decoded REST response for the post.
	 * @return array Meta with each allowlisted field written last.
	 */
	public static function allowlist_acf_into_meta(
		array $meta,
		array $data
	): array {
		$acf = is_array( $data['acf'] ?? null ) ? $data['acf'] : array();

		foreach ( array( 'hero_title', 'subtitle' ) as $field ) {
			$value = $acf[ $field ] ?? $meta[ $field ] ?? '';
			$value = is_scalar( $value )
				? sanitize_text_field( (string) $value )
				: '';

			// Write the field last, so it replaces the source's value.
			unset( $meta[ $field ] );
			$meta[ $field ] = $value;
		}

		return $meta;
	}
}
