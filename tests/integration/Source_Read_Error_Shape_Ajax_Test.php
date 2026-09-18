<?php
/**
 * Integration tests for the shape the listing's source reads report failures in
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_Ajax_UnitTestCase;

/**
 * Verifies that both Manage-listing reads answer a source failure with the
 * structured detail the notice needs to isolate the source's own wording, and
 * still answer a local failure with a bare message.
 */
class Source_Read_Error_Shape_Ajax_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Source both reads are pointed at.
	 */
	private const SOURCE = 'https://source.example.com';

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * Wording the source site reports, embedded in the plugin's sentence.
	 */
	private const UPSTREAM_REASON = 'The source refused the request.';

	/**
	 * Signs in as an administrator and stubs the source transport.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Required by validate_auth() on both read paths.
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'administrator' ) )
		);

		add_filter( 'pre_http_request', array( $this, 'refuse_request' ), 1, 3 );
	}

	/**
	 * Removes the transport stub.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'refuse_request' ), 1 );
		parent::tearDown();
	}

	/**
	 * Answers every source request with a REST-shaped 401.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $_args   Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed Canned response, or the untouched short-circuit value.
	 */
	public function refuse_request( mixed $preempt, array $_args, string $url ): mixed {
		if ( ! str_starts_with( $url, self::SOURCE ) ) {
			return $preempt;
		}

		return array(
			'response' => array(
				'code'    => 401,
				'message' => 'Unauthorized',
			),
			'body'     => (string) wp_json_encode(
				array( 'message' => self::UPSTREAM_REASON )
			),
			'headers'  => array(),
		);
	}

	/**
	 * Verifies that the posts read carries structured source detail.
	 */
	public function test_list_posts_failure_carries_structured_source_detail(): void {
		// ARRANGE + ACT: Request the All list against the refusing source.
		$response = $this->dispatch(
			'safe_publish_list_posts',
			array(
				'source_site_url' => self::SOURCE,
				'state'           => 'all',
			)
		);

		// ASSERT: The notice gets the sentence and the halves it is built from.
		$this->assert_isolatable_source_error( $response );
	}

	/**
	 * Verifies that the post-types read carries structured source detail.
	 */
	public function test_post_types_failure_carries_structured_source_detail(): void {
		// ARRANGE + ACT: Request the type list against the refusing source.
		$response = $this->dispatch(
			'safe_publish_fetch_post_types',
			array( 'source_site_url' => self::SOURCE )
		);

		// ASSERT: The notice gets the sentence and the halves it is built from.
		$this->assert_isolatable_source_error( $response );
	}

	/**
	 * Verifies that a failure raised before any source request keeps the bare
	 * message shape the client has always read.
	 */
	public function test_local_failure_stays_a_bare_message(): void {
		// ARRANGE + ACT: Ask for post types without naming a source.
		$response = $this->dispatch(
			'safe_publish_fetch_post_types',
			array( 'source_site_url' => '' )
		);

		// ASSERT: Still a string, so no client path loses its message.
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Source site URL is required.', $response['data'] );
	}

	/**
	 * Asserts a response carries the flat sentence and the pair composing it.
	 *
	 * @param array $response Decoded AJAX response.
	 */
	private function assert_isolatable_source_error( array $response ): void {
		$this->assertFalse( $response['success'] );
		$this->assertSame(
			'Source site returned HTTP error 401. ' . self::UPSTREAM_REASON,
			$response['data']['message']
		);
		$this->assertSame(
			array(
				'message'  => self::UPSTREAM_REASON,
				'template' => 'Source site returned HTTP error 401. <reason />',
			),
			$response['data']['source_error']
		);
	}

	/**
	 * Dispatches an AJAX action and decodes its response.
	 *
	 * @param string $action AJAX action slug.
	 * @param array  $params Request parameters beyond the nonce.
	 * @return array Decoded AJAX response.
	 */
	private function dispatch( string $action, array $params ): array {
		$_POST = array_merge(
			array( 'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ) ),
			$params
		);

		// _handleAjax appends, so clear it to keep repeat calls decodable.
		$this->_last_response = '';

		$this->dispatch_ajax_expecting_die( $action );

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );

		return $response;
	}
}
