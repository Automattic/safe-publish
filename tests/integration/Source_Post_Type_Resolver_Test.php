<?php
/**
 * Integration tests for Source_Post_Type_Resolver
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Source_Post_Type_Resolver;
use WP_Error;

/**
 * Verifies slug => rest_base resolution against the source's post-types map.
 */
class Source_Post_Type_Resolver_Test extends Integration_Test_Case {

	/**
	 * Source URL used across the resolver tests.
	 */
	private const SOURCE_URL = 'https://source.example.com';

	/**
	 * Resets the per-request memo so each test sees a fresh fetch.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Source_Post_Type_Resolver::reset_cache();
	}

	/**
	 * Builds a make_request callable returning a catalog/post-types list and
	 * counting how many times it is invoked.
	 *
	 * @param array $types      Post-type entries to encode in the response
	 *                          body.
	 * @param int   $call_count Invocation counter, passed by reference.
	 * @return callable fn($url, $action, $credentials): array
	 */
	private function recording_types_callable(
		array $types,
		int &$call_count
	): callable {
		return function () use ( $types, &$call_count ): array {
			++$call_count;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( $types ),
			);
		};
	}

	/**
	 * Verifies that built-in types resolve from the static map without issuing
	 * a source request.
	 */
	public function test_builtin_resolves_without_source_request(): void {
		// ARRANGE: A callable that flags the test if it is ever invoked.
		$called       = false;
		$make_request = function () use ( &$called ): array {
			$called = true;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '[]',
			);
		};

		// ACT: Resolve a built-in type.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'post',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: Resolved from the static map; no source request was made.
		$this->assertSame( 'posts', $rest_base );
		$this->assertFalse(
			$called,
			'Built-in types must not trigger a source request.'
		);
	}

	/**
	 * Verifies that a custom CPT whose rest_base differs from its slug resolves
	 * to the rest_base advertised by the source.
	 */
	public function test_custom_cpt_resolves_to_source_rest_base(): void {
		// ARRANGE: Source advertises movie => movies.
		$calls        = 0;
		$make_request = $this->recording_types_callable(
			array(
				array(
					'slug'      => 'movie',
					'rest_base' => 'movies',
				),
			),
			$calls
		);

		// ACT: Resolve the custom slug.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: Resolved to the advertised rest_base.
		$this->assertSame( 'movies', $rest_base );
	}

	/**
	 * Verifies that a custom REST base resolves back to its source type slug.
	 */
	public function test_custom_rest_base_resolves_to_source_slug(): void {
		// ARRANGE: Source advertises movie => movies.
		$calls        = 0;
		$make_request = $this->recording_types_callable(
			array(
				array(
					'slug'      => 'movie',
					'rest_base' => 'movies',
				),
			),
			$calls
		);

		// ACT: Resolve post data requested through the advertised REST base.
		$result = Source_Post_Type_Resolver::resolve_post_data(
			'movies',
			array(
				'type'    => 'movie',
				'title'   => array( 'raw' => 'Movie' ),
				'content' => array( 'raw' => 'Content' ),
				'excerpt' => array( 'raw' => '' ),
			),
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The authoritative catalog supplies the canonical slug.
		$this->assertIsArray( $result );
		$this->assertSame( 'movie', $result['post_type'] );
	}

	/**
	 * Verifies that routing and raw-field resolution share one authenticated
	 * catalog request.
	 */
	public function test_catalog_metadata_is_shared_between_resolvers(): void {
		// ARRANGE: A custom movie type supports title and content, but not
		// excerpt.
		$calls         = 0;
		$requested_url = '';
		$make_request  = function ( string $url ) use (
			&$calls,
			&$requested_url
		): array {
			++$calls;
			$requested_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						array(
							'slug'       => 'movie',
							'rest_base'  => 'movies',
							'raw_fields' => array( 'title', 'content' ),
						),
					)
				),
			);
		};

		$post_data = array(
			'type'    => 'movie',
			'title'   => array( 'raw' => 'Movie' ),
			'content' => array( 'raw' => 'Content' ),
		);

		// ACT: Resolve routing, then validate data using the same catalog.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		$result    = Source_Post_Type_Resolver::resolve_post_data(
			'movie',
			$post_data,
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: One authenticated catalog request supplied both resolutions.
		$this->assertSame( 'movies', $rest_base );
		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'title'   => 'Movie',
				'content' => 'Content',
				'excerpt' => '',
			),
			$result['raw_values']
		);
		$this->assertSame( 1, $calls );
		$this->assertStringContainsString(
			'/safe-publish/v1/catalog/post-types',
			$requested_url
		);
	}

	/**
	 * Verifies that a catalog from an older source has no field metadata.
	 */
	public function test_legacy_catalog_omits_raw_fields(): void {
		// ARRANGE: The catalog entry predates raw_fields.
		$calls        = 0;
		$make_request = $this->recording_types_callable(
			array(
				array(
					'slug'      => 'post',
					'rest_base' => 'posts',
				),
			),
			$calls
		);

		// ACT: Resolve post data with every raw field absent.
		$result = Source_Post_Type_Resolver::resolve_post_data(
			'post',
			array( 'type' => 'post' ),
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: Absence selects response-shape compatibility behavior.
		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'title'   => '',
				'content' => '',
				'excerpt' => '',
			),
			$result['raw_values']
		);
	}

	/**
	 * Verifies that invalid current raw_fields metadata fails conservatively
	 * without losing the REST-base mapping.
	 */
	public function test_invalid_raw_fields_property_fails_conservatively(): void {
		// ARRANGE: Valid type metadata carries an invalid field name.
		$calls        = 0;
		$make_request = $this->recording_types_callable(
			array(
				array(
					'slug'       => 'movie',
					'rest_base'  => 'movies',
					'raw_fields' => array( 'title', 'unknown' ),
				),
			),
			$calls
		);

		// ACT: Resolve routing and post data missing one core raw field.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		$result    = Source_Post_Type_Resolver::resolve_post_data(
			'movie',
			array(
				'type'    => 'movie',
				'title'   => array( 'raw' => 'Movie' ),
				'content' => array( 'raw' => 'Content' ),
			),
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: Routing remains valid, but invalid metadata cannot weaken
		// field validation.
		$this->assertSame( 'movies', $rest_base );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_raw_fields_missing',
			$result->get_error_code()
		);
		$this->assertSame( 1, $calls );
	}

	/**
	 * Verifies that an unreachable source falls back to slug passthrough rather
	 * than failing.
	 */
	public function test_unreachable_source_falls_back_to_slug(): void {
		// ARRANGE: Callable returns a transport error.
		$make_request = static fn() => new WP_Error(
			'http_request_failed',
			'down'
		);

		// ACT: Resolve a custom slug with no reachable source.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: Falls back to the slug.
		$this->assertSame( 'movie', $rest_base );
	}

	/**
	 * Verifies that a REST error envelope is treated as a failure and falls
	 * back to slug passthrough.
	 */
	public function test_error_envelope_falls_back_to_slug(): void {
		// ARRANGE: Callable returns a REST error envelope body.
		$make_request = static fn() => array(
			'response' => array( 'code' => 401 ),
			'body'     => (string) wp_json_encode(
				array(
					'code'    => 'rest_forbidden',
					'message' => 'no',
				)
			),
		);

		// ACT: Resolve a custom slug.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: Falls back to the slug.
		$this->assertSame( 'movie', $rest_base );
	}

	/**
	 * Verifies that a failed fetch is not memoized, so a later call retries.
	 */
	public function test_failed_fetch_is_not_memoized(): void {
		// ARRANGE: First response errors, second succeeds.
		$attempt      = 0;
		$make_request = function () use ( &$attempt ): array|WP_Error {
			++$attempt;
			if ( 1 === $attempt ) {
				return new WP_Error( 'http_request_failed', 'down' );
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						array(
							'slug'      => 'movie',
							'rest_base' => 'movies',
						),
					)
				),
			);
		};

		// ACT: First resolution fails (passthrough), second succeeds.
		$first  = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		$second = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The failure was retried rather than cached.
		$this->assertSame(
			'movie',
			$first,
			'A failed fetch should fall back to the slug.'
		);
		$this->assertSame(
			'movies',
			$second,
			'A failed fetch must not be memoized.'
		);
	}

	/**
	 * Verifies that a 200 response whose body is a JSON object rather than a
	 * list is treated as a failure and not memoized, so a later call retries.
	 */
	public function test_non_list_body_is_not_memoized(): void {
		// ARRANGE: First response is a JSON object, second is a valid list.
		$attempt      = 0;
		$make_request = function () use ( &$attempt ): array {
			++$attempt;
			if ( 1 === $attempt ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array( 'movie' => array( 'rest_base' => 'movies' ) )
					),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						array(
							'slug'      => 'movie',
							'rest_base' => 'movies',
						),
					)
				),
			);
		};

		// ACT: First resolution sees the object body, second the list.
		$first  = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		$second = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The object body was not memoized; the retry resolved it.
		$this->assertSame(
			'movie',
			$first,
			'A non-list body should fall back to the slug.'
		);
		$this->assertSame(
			'movies',
			$second,
			'A non-list body must not be memoized.'
		);
	}

	/**
	 * Verifies that a rest_base outside the safe REST-path charset is rejected
	 * so a compromised source cannot shape the request URL.
	 *
	 * @dataProvider unsafe_rest_base_provider
	 *
	 * @param string $unsafe_rest_base A rest_base the source must not be
	 *                                 trusted with.
	 */
	public function test_unsafe_rest_base_is_rejected(
		string $unsafe_rest_base
	): void {
		// ARRANGE: Source advertises an unsafe rest_base for the slug.
		$calls        = 0;
		$make_request = $this->recording_types_callable(
			array(
				array(
					'slug'      => 'movie',
					'rest_base' => $unsafe_rest_base,
				),
			),
			$calls
		);

		// ACT: Resolve the custom slug.
		$rest_base = Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The unsafe value is dropped, falling back to the slug.
		$this->assertSame( 'movie', $rest_base );
	}

	/**
	 * Data provider of rest_base values that must be rejected.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafe_rest_base_provider(): array {
		return array(
			'path traversal'   => array( '../../wp-admin' ),
			'trailing newline' => array( "movies\n" ),
			'embedded space'   => array( 'mov ies' ),
			'dot extension'    => array( 'movies.json' ),
			'empty'            => array( '' ),
		);
	}
}
