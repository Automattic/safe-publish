<?php
/**
 * Meta Terms Manager import key policy test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\Meta_Terms_Manager;

/**
 * Tests the key policy Meta_Terms_Manager applies to imported post meta.
 */
class MetaTermsManagerTest extends TestCase {

	/**
	 * Every core key the policy reserves, as the docs and the PR describe it.
	 *
	 * Kept here rather than read from the class so a key added to the constant
	 * without a documentation change fails a test.
	 *
	 * @var string[]
	 */
	private const DOCUMENTED_RESERVED_CORE_KEYS = array(
		'_edit_last',
		'_edit_lock',
		'_encloseme',
		'_pingme',
		'_thumbnail_id',
		'_wp_attached_file',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_metadata',
		'_wp_desired_post_slug',
		'_wp_old_date',
		'_wp_old_slug',
		'_wp_trash_meta_comments_status',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
	);

	/**
	 * Verifies that migratable keys, protected ones included, stay importable.
	 */
	public function test_importable_meta_keys_keeps_migratable_keys(): void {
		// ARRANGE: Keys an import is meant to carry across, among them the
		// protected keys the Yoast and ACF recipes depend on.
		$meta = array(
			'custom_field'             => 'value',
			'_yoast_wpseo_metadesc'    => 'A description.',
			'_acf_import_payload'      => '{}',
			'_wp_page_template'        => 'template-wide.php',
			'_wp_attachment_image_alt' => 'Alt text',
		);

		// ACT: Split the payload by the import key policy.
		$importable = Meta_Terms_Manager::importable_meta_keys( $meta );

		// ASSERT: Every key is written, in payload order, and none refused.
		$this->assertSame( array_keys( $meta ), $importable );
		$this->assertSame(
			array(),
			Meta_Terms_Manager::refused_meta_keys( $meta )
		);
	}

	/**
	 * Verifies that the plugin's own namespace is refused, so a source cannot
	 * repoint the identity meta an imported post is found by.
	 */
	public function test_refused_meta_keys_covers_the_plugin_namespace(): void {
		// ARRANGE: A payload naming the plugin's public and private keys.
		$meta = array(
			'safe_publish_source_post_id'       => 4321,
			'safe_publish_source_site_url'      => 'https://example.com',
			'_safe_publish_source_author_email' => 'author@example.com',
			'custom_field'                      => 'value',
		);

		// ACT: Split the payload by the import key policy.
		$refused = Meta_Terms_Manager::refused_meta_keys( $meta );

		// ASSERT: Only the plugin's own keys are refused.
		$this->assertSame(
			array(
				'safe_publish_source_post_id',
				'safe_publish_source_site_url',
				'_safe_publish_source_author_email',
			),
			$refused
		);
		$this->assertSame(
			array( 'custom_field' ),
			Meta_Terms_Manager::importable_meta_keys( $meta )
		);
	}

	/**
	 * Verifies that core keys holding destination state or source-side
	 * pointers are refused by default.
	 *
	 * @dataProvider reserved_core_key_provider
	 *
	 * @param string $key Reserved core meta key.
	 */
	public function test_refused_meta_keys_covers_reserved_core_keys(
		string $key
	): void {
		// ARRANGE: The reserved key alongside a migratable one.
		$meta = array(
			$key           => 'source-value',
			'custom_field' => 'value',
		);

		// ACT: Split the payload by the import key policy.
		$refused = Meta_Terms_Manager::refused_meta_keys( $meta );

		// ASSERT: The reserved key is refused and the other still written.
		$this->assertSame( array( $key ), $refused );
		$this->assertSame(
			array( 'custom_field' ),
			Meta_Terms_Manager::importable_meta_keys( $meta )
		);
	}

	/**
	 * Data provider naming every reserved core key the documentation lists.
	 *
	 * @return array<string, array{string}> One case per reserved core key.
	 */
	public static function reserved_core_key_provider(): array {
		$cases = array();

		foreach ( self::DOCUMENTED_RESERVED_CORE_KEYS as $key ) {
			$cases[ $key ] = array( $key );
		}

		return $cases;
	}

	/**
	 * Verifies that the reserved core list is exactly the documented one, so
	 * the class and the hooks documentation cannot drift apart.
	 */
	public function test_reserved_core_keys_match_the_documented_list(): void {
		// ARRANGE: Every documented reserved key, plus a migratable control.
		$meta                 = array_fill_keys(
			self::DOCUMENTED_RESERVED_CORE_KEYS,
			'source-value'
		);
		$meta['custom_field'] = 'value';

		// ACT: Split the payload by the import key policy.
		$refused    = Meta_Terms_Manager::refused_meta_keys( $meta );
		$importable = Meta_Terms_Manager::importable_meta_keys( $meta );

		// ASSERT: Every documented key is refused, and only the control is
		// written.
		$this->assertSame( self::DOCUMENTED_RESERVED_CORE_KEYS, $refused );
		$this->assertSame( array( 'custom_field' ), $importable );
	}

	/**
	 * Verifies that the policy tests the sanitized key, which is the key the
	 * write path stores, so a raw key that only becomes reserved once
	 * sanitized is still refused.
	 */
	public function test_refused_meta_keys_matches_the_sanitized_key(): void {
		// ARRANGE: A raw key sanitize_text_field() collapses onto a reserved
		// one, sent alongside the plain reserved key.
		$meta = array(
			'_thumb<span>nail_id' => 11,
			'_thumbnail_id'       => 12,
		);

		// ACT: Split the payload by the import key policy.
		$refused = Meta_Terms_Manager::refused_meta_keys( $meta );

		// ASSERT: Both collapse to the one refused key, and nothing is left
		// importable.
		$this->assertSame( array( '_thumbnail_id' ), $refused );
		$this->assertSame(
			array(),
			Meta_Terms_Manager::importable_meta_keys( $meta )
		);
	}

	/**
	 * Verifies that an empty payload produces no keys on either side.
	 */
	public function test_meta_key_policy_handles_an_empty_payload(): void {
		// ARRANGE: A post whose source sent no meta at all.
		$meta = array();

		// ACT: Split the empty payload.
		$importable = Meta_Terms_Manager::importable_meta_keys( $meta );
		$refused    = Meta_Terms_Manager::refused_meta_keys( $meta );

		// ASSERT: Neither side names a key.
		$this->assertSame( array(), $importable );
		$this->assertSame( array(), $refused );
	}
}
