<?php
/**
 * Reference implementation of the ACF/SCF complex-field recipe
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Registers the stash-and-replay pattern from the "Complex fields" section of
 * docs/extending/acf-scf.md.
 *
 * The stash_acf_payload and replay_acf bodies mirror that document, except
 * that replay_acf records its update_field() calls in $updates instead of
 * calling ACF, which this harness lacks, so it skips the function_exists()
 * check.
 */
final class Acf_Complex_Recipe_Fixture {

	/**
	 * Meta key the acf payload is stashed under between fetch and save.
	 */
	public const STASH_KEY = '_acf_import_payload';

	/**
	 * Field key of the repeater the replay writes.
	 */
	public const FIELD_KEY = 'field_650f1a2b3c4d5';

	/**
	 * The update_field() calls replay_acf made, as selector, value, and post
	 * ID.
	 *
	 * @var list<array{string, mixed, int}>
	 */
	public static array $updates = array();

	/**
	 * Adds the stash filter and the replay actions.
	 */
	public static function register(): void {
		add_filter(
			'safe_publish_source_post_meta',
			array( self::class, 'stash_acf_payload' ),
			PHP_INT_MAX,
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
	 * @return array Meta with the acf payload stashed last.
	 */
	public static function stash_acf_payload( array $meta, array $data ): array {
		$acf = is_array( $data['acf'] ?? null ) ? $data['acf'] : array();

		// Write the stash last, so the replay runs after the source's meta.
		unset( $meta[ self::STASH_KEY ] );
		$meta[ self::STASH_KEY ] = wp_json_encode( $acf );

		return $meta;
	}

	/**
	 * Clears the stash once it lands on the saved post, then replays the
	 * expected fields.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $post_id    Post the meta was written to.
	 * @param string $meta_key   Meta key written.
	 * @param mixed  $meta_value Meta value written.
	 */
	public static function replay_acf(
		int $meta_id,
		int $post_id,
		string $meta_key,
		mixed $meta_value
	): void {
		unset( $meta_id );

		if ( self::STASH_KEY !== $meta_key ) {
			return;
		}

		// Always clear the stash, even when ACF is unavailable to consume it.
		delete_post_meta( $post_id, self::STASH_KEY );

		$acf = json_decode( (string) $meta_value, true );

		// Replay only the expected fields, each sanitized for its shape. This
		// example expects a slides repeater with a caption text subfield.
		$rows = array();

		if ( isset( $acf['slides'] ) && is_array( $acf['slides'] ) ) {
			foreach ( $acf['slides'] as $row ) {
				$caption = is_array( $row ) ? ( $row['caption'] ?? '' ) : '';
				$caption = is_scalar( $caption ) ? (string) $caption : '';

				$rows[] = array(
					'caption' => sanitize_text_field( $caption ),
				);
			}
		}

		// Write the field on every import, so ACF reads only sanitized rows.
		// The field key pins its definition, whatever the post's meta holds.
		self::update_field( self::FIELD_KEY, $rows, $post_id );
	}

	/**
	 * Records an update_field() call in place of ACF.
	 *
	 * @param string $selector Field name or key.
	 * @param mixed  $value    Value to store.
	 * @param int    $post_id  Post to store it on.
	 */
	private static function update_field(
		string $selector,
		mixed $value,
		int $post_id
	): void {
		self::$updates[] = array( $selector, $value, $post_id );
	}
}
