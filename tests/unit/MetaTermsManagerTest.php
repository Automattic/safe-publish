<?php
/**
 * Meta Terms Manager import key policy test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Utils\Options;

/**
 * Tests the key policy Meta_Terms_Manager applies to imported post meta.
 */
class MetaTermsManagerTest extends TestCase {

	/**
	 * Database global the test replaces.
	 *
	 * @var mixed
	 */
	private mixed $wpdb;

	/**
	 * Supplies the default meta_key collation the key policy compares under.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Postmeta table name.
			 *
			 * @var string
			 */
			public string $postmeta = 'wp_postmeta';

			/**
			 * Returns the query as given.
			 *
			 * @param string $query Query.
			 * @return string Query.
			 */
			public function prepare( string $query ): string {
				return $query;
			}

			/**
			 * Returns the meta_key column's collation.
			 *
			 * @return array{Collation: string} Column row.
			 */
			public function get_row(): array {
				return array( 'Collation' => 'utf8mb4_unicode_520_ci' );
			}
		};
	}

	/**
	 * Restores the database global.
	 */
	#[\Override]
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $this->wpdb;

		parent::tearDown();
	}

	/**
	 * Every reserved core key, as the hooks documentation lists it.
	 *
	 * Kept here rather than read from the class, so changing the class list
	 * fails a test as a reminder to update the documentation.
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
	 * Verifies that every documented reserved core key is refused, and that the
	 * class reserves exactly those.
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

		// ASSERT: The class reserves exactly the documented core keys.
		$this->assertSame(
			self::DOCUMENTED_RESERVED_CORE_KEYS,
			( new ReflectionClass( Meta_Terms_Manager::class ) )
				->getConstant( 'RESERVED_CORE_KEYS' )
		);
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
	 * Verifies that a key the write stores, or lookups read, as a reserved key
	 * is refused under the form the source sent.
	 *
	 * @dataProvider reserved_key_variant_provider
	 *
	 * @param string $key Variant of a reserved key.
	 */
	public function test_refused_meta_keys_covers_reserved_key_variants(
		string $key
	): void {
		// ARRANGE: The variant alongside a migratable key.
		$meta = array(
			$key           => 'source-value',
			'custom_field' => 'value',
		);

		// ACT: Split the payload by the import key policy.
		$refused = Meta_Terms_Manager::refused_meta_keys( $meta );

		// ASSERT: The variant is refused as sent, and the other key written.
		$this->assertSame( array( $key ), $refused );
		$this->assertSame(
			array( 'custom_field' ),
			Meta_Terms_Manager::importable_meta_keys( $meta )
		);
	}

	/**
	 * Data provider naming reserved key variants PHP can compare.
	 *
	 * @return array<string, array{string}> One case per variant.
	 */
	public static function reserved_key_variant_provider(): array {
		return array(
			'uppercase plugin key' => array( 'SAFE_PUBLISH_SOURCE_POST_ID' ),
			'uppercase future key' => array( 'SAFE_PUBLISH_FUTURE_KEY' ),
			'uppercase core key'   => array( '_THUMBNAIL_ID' ),
			'slashed plugin key'   => array( '\\safe_publish_source_post_id' ),
			'slashed core key'     => array( '_thumbnail\\_id' ),
		);
	}

	/**
	 * Verifies that the reserved plugin keys are every post meta key Options
	 * defines, so a new key can't skip the collation check, and that every
	 * reserved key is lowercase printable ASCII, which the check requires.
	 */
	public function test_reserved_plugin_keys_cover_options_meta_keys(): void {
		// ARRANGE: Options' post meta keys, minus term meta keys and the
		// imported-from value; the policy's reserved lists.
		$excluded  = array(
			'META_SOURCE_TERM_ID',
			'META_SOURCE_TERM_URL',
			'META_TERM_ORIGIN_URL',
			'META_IMPORTED_FROM_VALUE',
		);
		$keys      = array();
		$constants = ( new ReflectionClass( Options::class ) )->getConstants();
		foreach ( $constants as $name => $value ) {
			if (
				str_starts_with( $name, 'META_' )
				&& ! in_array( $name, $excluded, true )
				&& is_string( $value )
			) {
				$keys[] = $value;
			}
		}
		$policy = new ReflectionClass( Meta_Terms_Manager::class );
		/** @var string[] $plugin */
		$plugin = $policy->getConstant( 'RESERVED_PLUGIN_KEYS' );
		/** @var string[] $core */
		$core     = $policy->getConstant( 'RESERVED_CORE_KEYS' );
		$reserved = array_merge( $plugin, $core );
		sort( $keys );
		sort( $plugin );

		// ACT & ASSERT: The plugin keys are exactly those, and lowercasing
		// changes no reserved key, none of which holds other bytes.
		$this->assertSame( $keys, $plugin );
		$this->assertSame( array_map( 'strtolower', $reserved ), $reserved );
		$this->assertSame(
			array(),
			preg_grep( '/[^\x20-\x7E]/', $reserved )
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
