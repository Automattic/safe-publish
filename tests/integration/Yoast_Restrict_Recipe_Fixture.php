<?php
/**
 * Reference implementation of the Yoast SEO restrict recipe
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Registers the destination-side filter from the "Optional: restrict to a
 * known set on the destination" section of docs/extending/yoast.md.
 *
 * The restrict_yoast_meta body must stay identical to that snippet, so the
 * integration tests exercise the documented recipe.
 */
final class Yoast_Restrict_Recipe_Fixture {

	/**
	 * Adds the recipe's meta filter.
	 */
	public static function register(): void {
		add_filter(
			'safe_publish_source_post_meta',
			array( self::class, 'restrict_yoast_meta' ),
			PHP_INT_MAX
		);
	}

	/**
	 * Drops every Yoast SEO key outside the allowlist from the meta array.
	 *
	 * @param array $meta Meta from the REST meta object.
	 * @return array Meta without the excluded Yoast SEO keys.
	 */
	public static function restrict_yoast_meta( array $meta ): array {
		$allowed = array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
		);

		foreach ( array_keys( $meta ) as $key ) {
			// Compare the key as the import stores it, ignoring case.
			$stored = strtolower( wp_unslash( sanitize_text_field( $key ) ) );

			if (
				str_starts_with( $stored, '_yoast_wpseo_' ) &&
				! in_array( $stored, $allowed, true )
			) {
				unset( $meta[ $key ] );
			}
		}

		return $meta;
	}
}
