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

		// ACT: Resolve the advertised REST base to its slug.
		$slug = Source_Post_Type_Resolver::resolve_slug(
			'movies',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The authoritative source map supplies the canonical slug.
		$this->assertSame( 'movie', $slug );
	}

	/**
	 * Verifies that source raw fields are read from the route schema and cached
	 * for repeated imports of the same type.
	 */
	public function test_source_raw_fields_are_resolved_and_cached(): void {
		// ARRANGE: A source type supporting title and editor, but not excerpt.
		$calls         = 0;
		$requested_url = '';
		$method        = '';
		$make_request  = function (
			string $url,
			string $_action,
			array $_credentials,
			array $args
		) use (
			&$calls,
			&$requested_url,
			&$method
		): array {
			++$calls;
			$requested_url = $url;
			$method        = (string) ( $args['method'] ?? '' );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'schema' => array(
							'properties' => array(
								'title'   => array( 'properties' => array( 'raw' => array( 'type' => 'string' ) ) ),
								'content' => array( 'properties' => array( 'raw' => array( 'type' => 'string' ) ) ),
							),
						),
					)
				),
			);
		};

		// ACT: Resolve the same source type twice.
		$first  = Source_Post_Type_Resolver::resolve_raw_fields(
			'wp_navigation',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		$second = Source_Post_Type_Resolver::resolve_raw_fields(
			'wp_navigation',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The route schema was requested once with OPTIONS.
		$this->assertSame(
			array(
				'title'   => true,
				'content' => true,
			),
			$first
		);
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
		$this->assertStringContainsString( '/wp-json/wp/v2/navigation', $requested_url );
		$this->assertSame( 'OPTIONS', $method );
	}

	/**
	 * Verifies that failed schema lookups are cached instead of retried for
	 * every item in a bulk import.
	 *
	 * @dataProvider failed_schema_provider
	 *
	 * @param string|WP_Error $body          Malformed body or transport error.
	 * @param string          $expected_code Expected resolver error code.
	 */
	public function test_failed_source_post_type_schema_returns_error(
		string|WP_Error $body,
		string $expected_code
	): void {
		// ARRANGE: The metadata request returns a terminal failure.
		$calls        = 0;
		$make_request = static function () use ( &$calls, $body ): array|WP_Error {
			++$calls;
			if ( $body instanceof WP_Error ) {
				return $body;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $body,
			);
		};

		// ACT: Resolve raw fields from the malformed response.
		$result = Source_Post_Type_Resolver::resolve_raw_fields(
			'post',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		$cached = Source_Post_Type_Resolver::resolve_raw_fields(
			'post',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The distinct metadata error is cached for the request.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertSame( $result, $cached );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Data provider of failed schema responses.
	 *
	 * @return array<string, array{string|WP_Error, string}>
	 */
	public static function failed_schema_provider(): array {
		return array(
			'missing schema'     => array(
				'{}',
				'source_post_type_schema_invalid',
			),
			'properties as list' => array(
				'{"schema":{"properties":[]}}',
				'source_post_type_schema_invalid',
			),
			'transport error'    => array(
				new WP_Error( 'request_failed', 'Source request failed.' ),
				'request_failed',
			),
		);
	}

	/**
	 * Verifies that a route schema with no raw fields is accepted.
	 */
	public function test_empty_source_post_type_schema_is_accepted(): void {
		// ARRANGE: Source returns an empty schema properties object.
		$make_request = static fn(): array => array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"schema":{"properties":{}}}',
		);

		// ACT: Resolve the empty raw-field map.
		$result = Source_Post_Type_Resolver::resolve_raw_fields(
			'sp_minimal',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: A genuinely empty support map is valid.
		$this->assertSame( array(), $result );
	}

	/**
	 * Verifies that the source post-types map is fetched once per request and
	 * reused for subsequent resolutions (the bulk-import dedupe path).
	 */
	public function test_source_map_is_fetched_once_per_request(): void {
		// ARRANGE: Recording callable shared across two resolutions.
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

		// ACT: Resolve twice within the same request.
		Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);
		Source_Post_Type_Resolver::resolve_rest_base(
			'movie',
			self::SOURCE_URL,
			$make_request,
			array()
		);

		// ASSERT: The source was consulted only once.
		$this->assertSame(
			1,
			$calls,
			'The post-types map must be memoized per request.'
		);
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
