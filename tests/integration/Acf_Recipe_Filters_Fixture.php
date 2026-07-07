<?php
/**
 * Reference implementation of the ACF/SCF meta extension recipe.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Registers the destination-side recipe from docs/extending/acf-scf.md.
 *
 * The merge_acf_into_meta body must stay identical to the Step 1 snippet in
 * that document so the integration test exercises the documented recipe
 * rather than an ad hoc reimplementation.
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
	 * Merges scalar values from the REST acf object into the meta array.
	 *
	 * @param array $meta Meta from the REST meta object.
	 * @param array $data Full decoded REST response for the post.
	 * @return array Meta with scalar acf values merged in.
	 */
	public static function merge_acf_into_meta( array $meta, array $data ): array {
		$acf = isset( $data['acf'] ) && is_array( $data['acf'] ) ? $data['acf'] : array();

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
				$meta[ $key ] = $value;
			}
		}

		return $meta;
	}
}
