<?php
/**
 * Tests that fetch_fresh_post_content returns a distinct WP_Error per cause
 * instead of collapsing every failure into a single generic return.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Fetch Fresh Content Error Test.
 *
 * Drives the source single-post fetch through a mocked HTTP response so each
 * distinct failure surfaces its own error code. The invalid-URL short-circuit,
 * which needs no HTTP call, is covered by the unit suite.
 */
class Fetch_Fresh_Content_Error_Test extends Integration_Test_Case {

	/**
	 * Source URL the mocked endpoint is rooted at.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Body returned by the mocked response, set per test.
	 *
	 * @var string
	 */
	private string $mock_body = '';

	/**
	 * Raw fields returned by the mocked source post-type catalog.
	 *
	 * @var array<string, bool>
	 */
	private array $mock_raw_fields = array(
		'title'   => true,
		'content' => true,
		'excerpt' => true,
	);

	/**
	 * Optional raw_fields value used instead of the valid default.
	 *
	 * @var mixed
	 */
	private mixed $mock_raw_fields_override = null;

	/**
	 * Whether catalog entries include raw_fields metadata.
	 *
	 * @var bool
	 */
	private bool $mock_includes_raw_fields = true;

	/**
	 * Optional error returned by the mocked catalog request.
	 *
	 * @var array|WP_Error|null
	 */
	private array|WP_Error|null $mock_catalog_error = null;

	/**
	 * Catalog requests the mock has served.
	 *
	 * @var int
	 */
	private int $catalog_requests = 0;

	/**
	 * Post types returned by the mocked Safe Publish catalog.
	 *
	 * @var list<array<string, string>>
	 */
	private array $mock_post_types = array(
		array(
			'slug'      => 'post',
			'rest_base' => 'posts',
		),
		array(
			'slug'      => 'wp_navigation',
			'rest_base' => 'navigation',
		),
		array(
			'slug'      => 'sp_book',
			'rest_base' => 'sp_book',
		),
	);

	/**
	 * Sets the connected URL and registers the HTTP mock.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE_SITE_URL );
		add_filter(
			'pre_http_request',
			array( $this, 'intercept_http_request' ),
			5,
			3
		);
	}

	/**
	 * Removes the HTTP mock and the connected-URL option.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'intercept_http_request' ),
			5
		);
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the mocked 200 response carrying the per-test body.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value (unused).
	 * @param array                $_args   HTTP request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return array|WP_Error Mock HTTP response or error.
	 */
	public function intercept_http_request(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): array|WP_Error {
		unset( $preempt );

		if ( str_contains( $url, '/safe-publish/v1/catalog/post-types' ) ) {
			++$this->catalog_requests;

			if ( null !== $this->mock_catalog_error ) {
				return $this->mock_catalog_error;
			}

			$post_types = $this->mock_post_types;
			foreach ( $post_types as &$post_type ) {
				if ( $this->mock_includes_raw_fields ) {
					$post_type['raw_fields'] = null !== $this->mock_raw_fields_override
						? $this->mock_raw_fields_override
						: array_keys( $this->mock_raw_fields );
				}
			}
			unset( $post_type );

			return $this->successful_response(
				(string) wp_json_encode( $post_types )
			);
		}

		return $this->successful_response( $this->mock_body );
	}

