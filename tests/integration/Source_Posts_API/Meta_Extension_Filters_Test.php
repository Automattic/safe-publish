<?php
/**
 * Tests for the source-fetch query-args and post-meta extension filters
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Acf_Complex_Recipe_Fixture;
use Safe_Publish\Tests\Integration\Acf_Recipe_Filters_Fixture;
use Safe_Publish\Tests\Integration\Yoast_Restrict_Recipe_Fixture;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Exercises the safe_publish_source_fetch_query_args and
 * safe_publish_source_post_meta filters: Their invocation contracts, that a
 * mutated query string reaches the request URL, and that mutated meta reaches
 * postmeta. Also runs the documented ACF/SCF and Yoast SEO recipes end to end.
 */
class Meta_Extension_Filters_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source URL the mocked endpoints are rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Source value with markup, which sanitize_text_field() turns into
	 * 'Bold raw'.
	 */
	private const RAW = '<b>Bold</b> raw';

	/**
	 * Credentials whose format passes the edit-context gate.
	 */
	private const CREDS = array(
		'shared_secret' => '0123456789abcdef0123456789abcdef',
	);

	/**
	 * Source Posts API under test.
	 *
	 * @var Source_Posts_API
	 */
	private Source_Posts_API $api;

	/**
	 * Post import service driving the end-to-end cases.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Values recorded by the filter and request hooks under test.
	 *
	 * @var array<string, mixed>
	 */
	private array $captures = array();

	/**
	 * Sets up the API and a full import service.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->api = new Source_Posts_API( new HTTP_Client() );

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			$this->api,
			$media_importer,
			$content_processor,
			new History_Repository(),
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Clears the extension filters and request hooks added per test.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_all_filters( 'safe_publish_source_fetch_query_args' );
		remove_all_filters( 'safe_publish_source_post_meta' );
		remove_filter( 'pre_http_request', array( $this, 'capture_request_url' ), 1 );
		remove_filter( 'pre_http_request', array( $this, 'serve_response_without_meta' ), 1 );
		remove_filter(
			'pre_http_request',
			array( $this, 'serve_response_with_overflow' ),
			1
		);
		remove_action( 'added_post_meta', array( Acf_Complex_Recipe_Fixture::class, 'replay_acf' ), 10 );
		remove_action( 'updated_post_meta', array( Acf_Complex_Recipe_Fixture::class, 'replay_acf' ), 10 );
		remove_action( 'added_post_meta', array( $this, 'record_stash' ), 1 );
		remove_action( 'added_post_meta', array( $this, 'record_write' ), 1 );
		Acf_Complex_Recipe_Fixture::$updates = array();
		parent::tearDown();
	}

	/**
	 * Verifies that safe_publish_source_fetch_query_args fires with the base
	 * query args and the fetch context.
	 */
	public function test_query_args_filter_receives_query_args_and_context(): void {
		// ARRANGE: Record what the filter receives.
		add_filter(
			'safe_publish_source_fetch_query_args',
			function ( array $query_args, array $context ): array {
				$this->captures['query_args']    = $query_args;
				$this->captures['query_context'] = $context;
				return $query_args;
			},
			10,
			2
		);

		// ACT: Fetch a post with edit-context credentials.
		$this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			self::CREDS,
			'post'
		);

		// ASSERT: Filter saw the embed + edit-context query args.
		$this->assertSame(
			array(
				'_embed'  => '1',
				'context' => 'edit',
			),
			$this->captures['query_args']
		);

		// ASSERT: Filter saw the fetch context.
		$this->assertSame(
			array(
				'source_post_id'  => 4242,
				'post_type'       => 'post',
				'source_site_url' => self::SOURCE_SITE_URL,
			),
			$this->captures['query_context']
		);
	}

	/**
	 * Verifies that a query-args mutation reaches the actual request URL.
	 */
	public function test_query_args_mutation_reaches_request_url(): void {
		// ARRANGE: A filter adds a marker arg; capture the outgoing URL.
		add_filter(
			'safe_publish_source_fetch_query_args',
			static function ( array $query_args ): array {
				$query_args['sp_marker'] = 'acf';
				return $query_args;
			}
		);
		add_filter( 'pre_http_request', array( $this, 'capture_request_url' ), 1, 3 );

		// ACT: Fetch a post.
		$this->api->fetch_fresh_post_content(
			4242,
			self::SOURCE_SITE_URL,
			self::CREDS,
			'post'
		);

		// ASSERT: The mutated arg is present in the request URL.
		$this->assertStringContainsString(
			'sp_marker=acf',
			$this->captures['request_url']
		);
	}

	/**
	 * Verifies that safe_publish_source_post_meta fires with the source meta,
	 * the full REST response, and the fetch context.
	 */
	public function test_post_meta_filter_receives_meta_data_and_context(): void {
		// ARRANGE: Source returns a meta object and a top-level acf object.
		$this->mock_post_overrides = array(
			'meta' => array( 'existing_key' => 'existing_value' ),
			'acf'  => array( 'hero_title' => 'From ACF' ),
		);
		add_filter(
			'safe_publish_source_post_meta',
			function ( array $meta, array $data, array $context ): array {
				$this->captures['meta']         = $meta;
				$this->captures['data']         = $data;
				$this->captures['meta_context'] = $context;
				return $meta;
			},
			10,
			3
		);

		// ACT: Fetch the post.
		$this->api->fetch_fresh_post_content(
			55,
			self::SOURCE_SITE_URL,
			self::CREDS,
			'post'
		);

		// ASSERT: The source meta object is the filter's initial value.
		$this->assertSame(
			array( 'existing_key' => 'existing_value' ),
			$this->captures['meta']
		);

		// ASSERT: The full response carries the top-level acf object.
		$this->assertSame(
			array( 'hero_title' => 'From ACF' ),
			$this->captures['data']['acf']
		);

		// ASSERT: The fetch context is passed through.
		$this->assertSame(
			array(
				'source_post_id'  => 55,
				'post_type'       => 'post',
				'source_site_url' => self::SOURCE_SITE_URL,
			),
			$this->captures['meta_context']
		);
	}

	/**
	 * Verifies that the meta filter still fires with an empty array when the
	 * response carries no meta key.
	 */
	public function test_post_meta_filter_receives_empty_array_when_meta_absent(): void {
		// ARRANGE: Serve a response with no meta key and record the initial value.
		add_filter( 'pre_http_request', array( $this, 'serve_response_without_meta' ), 1, 3 );
		add_filter(
			'safe_publish_source_post_meta',
			function ( array $meta ): array {
				$this->captures['meta'] = $meta;
				return $meta;
			}
		);

		// ACT: Fetch the post.
		$result = $this->api->fetch_fresh_post_content(
			77,
			self::SOURCE_SITE_URL,
			self::CREDS,
			'post'
		);

		// ASSERT: The fetch succeeded and the filter saw an empty array.
		$this->assertNotFalse( $result );
		$this->assertSame( array(), $this->captures['meta'] );
	}

	/**
	 * Verifies that a non-array filter return is coerced to an array, keeping
	 * the import crash-proof for downstream array sinks.
	 */
	public function test_post_meta_filter_non_array_return_is_coerced(): void {
		// ARRANGE: A filter returns null, as a bare return would.
		add_filter( 'safe_publish_source_post_meta', static fn() => null );

		// ACT: Fetch a post.
		$result = $this->api->fetch_fresh_post_content(
			99,
			self::SOURCE_SITE_URL,
			self::CREDS,
			'post'
		);

		// ASSERT: Meta is coerced to an empty array, not null.
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['meta'] );
	}

	/**
	 * Verifies that meta added by the filter reaches destination postmeta.
	 */
	public function test_mutated_meta_reaches_postmeta(): void {
		// ARRANGE: A filter injects a meta key absent from the source.
		add_filter(
			'safe_publish_source_post_meta',
			static function ( array $meta ): array {
				$meta['sp_injected'] = 'injected_value';
				return $meta;
			}
		);
		$post_data = array(
			'id'        => 8100,
			'title'     => 'Injected Meta',
			'content'   => '<p>Stale.</p>',
			'link'      => self::SOURCE_SITE_URL . '/injected-meta',
			'post_type' => 'post',
		);

		// ACT: Import via the single path.
		$result = $this->import_service->import_post( $post_data );

		// ASSERT: The injected value was written to destination postmeta.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			'injected_value',
			get_post_meta( (int) $result['post_id'], 'sp_injected', true )
		);
	}

	/**
	 * Verifies that the documented recipe merges scalar acf values into meta
	 * while skipping protected, reserved, and non-scalar entries.
	 */
	public function test_documented_recipe_merges_acf_scalars_into_meta(): void {
		// ARRANGE: Register the recipe and stage a mixed acf object.
		Acf_Recipe_Filters_Fixture::register();
		$this->mock_post_overrides = array(
			'title' => 'ACF Post',
			'acf'   => array(
				'hero_title'          => 'Hello',
				'subtitle'            => 'World',
				'_hero_title'         => 'field_key',
				'safe_publish_marker' => 'nope',
				'gallery'             => array( 1, 2, 3 ),
			),
		);
		$post_data                 = array(
			'id'        => 8200,
			'title'     => 'ACF Post',
			'content'   => '<p>Stale.</p>',
			'link'      => self::SOURCE_SITE_URL . '/acf-post',
			'post_type' => 'post',
		);

		// ACT: Import via the single path.
		$result  = $this->import_service->import_post( $post_data );
		$post_id = (int) $result['post_id'];

		// ASSERT: Scalar acf values landed in meta.
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Hello', get_post_meta( $post_id, 'hero_title', true ) );
		$this->assertSame( 'World', get_post_meta( $post_id, 'subtitle', true ) );

		// ASSERT: Protected, reserved, and non-scalar entries were skipped.
		$this->assertSame( '', get_post_meta( $post_id, '_hero_title', true ) );
		$this->assertSame( '', get_post_meta( $post_id, 'safe_publish_marker', true ) );
		$this->assertSame( '', get_post_meta( $post_id, 'gallery', true ) );
	}

	/**
	 * Verifies the contract the documented complex-field recipe depends on: That
	 * Safe Publish's meta write fires WordPress' added_post_meta action — so the
	 * recipe's cleanup handler runs — and preserves the stashed payload intact.
	 *
	 * The scalar recipe test only inspects final postmeta, so it does not cover
	 * this added_post_meta contract.
	 */
	public function test_complex_recipe_stashes_and_clears_acf_payload(): void {
		// ARRANGE: A spy records the stash the instant it is written, before the
		// recipe's replay (priority 10) clears it. ACF is absent in this harness.
		add_action( 'added_post_meta', array( $this, 'record_stash' ), 1, 4 );
		Acf_Complex_Recipe_Fixture::register();
		$acf                       = array( 'gallery' => array( 1, 2, 3 ) );
		$this->mock_post_overrides = array( 'acf' => $acf );
		$post_data                 = array(
			'id'        => 8300,
			'title'     => 'Complex ACF',
			'content'   => '<p>Stale.</p>',
			'link'      => self::SOURCE_SITE_URL . '/complex-acf',
			'post_type' => 'post',
		);

		// ACT: Import via the single path.
		$result  = $this->import_service->import_post( $post_data );
		$post_id = (int) $result['post_id'];

		// ASSERT: The stash was written with the acf payload intact.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			wp_json_encode( $acf ),
			$this->captures['stash'] ?? null
		);

		// ASSERT: The replay cleared the stash — no orphan meta remains.
		$this->assertSame(
			'',
			get_post_meta( $post_id, Acf_Complex_Recipe_Fixture::STASH_KEY, true )
		);
	}

	/**
	 * Verifies that the documented recipe writes each acf scalar after the
	 * source's meta, so the acf value replaces every copy the source sent.
	 */
	public function test_documented_recipe_writes_acf_scalars_last(): void {
		// ARRANGE: Register the recipe.
		Acf_Recipe_Filters_Fixture::register();

		// ACT: Import a post whose meta holds the key, then a variant of it.
		$post_id = $this->import_source_post(
			8210,
			array(
				'meta' => array(
					'hero_title'  => 'Stale',
					'hero_title ' => 'Variant',
				),
				'acf'  => array( 'hero_title' => 'From ACF' ),
			)
		);

		// ASSERT: The acf value replaced both source copies.
		$this->assertSame(
			'From ACF',
			get_post_meta( $post_id, 'hero_title', true )
		);
	}

	/**
	 * Data provider for the allowlist recipe: an optional first import, the
	 * import under test, and the hero_title value it must leave.
	 *
	 * @return array<string, array{?array, array, string}> One case per payload.
	 */
	public static function allowlist_payload_provider(): array {
		$clean = array( 'acf' => array( 'hero_title' => 'Clean' ) );

		return array(
			'source meta copy, no acf value'  => array(
				null,
				array( 'meta' => array( 'hero_title' => self::RAW ) ),
				'Bold raw',
			),
			'acf value over source meta copy' => array(
				null,
				array(
					'meta' => array( 'hero_title' => self::RAW ),
					'acf'  => array( 'hero_title' => 'Clean' ),
				),
				'Clean',
			),
			'acf value with markup'           => array(
				null,
				array( 'acf' => array( 'hero_title' => self::RAW ) ),
				'Bold raw',
			),
			'key with trailing space'         => array(
				null,
				array( 'meta' => array( 'hero_title ' => self::RAW ) ),
				'',
			),
			'key wrapped in tags'             => array(
				null,
				array( 'meta' => array( '<b>hero_title</b>' => self::RAW ) ),
				'',
			),
			'slashed key'                     => array(
				null,
				array( 'meta' => array( 'hero\\_title' => self::RAW ) ),
				'',
			),
			'uppercase key'                   => array(
				null,
				array( 'meta' => array( 'HERO_TITLE' => self::RAW ) ),
				'',
			),
			'uppercase key on re-import'      => array(
				$clean,
				array( 'meta' => array( 'HERO_TITLE' => self::RAW ) ),
				'',
			),
			'accented key on re-import'       => array(
				$clean,
				array( 'meta' => array( 'héro_title' => self::RAW ) ),
				'',
			),
			'variant key after exact key'     => array(
				null,
				array(
					'meta' => array(
						'hero_title'  => 'Stale',
						'hero_title ' => self::RAW,
					),
					'acf'  => array( 'hero_title' => 'Clean' ),
				),
				'Clean',
			),
			'array acf value'                 => array(
				null,
				array( 'acf' => array( 'hero_title' => array( self::RAW ) ) ),
				'',
			),
			'value cleared on the source'     => array(
				$clean,
				array( 'acf' => array( 'hero_title' => null ) ),
				'',
			),
		);
	}

	/**
	 * Verifies that the allowlist recipe stores only its sanitized value for
	 * a listed field, whatever copy of the key the source sends.
	 *
	 * @dataProvider allowlist_payload_provider
	 *
	 * @param array|null $first    Overrides for an earlier import, if any.
	 * @param array      $second   Overrides for the import under test.
	 * @param string     $expected Value hero_title must hold.
	 */
	public function test_allowlist_recipe_stores_only_its_value(
		?array $first,
		array $second,
		string $expected
	): void {
		// ARRANGE: Register the allowlist; run the earlier import, if any.
		Acf_Recipe_Filters_Fixture::register_allowlist();
		if ( null !== $first ) {
			$this->import_source_post( 8220, $first );
		}

		// ACT: Import the payload under test.
		$post_id = $this->import_source_post( 8220, $second );

		// ASSERT: hero_title holds the recipe's value, and no row the
		// database reads as hero_title holds the raw one.
		$this->assertSame(
			$expected,
			get_post_meta( $post_id, 'hero_title', true )
		);
		$this->assertNotContains(
			self::RAW,
			self::stored_values( $post_id, 'hero_title' )
		);
	}

	/**
	 * Verifies that the allowlist recipe leaves the source's other meta as the
	 * source sent it.
	 */
	public function test_allowlist_recipe_keeps_other_source_meta(): void {
		// ARRANGE: Register the allowlist.
		Acf_Recipe_Filters_Fixture::register_allowlist();

		// ACT: Import a post whose meta holds a key the allowlist omits.
		$post_id = $this->import_source_post(
			8230,
			array( 'meta' => array( 'other_key' => self::RAW ) )
		);

		// ASSERT: The omitted key imported unchanged.
		$this->assertSame(
			self::RAW,
			get_post_meta( $post_id, 'other_key', true )
		);
	}

	/**
	 * Verifies that the complex recipe replays only the expected field, with
	 * each value sanitized, whatever else the acf payload holds.
	 */
	public function test_complex_recipe_replays_only_expected_fields(): void {
		// ARRANGE: Register the recipe.
		Acf_Complex_Recipe_Fixture::register();

		// ACT: Import a post whose acf payload holds the expected repeater,
		// with a markup caption and a malformed row, next to other fields.
		$post_id = $this->import_source_post(
			8310,
			array(
				'acf' => array(
					'slides'      => array(
						array( 'caption' => self::RAW ),
						'malformed',
					),
					'other_field' => self::RAW,
					'hero_title'  => self::RAW,
				),
			)
		);

		// ASSERT: Only the repeater reached update_field(), sanitized.
		$this->assertSame(
			array(
				array(
					Acf_Complex_Recipe_Fixture::FIELD_KEY,
					array(
						array( 'caption' => 'Bold raw' ),
						array( 'caption' => '' ),
					),
					$post_id,
				),
			),
			Acf_Complex_Recipe_Fixture::$updates
		);
	}

	/**
	 * Verifies that the complex recipe replaces a stash the source sends under
	 * the stash key with its own, even when the response has no acf object.
	 */
	public function test_complex_recipe_replaces_source_stash(): void {
		// ARRANGE: Register the recipe; the source's stash holds a row.
		Acf_Complex_Recipe_Fixture::register();
		$stash = wp_json_encode(
			array( 'slides' => array( array( 'caption' => 'Sent' ) ) )
		);

		// ACT: Import a post whose meta holds its own stash and no acf object.
		$post_id = $this->import_source_post(
			8320,
			array(
				'meta' => array(
					Acf_Complex_Recipe_Fixture::STASH_KEY => $stash,
				),
			)
		);

		// ASSERT: Only the recipe's empty stash replayed.
		$this->assertSame(
			array(
				array(
					Acf_Complex_Recipe_Fixture::FIELD_KEY,
					array(),
					$post_id,
				),
			),
			Acf_Complex_Recipe_Fixture::$updates
		);
	}

	/**
	 * Verifies that the complex recipe validates a stash the source sends
	 * under a variant of the stash key, then replays its own last.
	 */
	public function test_complex_recipe_validates_source_stash_variant(): void {
		// ARRANGE: Register the recipe; the source's stash holds a markup
		// caption and another field.
		Acf_Complex_Recipe_Fixture::register();
		$stash = wp_json_encode(
			array(
				'slides'      => array( array( 'caption' => self::RAW ) ),
				'other_field' => self::RAW,
			)
		);

		// ACT: Import a post whose meta holds the stash under a variant key.
		$post_id = $this->import_source_post(
			8330,
			array(
				'meta' => array(
					Acf_Complex_Recipe_Fixture::STASH_KEY . ' ' => $stash,
				),
			)
		);

		// ASSERT: The source's stash replayed only the sanitized repeater,
		// and the recipe's own stash replayed last.
		$this->assertSame(
			array(
				array(
					Acf_Complex_Recipe_Fixture::FIELD_KEY,
					array( array( 'caption' => 'Bold raw' ) ),
					$post_id,
				),
				array(
					Acf_Complex_Recipe_Fixture::FIELD_KEY,
					array(),
					$post_id,
				),
			),
			Acf_Complex_Recipe_Fixture::$updates
		);
	}

	/**
	 * Verifies that the complex recipe writes its stash after the source's
	 * meta, so the replay is the last write to the repeater's rows.
	 */
	public function test_complex_recipe_writes_stash_after_source_meta(): void {
		// ARRANGE: Record the order of meta writes; register the recipe.
		add_action( 'added_post_meta', array( $this, 'record_write' ), 1, 4 );
		Acf_Complex_Recipe_Fixture::register();
		$keys = array(
			'slides_0_caption',
			Acf_Complex_Recipe_Fixture::STASH_KEY,
		);

		// ACT: Import a post whose meta holds the stash key ahead of a row.
		$this->import_source_post(
			8340,
			array(
				'meta' => array(
					Acf_Complex_Recipe_Fixture::STASH_KEY => '[]',
					'slides_0_caption'                    => self::RAW,
				),
				'acf'  => array(
					'slides' => array( array( 'caption' => 'Clean' ) ),
				),
			)
		);

		// ASSERT: The stash was written once, after the row.
		$this->assertSame(
			$keys,
			array_values(
				array_intersect( $this->captures['writes'] ?? array(), $keys )
			)
		);
	}

	/**
	 * Verifies that the complex recipe writes its stash after the keys that a
	 * filter registered after it adds.
	 */
	public function test_complex_recipe_stashes_after_other_filters(): void {
		// ARRANGE: Record the order of meta writes; register the complex
		// recipe before the Step 1 recipe, which merges acf scalars.
		add_action( 'added_post_meta', array( $this, 'record_write' ), 1, 4 );
		Acf_Complex_Recipe_Fixture::register();
		Acf_Recipe_Filters_Fixture::register();
		$keys = array(
			'slides_0_caption',
			Acf_Complex_Recipe_Fixture::STASH_KEY,
		);

		// ACT: Import a post whose acf object holds a row as a scalar.
		$this->import_source_post(
			8350,
			array( 'acf' => array( 'slides_0_caption' => self::RAW ) )
		);

		// ASSERT: The stash was written after the merged row.
		$this->assertSame(
			$keys,
			array_values(
				array_intersect( $this->captures['writes'] ?? array(), $keys )
			)
		);
	}

	/**
	 * Verifies that the complex recipe replays the expected field, empty, when
	 * the acf payload holds a number that cannot be stashed.
	 */
	public function test_complex_recipe_replays_unstashable_payload(): void {
		// ARRANGE: Register the recipe; serve the payload's placeholder as a
		// number too large to encode back to JSON.
		Acf_Complex_Recipe_Fixture::register();
		add_filter(
			'pre_http_request',
			array( $this, 'serve_response_with_overflow' ),
			1,
			3
		);

		// ACT: Import a post whose acf payload holds the number.
		$post_id = $this->import_source_post(
			8360,
			array(
				'acf' => array(
					'slides' => array( array( 'caption' => 'Clean' ) ),
					'count'  => 'OVERFLOW',
				),
			)
		);

		// ASSERT: The replay still wrote the repeater, empty.
		$this->assertSame(
			array(
				array(
					Acf_Complex_Recipe_Fixture::FIELD_KEY,
					array(),
					$post_id,
				),
			),
			Acf_Complex_Recipe_Fixture::$updates
		);
	}

	/**
	 * Data provider naming forms of an excluded Yoast SEO key that the recipe
	 * drops.
	 *
	 * @return array<string, array{string}> One case per key form.
	 */
	public static function yoast_excluded_key_provider(): array {
		return array(
			'exact key'              => array(
				'_yoast_wpseo_meta-robots-noindex',
			),
			'uppercase key'          => array(
				'_YOAST_WPSEO_META-ROBOTS-NOINDEX',
			),
			'key wrapped in tags'    => array(
				'<b>_yoast_wpseo_meta-robots-noindex</b>',
			),
			'slashed key'            => array(
				'_yoast_wpseo\\_meta-robots-noindex',
			),
			'key with leading space' => array(
				' _yoast_wpseo_meta-robots-noindex',
			),
		);
	}

	/**
	 * Verifies that the Yoast SEO recipe drops an excluded key whatever its
	 * case, markup, slashes, or surrounding spaces, and imports the allowed
	 * keys.
	 *
	 * @dataProvider yoast_excluded_key_provider
	 *
	 * @param string $key Form of the excluded key the source sends.
	 */
	public function test_yoast_recipe_drops_excluded_key( string $key ): void {
		// ARRANGE: Register the recipe; import the post, then set the
		// excluded key on the destination copy.
		Yoast_Restrict_Recipe_Fixture::register();
		$post_id = $this->import_source_post(
			8510,
			array( 'meta' => array( '_yoast_wpseo_title' => 'Title' ) )
		);
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '0' );

		// ACT: Re-import with the source sending the excluded key.
		$this->import_source_post(
			8510,
			array(
				'meta' => array(
					'_yoast_wpseo_title' => 'New title',
					$key                 => '1',
				),
			)
		);

		// ASSERT: The destination's value stands, and the allowed key
		// imported.
		$this->assertSame(
			array( '0' ),
			self::stored_values( $post_id, '_yoast_wpseo_meta-robots-noindex' )
		);
		$this->assertSame(
			'New title',
			get_post_meta( $post_id, '_yoast_wpseo_title', true )
		);
	}

	/**
	 * Verifies that the Yoast SEO recipe drops an excluded key that a filter
	 * registered after it adds.
	 */
	public function test_yoast_recipe_runs_after_other_filters(): void {
		// ARRANGE: Register the Yoast SEO recipe before the Step 1 recipe,
		// which merges acf scalars.
		Yoast_Restrict_Recipe_Fixture::register();
		Acf_Recipe_Filters_Fixture::register();

		// ACT: Import a post whose acf object holds the excluded key.
		$post_id = $this->import_source_post(
			8520,
			array(
				'acf' => array( ' _yoast_wpseo_meta-robots-noindex' => '1' ),
			)
		);

		// ASSERT: The excluded key did not import.
		$this->assertSame(
			array(),
			self::stored_values( $post_id, '_yoast_wpseo_meta-robots-noindex' )
		);
	}

	/**
	 * Records the outgoing request URL, then defers to the base mock.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $args    Request args (unused).
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error The unchanged short-circuit value.
	 */
	public function capture_request_url(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );
		if ( 1 === preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			$this->captures['request_url'] = $url;
		}
		return $preempt;
	}

	/**
	 * Serves a single-post response that omits the meta key entirely.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $args    Request args (unused).
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error Mock response, or $preempt to defer.
	 */
	public function serve_response_without_meta(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( 1 !== preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return $preempt;
		}

		$body = array(
			'id'      => 77,
			'title'   => array( 'raw' => 'No Meta' ),
			'content' => array( 'raw' => '<p>Body.</p>' ),
			'excerpt' => array( 'raw' => '' ),
			'link'    => self::SOURCE_SITE_URL . '/no-meta',
			'type'    => 'post',
		);

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Serves the mocked single-post response with its OVERFLOW placeholder
	 * replaced by a number too large for PHP to encode back to JSON.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $args    Request args (unused).
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error Mock response, or $preempt to defer.
	 */
	public function serve_response_with_overflow(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( 1 !== preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return $preempt;
		}

		$response         = $this->build_mock_post_response();
		$response['body'] = str_replace(
			'"OVERFLOW"',
			'1e400',
			$response['body']
		);

		return $response;
	}

	/**
	 * Records the acf stash value the moment it is written to a post.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $post_id    Post the meta was written to (unused).
	 * @param string $meta_key   Meta key written.
	 * @param mixed  $meta_value Meta value written.
	 */
	public function record_stash(
		int $meta_id,
		int $post_id,
		string $meta_key,
		mixed $meta_value
	): void {
		unset( $meta_id, $post_id );

		if ( Acf_Complex_Recipe_Fixture::STASH_KEY === $meta_key ) {
			$this->captures['stash'] = $meta_value;
		}
	}

	/**
	 * Records each meta key the moment a post first stores it.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $post_id    Post the meta was written to (unused).
	 * @param string $meta_key   Meta key written.
	 * @param mixed  $meta_value Meta value written (unused).
	 */
	public function record_write(
		int $meta_id,
		int $post_id,
		string $meta_key,
		mixed $meta_value
	): void {
		unset( $meta_id, $post_id, $meta_value );

		$this->captures['writes'][] = $meta_key;
	}

	/**
	 * Imports a source post through the single path.
	 *
	 * @param int   $source_id Source post ID.
	 * @param array $overrides Overrides for the mocked source response.
	 * @return int Destination post ID.
	 */
	private function import_source_post(
		int $source_id,
		array $overrides
	): int {
		$this->mock_post_overrides = $overrides;

		$result = $this->import_service->import_post(
			array(
				'id'        => $source_id,
				'title'     => 'Recipe Post',
				'content'   => '<p>Stale.</p>',
				'link'      => self::SOURCE_SITE_URL . '/recipe-' . $source_id,
				'post_type' => 'post',
			)
		);

		$this->assertTrue( $result['success'] );

		return (int) $result['post_id'];
	}

	/**
	 * Returns the values of every row the database reads as a meta key.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string[] Stored values.
	 */
	private static function stored_values( int $post_id, string $key ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				 WHERE post_id = %d AND meta_key = %s",
				$post_id,
				$key
			)
		);
	}
}
