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
	 * Raw fields returned by the mocked source route schema.
	 *
	 * @var array<string, bool>
	 */
	private array $mock_raw_fields = array(
		'title'   => true,
		'content' => true,
		'excerpt' => true,
	);

	/**
	 * Optional error returned by the mocked schema request.
	 *
	 * @var array|WP_Error|null
	 */
	private array|WP_Error|null $mock_schema_error = null;

	/**
	 * Post types returned by the mocked Safe Publish catalog.
	 *
	 * @var list<array<string, string>>
	 */
	private array $mock_post_types = array();

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
	 * @param array                $args    HTTP request arguments.
	 * @param string               $url     Request URL (unused).
	 * @return array|WP_Error Mock HTTP response or error.
	 */
	public function intercept_http_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): array|WP_Error {
		unset( $preempt );

		if ( str_contains( $url, '/safe-publish/v1/catalog/post-types' ) ) {
			return $this->successful_response(
				(string) wp_json_encode( $this->mock_post_types )
			);
		}
		if ( 'OPTIONS' === ( $args['method'] ?? 'GET' ) ) {
			if ( null !== $this->mock_schema_error ) {
				return $this->mock_schema_error;
			}

			$properties = array();
			foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
				if ( array_key_exists( $field, $this->mock_raw_fields ) ) {
					$properties[ $field ] = array(
						'properties' => array(
							'raw' => array( 'type' => 'string' ),
						),
					);
				}
			}
			return $this->successful_response(
				(string) wp_json_encode(
					array( 'schema' => array( 'properties' => $properties ) )
				)
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
	 * schema does not declare a raw value.
	 */
	public function test_supported_scalar_excerpt_is_rejected(): void {
		// ARRANGE: Excerpt is a plain string omitted from the raw-field schema.
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
	 * Verifies that a response cannot select a different post type's route
	 * schema to weaken raw-field validation.
	 */
	public function test_mismatched_response_post_type_is_rejected(): void {
		// ARRANGE: A post request receives a response claiming to be navigation.
		$this->mock_body = (string) wp_json_encode(
			array(
				'id'      => 123,
				'type'    => 'wp_navigation',
				'title'   => array( 'raw' => 'Title' ),
				'content' => array( 'raw' => '<p>Content.</p>' ),
			)
		);

		// ACT: Fetch the item requested as a post.
		$result = $this->fetch();

		// ASSERT: The response is rejected before its schema is consulted.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_post_type_mismatch',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that source metadata request failures retain their transport
	 * error semantics.
	 */
	public function test_schema_request_error_is_propagated(): void {
		// ARRANGE: The post is valid, but its route schema request cannot run.
		$this->mock_body         = (string) wp_json_encode(
			array(
				'id'      => 123,
				'type'    => 'post',
				'title'   => array( 'raw' => 'Title' ),
				'content' => array( 'raw' => '<p>Content.</p>' ),
				'excerpt' => array( 'raw' => '' ),
			)
		);
		$this->mock_schema_error = new WP_Error(
			'transport_down',
			'Metadata transport failed.'
		);

		// ACT: Fetch fresh content for import.
		$result = $this->fetch();

		// ASSERT: HTTP_Client's transport error is preserved, not remapped to 502.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( HTTP_Client::ERROR_REQUEST_FAILED, $result->get_error_code() );
	}

	/**
	 * Verifies that the route schema requires raw values for every
	 * field it declares.
	 */
	public function test_route_schema_requires_declared_raw_fields(): void {
		// ARRANGE: Schema declares excerpt, but the edit response omits it.
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

		// ASSERT: Schema authority prevents a silent empty excerpt overwrite.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_raw_fields_missing',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that a custom REST base resolves back to the authoritative type
	 * slug before response-type validation and schema lookup.
	 */
	public function test_custom_rest_base_resolves_to_response_post_type(): void {
		// ARRANGE: The catalog maps sp_movie to its distinct sp_movies REST base.
		$this->mock_post_types = array(
			array(
				'slug'      => 'sp_movie',
				'rest_base' => 'sp_movies',
			),
		);
		$this->mock_body       = (string) wp_json_encode(
			array(
				'id'      => 123,
				'type'    => 'sp_movie',
				'title'   => array( 'raw' => 'Movie' ),
				'content' => array( 'raw' => '<p>Movie content.</p>' ),
				'excerpt' => array( 'raw' => '' ),
			)
		);

		// ACT: Fetch using the custom REST base rather than the type slug.
		$result = $this->fetch( 'sp_movies' );

		// ASSERT: The catalog-backed reverse resolution accepts the response.
		$this->assertIsArray( $result );
		$this->assertSame( 'Movie', $result['title'] );
		$this->assertSame( '<p>Movie content.</p>', $result['content'] );
	}
}