	/**
	 * Builds a successful mocked HTTP response.
	 *
	 * @param string $body Response body.
	 * @return array Mock HTTP response.
	 */
	private function successful_response( string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Builds a valid source post body for tests focused on type resolution.
	 *
	 * @param string $post_type     Source post type.
	 * @param string $title         Raw post title.
	 * @param string $content       Raw post content.
	 * @param bool   $with_excerpt Optional. Whether to emit excerpt.raw.
	 *                             Default true.
	 * @return string JSON-encoded source post body.
	 */
	private function raw_post_body(
		string $post_type,
		string $title,
		string $content,
		bool $with_excerpt = true
	): string {
		$body = array(
			'id'      => 123,
			'type'    => $post_type,
			'title'   => array( 'raw' => $title ),
			'content' => array( 'raw' => $content ),
		);

		if ( $with_excerpt ) {
			$body['excerpt'] = array( 'raw' => '' );
		}

		return (string) wp_json_encode( $body );
	}

	/**
	 * Fetches the mocked source item.
	 *
	 * @param string $post_type Post type slug or REST base.
	 * @return array|WP_Error Fresh content or an error.
	 */
	private function fetch( string $post_type = 'post' ): array|WP_Error {
		return ( new Source_Posts_API( new HTTP_Client() ) )
			->fetch_fresh_post_content(
				123,
				self::SOURCE_SITE_URL,
				array(),
				$post_type
			);
	}

	/**
	 * Verifies that a non-array response body yields the invalid-response
	 * error code rather than a bare false.
	 */
	public function test_non_array_body_returns_invalid_response_error(): void {
		// ARRANGE: The source returns a body that is not a JSON object.
		$this->mock_body = 'unexpected non-JSON body';

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: The distinct invalid-response code surfaces.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_invalid_response',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a response lacking the raw edit-context fields yields the
	 * raw-fields-missing error code rather than a bare false.
	 */
	public function test_missing_raw_fields_returns_raw_fields_error(): void {
		// ARRANGE: The source returns rendered fields but no raw ones.
		$this->mock_body = (string) wp_json_encode(
			array(
				'id'      => 123,
				'title'   => array( 'rendered' => 'Rendered Title' ),
				'content' => array( 'rendered' => '<p>Rendered content.</p>' ),
				'excerpt' => array( 'rendered' => '<p>Rendered excerpt.</p>' ),
			)
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: The distinct raw-fields-missing code surfaces.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_raw_fields_missing',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that an absent excerpt is normalized when the source post type
	 * does not support excerpts.
	 */
	public function test_unsupported_excerpt_is_normalized_to_empty(): void {
		// ARRANGE: The type omits excerpt support and its response omits the field.
		unset( $this->mock_raw_fields['excerpt'] );
		$this->mock_body = (string) wp_json_encode(
			array(
				'id'      => 123,
				'type'    => 'wp_navigation',
				'title'   => array( 'raw' => 'Main navigation' ),
				'content' => array( 'raw' => '<!-- wp:navigation-link /-->' ),
			)
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch( 'wp_navigation' );

		// ASSERT: The valid no-excerpt response succeeds without inventing data.
		$this->assertIsArray( $result );
		$this->assertSame( 'Main navigation', $result['title'] );
		$this->assertSame(
			'<!-- wp:navigation-link /-->',
			$result['content']
		);
		$this->assertSame( '', $result['excerpt'] );
	}

	/**
	 * Verifies that a present scalar field is rejected even when its route
	 * catalog does not declare a raw value.
	 */
	public function test_supported_scalar_excerpt_is_rejected(): void {
		// ARRANGE: Excerpt is a plain string omitted from catalog metadata.
		unset( $this->mock_raw_fields['excerpt'] );
		$this->mock_body = (string) wp_json_encode(
			array(
				'id'      => 123,
				'type'    => 'post',
				'title'   => array( 'raw' => 'Title' ),
				'content' => array( 'raw' => '<p>Content.</p>' ),
				'excerpt' => 'Possibly rendered excerpt',
			)
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: The nonstandard scalar is not treated as an unsupported field.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_raw_fields_missing',
			$result->get_error_code()
		);
		$this->assertStringContainsString(
			'supported fields: excerpt',
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that a response cannot select a different post type's catalog
	 * metadata to weaken raw-field validation.
	 */
	public function test_mismatched_response_post_type_is_rejected(): void {
		// ARRANGE: A post request receives a response claiming to be navigation.
		$this->mock_body = $this->raw_post_body(
			'wp_navigation',
			'Title',
			'<p>Content.</p>'
		);

		// ACT: Fetch the item requested as a post.
		$result = $this->fetch();

		// ASSERT: The response is rejected before its metadata is used.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_post_type_mismatch',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a failed catalog request returns a retryable catalog error.
	 */
	public function test_catalog_request_error_rejects_absent_field(): void {
		// ARRANGE: A complete post response cannot bypass a failed catalog.
		$this->mock_body          = $this->raw_post_body(
			'post',
			'Title',
			'<p>Content.</p>'
		);
		$this->mock_catalog_error = new WP_Error(
			'transport_down',
			'Metadata transport failed.'
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: The transport failure is distinct and retryable.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_catalog_unavailable',
			$result->get_error_code()
		);
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertGreaterThan( 0, $this->catalog_requests );
	}

	/**
	 * Verifies that catalog failure precedes response-shape validation.
	 */
	public function test_catalog_request_error_preserves_type_validation(): void {
		// ARRANGE: A post request receives a page-shaped response with an absent
		// excerpt, so the catalog is consulted and unavailable.
		$this->mock_body          = $this->raw_post_body(
			'page',
			'Title',
			'<p>Content.</p>',
			false
		);
		$this->mock_catalog_error = new WP_Error(
			'transport_down',
			'Metadata transport failed.'
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: No response-shape fallback is attempted.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_catalog_unavailable',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a custom REST base cannot bypass unavailable metadata.
	 */
	public function test_custom_rest_base_survives_catalog_failure(): void {
		// ARRANGE: A custom REST endpoint returns its canonical custom type while
		// both catalog attempts fail.
		$this->mock_body          = $this->raw_post_body(
			'sp_movie',
			'Movie',
			'<p>Movie content.</p>'
		);
		$this->mock_catalog_error = new WP_Error(
			'transport_down',
			'Metadata transport failed.'
		);

		// ACT: Fetch using the REST base rather than the custom type slug.
		$result = $this->fetch( 'sp_movies' );

		// ASSERT: No custom slug or response-shape fallback is used.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_catalog_unavailable',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that custom routing propagates catalog failure first.
	 */
	public function test_custom_rest_base_cannot_fallback_to_builtin_type(): void {
		// ARRANGE: An unresolved custom endpoint returns a valid page response.
		$this->mock_body          = $this->raw_post_body(
			'page',
			'Page',
			'<p>Page content.</p>'
		);
		$this->mock_catalog_error = new WP_Error(
			'transport_down',
			'Metadata transport failed.'
		);

		// ACT: Fetch the response through a custom REST base.
		$result = $this->fetch( 'sp_movies' );

		// ASSERT: Route construction stops at the catalog failure.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_catalog_unavailable',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a successful catalog remains authoritative when it does
	 * not map a requested custom REST base.
	 */
	public function test_custom_fallback_requires_catalog_failure(): void {
		// ARRANGE: The catalog succeeds without mapping sp_movies, while the
		// endpoint response claims an otherwise valid custom type.
		$this->mock_body = $this->raw_post_body(
			'sp_movie',
			'Movie',
			'<p>Movie content.</p>'
		);

		// ACT: Fetch through an endpoint absent from the successful catalog.
		$result = $this->fetch( 'sp_movies' );

		// ASSERT: The type is reported as unlisted, not as a response mismatch.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_post_type_unresolved',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that complete built-in data still requires current metadata.
	 */
	public function test_complete_builtin_response_requires_catalog(): void {
		// ARRANGE: A post response carrying title, content, and excerpt raw.
		$this->mock_body = $this->raw_post_body(
			'post',
			'Title',
			'<p>Content.</p>'
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: The import succeeds only after consulting the catalog.
		$this->assertIsArray( $result );
		$this->assertSame( 'Title', $result['title'] );
		$this->assertSame( 1, $this->catalog_requests );
	}

	/**
	 * Verifies that repeated catalog failures stop after the attempt cap so one
	 * unreachable source cannot cost a request per item in a bulk run.
	 */
	public function test_catalog_failures_stop_at_the_attempt_cap(): void {
		// ARRANGE: Every item omits its excerpt, and the catalog always fails.
		$this->mock_body          = $this->raw_post_body(
			'post',
			'Title',
			'<p>Content.</p>',
			false
		);
		$this->mock_catalog_error = new WP_Error(
			'transport_down',
			'Metadata transport failed.'
		);

		// ACT: Fetch five items from the same source in one request.
		for ( $i = 0; $i < 5; $i++ ) {
			$result = $this->fetch();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame(
				'fresh_content_catalog_unavailable',
				$result->get_error_code()
			);
		}

		// ASSERT: The catalog was attempted twice, not once per item.
		$this->assertSame( 2, $this->catalog_requests );
	}

	/**
	 * Verifies that catalog metadata requires raw values for every field it
	 * declares.
	 */
	public function test_catalog_requires_declared_raw_fields(): void {
		// ARRANGE: Catalog declares excerpt, but the edit response omits it.
		$this->mock_body = (string) wp_json_encode(
			array(
				'id'      => 123,
				'type'    => 'sp_book',
				'title'   => array( 'raw' => 'Book' ),
				'content' => array( 'raw' => '<p>Book content.</p>' ),
			)
		);

		// ACT: Fetch the custom post.
		$result = $this->fetch( 'sp_book' );

		// ASSERT: Catalog authority prevents a silent empty excerpt overwrite.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_raw_fields_missing',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that catalog entries from an older source are incompatible.
	 */
	public function test_catalog_requires_raw_fields_metadata(): void {
		// ARRANGE: A successful legacy catalog omits raw_fields metadata.
		$this->mock_includes_raw_fields = false;
		$this->mock_body                = $this->raw_post_body(
			'post',
			'Title',
			'<p>Content.</p>'
		);

		// ACT: Fetch complete fresh post data.
		$result = $this->fetch();

		// ASSERT: Legacy metadata is rejected before data is accepted.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_catalog_incompatible',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that malformed raw_fields metadata is an invalid catalog.
	 */
	public function test_catalog_rejects_malformed_raw_fields_metadata(): void {
		// ARRANGE: A successful catalog returns a scalar raw_fields value.
		$this->mock_raw_fields_override = 'title';
		$this->mock_body                = $this->raw_post_body(
			'post',
			'Title',
			'<p>Content.</p>'
		);

		// ACT: Fetch complete fresh post data.
		$result = $this->fetch();

		// ASSERT: Malformed metadata has its own definitive error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_catalog_invalid',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a custom REST base resolves back to the authoritative type
	 * slug before response-type validation and metadata lookup.
	 */
	public function test_custom_rest_base_resolves_to_response_post_type(): void {
		// ARRANGE: The catalog maps sp_movie to its distinct sp_movies REST base.
		$this->mock_post_types = array(
			array(
				'slug'      => 'sp_movie',
				'rest_base' => 'sp_movies',
			),
		);
		$this->mock_body       = $this->raw_post_body(
			'sp_movie',
			'Movie',
			'<p>Movie content.</p>'
		);

		// ACT: Fetch using the custom REST base rather than the type slug.
		$result = $this->fetch( 'sp_movies' );

		// ASSERT: The catalog-backed reverse resolution accepts the response.
		$this->assertIsArray( $result );
		$this->assertSame( 'Movie', $result['title'] );
		$this->assertSame( '<p>Movie content.</p>', $result['content'] );
	}
}
