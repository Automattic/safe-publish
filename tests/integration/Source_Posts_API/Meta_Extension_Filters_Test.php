<?php
/**
 * Tests for the source-fetch query-args and post-meta extension filters.
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
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Exercises the safe_publish_source_fetch_query_args and
 * safe_publish_source_post_meta filters: their invocation contracts, that a
 * mutated query string reaches the request URL, and that mutated meta reaches
 * postmeta. Also runs the documented ACF/SCF recipe end to end.
 */
class Meta_Extension_Filters_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source URL the mocked endpoints are rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

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
		remove_action( 'added_post_meta', array( Acf_Complex_Recipe_Fixture::class, 'replay_acf' ), 10 );
		remove_action( 'updated_post_meta', array( Acf_Complex_Recipe_Fixture::class, 'replay_acf' ), 10 );
		remove_action( 'added_post_meta', array( $this, 'record_stash' ), 1 );
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
	 * Verifies the contract the documented complex-field recipe depends on: that
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
		$this->captures['request_url'] = $url;
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
}
