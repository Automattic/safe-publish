<?php
/**
 * Integration tests for Basic Auth outbound request handling.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Basic Auth Outbound Test.
 *
 * Tests that the full wp_options → Auth_Credential_Provider → HTTP_Client
 * → outbound request chain correctly includes both HMAC and Basic Auth
 * headers when credentials are configured, and that imports succeed or fail
 * accordingly.
 */
class Basic_Auth_Outbound_Test extends Integration_Test_Case {

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'basic-auth-integration-test-secret-32c';

	/**
	 * Source site URL used in all outbound requests.
	 */
	private const SOURCE_SITE_URL = 'https://source.example.com';

	/**
	 * Captured HTTP request arguments from the most recent outbound request.
	 *
	 * @var array|null
	 */
	private ?array $captured_request_args = null;

	/**
	 * HTTP status code the mock will return. Defaults to 200.
	 *
	 * @var int
	 */
	private int $mock_status_code = 200;

	/**
	 * Post import service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Sets up each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		$this->captured_request_args = null;
		$this->mock_status_code      = 200;

		$this->repository = new History_Repository();

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer )
		);

		$this->import_service = new Post_Import_Service(
			new External_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager()
		);

		add_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5, 3 );

		// Configure the connected site URL so fetch_fresh_content() can make requests.
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE_SITE_URL );
	}

	/**
	 * Tears down after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http_request' ), 5 );
		delete_option( Options::OPTION_USERNAME );
		delete_option( Options::OPTION_PASSWORD );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Verifies that fetch_posts sends both HMAC and Basic Auth headers when
	 * credentials are fully configured in plugin options, and returns one post.
	 */
	public function test_fetch_posts_sends_both_hmac_and_basic_auth_headers(): void {
		// ARRANGE: Configure both username and password in plugin options.
		update_option( Options::OPTION_USERNAME, 'testuser' );
		update_option( Options::OPTION_PASSWORD, 'testpass' );

		// ACT: Fetch posts using credentials sourced from plugin options.
		$credentials = Auth_Credential_Provider::get_credentials();
		$result      = ( new External_Posts_API( new HTTP_Client() ) )->fetch_posts( self::SOURCE_SITE_URL, 1, $credentials );

		// ASSERT: Both HMAC and Basic Auth headers were sent in the outbound request.
		$this->assertNotNull( $this->captured_request_args, 'HTTP request should have been intercepted.' );

		$headers = $this->captured_request_args['headers'] ?? array();

		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $headers, 'HMAC signature header should be present.' );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			$headers['X-Safe-Publish-Signature'] ?? '',
			'HMAC signature should be a 64-character lowercase hex string (SHA-256).'
		);

		$this->assertSame(
			'Basic ' . base64_encode( 'testuser:testpass' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$headers['Authorization'] ?? null,
			'Basic Auth Authorization header should be correctly encoded.'
		);

		$this->assertIsArray( $result, 'fetch_posts() should return an array of posts.' );
		$this->assertCount( 1, $result, 'fetch_posts() should return exactly one post.' );
	}

	/**
	 * Verifies that fetch_posts sends only HMAC headers and no Basic Auth
	 * header when no credentials are configured in plugin options, and still
	 * returns the expected posts.
	 */
	public function test_fetch_posts_sends_only_hmac_headers_without_basic_auth_credentials(): void {
		// ARRANGE: No credentials configured (options are empty).

		// ACT: Fetch posts without Basic Auth credentials.
		$credentials = Auth_Credential_Provider::get_credentials();
		$result      = ( new External_Posts_API( new HTTP_Client() ) )->fetch_posts( self::SOURCE_SITE_URL, 1, $credentials );

		// ASSERT: HMAC header is present but no Authorization header was sent.
		$this->assertNotNull( $this->captured_request_args, 'HTTP request should have been intercepted.' );

		$headers = $this->captured_request_args['headers'] ?? array();

		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $headers, 'HMAC signature header should be present.' );
		$this->assertArrayNotHasKey( 'Authorization', $headers, 'Authorization header should be absent without credentials.' );

		$this->assertIsArray( $result, 'fetch_posts() should return an array of posts.' );
		$this->assertCount( 1, $result, 'fetch_posts() should return exactly one post.' );
	}

	/**
	 * Verifies that fetch_posts omits the Basic Auth header when only a
	 * username is configured but no password, and still returns the expected
	 * posts.
	 */
	public function test_fetch_posts_omits_basic_auth_with_partial_credentials(): void {
		// ARRANGE: Only a username is configured; no password.
		update_option( Options::OPTION_USERNAME, 'testuser' );

		// ACT: Fetch posts with incomplete credentials.
		$credentials = Auth_Credential_Provider::get_credentials();
		$result      = ( new External_Posts_API( new HTTP_Client() ) )->fetch_posts( self::SOURCE_SITE_URL, 1, $credentials );

		// ASSERT: No Authorization header was sent because the password is missing.
		$this->assertNotNull( $this->captured_request_args, 'HTTP request should have been intercepted.' );

		$headers = $this->captured_request_args['headers'] ?? array();

		$this->assertArrayNotHasKey( 'Authorization', $headers, 'Authorization header should be absent with partial credentials.' );

		$this->assertIsArray( $result, 'fetch_posts() should still return posts when Basic Auth is not sent.' );
		$this->assertCount( 1, $result, 'fetch_posts() should return exactly one post even without Basic Auth.' );
	}

	/**
	 * Verifies that a full import completes successfully when HMAC and Basic
	 * Auth credentials are both configured and the source site returns posts.
	 */
	public function test_full_import_succeeds_with_hmac_and_basic_auth(): void {
		// ARRANGE: Configure both username and password in plugin options, then
		// fetch posts from the (mocked) source site.
		update_option( Options::OPTION_USERNAME, 'testuser' );
		update_option( Options::OPTION_PASSWORD, 'testpass' );

		$credentials = Auth_Credential_Provider::get_credentials();
		$posts       = ( new External_Posts_API( new HTTP_Client() ) )->fetch_posts( self::SOURCE_SITE_URL, 1, $credentials );

		$this->assertIsArray( $posts, 'fetch_posts() should return an array when the source responds with 200.' );
		$this->assertCount( 1, $posts, 'fetch_posts() should return exactly one post.' );

		// ACT: Import the fetched post.
		$session_id = $this->repository->create_session( self::SOURCE_SITE_URL, 'bulk' );
		$result     = $this->import_service->import_post( $posts[0], $session_id );

		// ASSERT: Import succeeded and a WP post was created in the database.
		$this->assertTrue( $result['success'], 'Import should succeed with valid credentials.' );
		$this->assertIsInt( $result['post_id'], 'Import result should contain an integer post ID.' );

		$post = get_post( $result['post_id'] );
		$this->assertNotNull( $post, 'A WP post should have been created in the database.' );
		$this->assertSame( 'Test Post', $post->post_title, 'The created post title should match the fetched post.' );
	}

	/**
	 * Verifies that fetch_posts returns a WP_Error when the source site
	 * rejects the request with a 401 Unauthorized response.
	 */
	public function test_fetch_posts_fails_when_source_site_returns_401(): void {
		// ARRANGE: Configure incorrect credentials and set mock to return 401.
		update_option( Options::OPTION_USERNAME, 'wronguser' );
		update_option( Options::OPTION_PASSWORD, 'wrongpass' );

		$this->mock_status_code = 401;

		// ACT: Attempt to fetch posts with credentials the source site rejects.
		$credentials = Auth_Credential_Provider::get_credentials();
		$result      = ( new External_Posts_API( new HTTP_Client() ) )->fetch_posts( self::SOURCE_SITE_URL, 1, $credentials );

		// ASSERT: A WP_Error is returned; no posts were fetched.
		$this->assertInstanceOf( \WP_Error::class, $result, 'fetch_posts() should return WP_Error on 401.' );
		$this->assertSame( 'http_error', $result->get_error_code(), 'Error code should identify an HTTP-level failure.' );
	}

	/**
	 * Intercepts outbound HTTP requests, captures the request arguments, and
	 * returns a mock response controlled by $mock_status_code.
	 *
	 * @param false|array|\WP_Error $preempt Preemptive return value.
	 * @param array                 $args    HTTP request arguments.
	 * @param string                $url     Request URL.
	 * @return array Mock HTTP response.
	 */
	public function intercept_http_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): array {
		unset( $preempt );

		$this->captured_request_args = $args;

		if ( 200 !== $this->mock_status_code ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => $this->mock_status_code,
					'message' => get_status_header_desc( $this->mock_status_code ),
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// Single-post endpoint used by fetch_fresh_content(): return a post object.
		if ( preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'id'             => 1,
						'link'           => 'https://source.example.com/test-post',
						'title'          => array( 'raw' => 'Test Post' ),
						'modified'       => '2026-01-01T00:00:00',
						'featured_media' => 0,
						'content'        => array( 'raw' => '<p>Test content.</p>' ),
						'excerpt'        => array( 'raw' => '' ),
						'slug'           => 'test-post',
						'comment_status' => 'open',
						'ping_status'    => 'open',
						'menu_order'     => 0,
						'meta'           => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		// Posts list endpoint: return an array of posts.
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					array(
						'id'             => 1,
						'link'           => 'https://source.example.com/test-post',
						'title'          => array( 'raw' => 'Test Post' ),
						'modified'       => '2026-01-01T00:00:00',
						'featured_media' => 0,
						'content'        => array( 'raw' => '<p>Test content.</p>' ),
						'excerpt'        => array( 'raw' => '' ),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
