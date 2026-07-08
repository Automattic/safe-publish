<?php
/**
 * Reference implementation of the ACF/SCF complex-field recipe.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Registers the stash-and-replay pattern from the "Complex fields" section of
 * docs/extending/acf-scf.md.
 *
 * The stash_acf_payload body mirrors that document. replay_acf performs only
 * the stash cleanup, since the document additionally delegates storage to ACF's
 * update_field(), which needs ACF on the destination and is out of scope for
 * this fixture.
 */
final class Acf_Complex_Recipe_Fixture {

	/**
	 * Meta key the acf payload is stashed under between fetch and save.
	 */
	public const STASH_KEY = '_acf_import_payload';

	/**
	 * Adds the stash filter and the replay actions.
	 */
	public static function register(): void {
		add_filter(
			'safe_publish_source_post_meta',
			array( self::class, 'stash_acf_payload' ),
			10,
			2
		);
		add_action(
			'added_post_meta',
			array( self::class, 'replay_acf' ),
			10,
			4
		);
		add_action(
			'updated_post_meta',
			array( self::class, 'replay_acf' ),
			10,
			4
		);
	}

	/**
	 * Stashes the raw acf payload as a scalar so it survives to the saved post.
	 *
	 * @param array $meta Meta from the REST meta object.
	 * @param array $data Full decoded REST response for the post.
	 * @return array Meta with the acf payload stashed in.
	 */
	public static function stash_acf_payload( array $meta, array $data ): array {
		if ( isset( $data['acf'] ) && is_array( $data['acf'] ) ) {
			$meta[ self::STASH_KEY ] = wp_json_encode( $data['acf'] );
		}

		return $meta;
	}

	/**
	 * Clears the stash once it lands on the saved post.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $post_id    Post the meta was written to.
	 * @param string $meta_key   Meta key written.
	 * @param mixed  $meta_value Meta value written (unused).
	 */
	public static function replay_acf(
		int $meta_id,
		int $post_id,
		string $meta_key,
		mixed $meta_value
	): void {
		unset( $meta_id, $meta_value );

		if ( self::STASH_KEY !== $meta_key ) {
			return;
		}

		delete_post_meta( $post_id, self::STASH_KEY );
	}
}
