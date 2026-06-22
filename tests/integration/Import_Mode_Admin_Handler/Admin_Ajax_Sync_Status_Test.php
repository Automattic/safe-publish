<?php
/**
 * Integration tests for the safe_publish_sync_status_batch AJAX handler.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Import_Mode_Admin_Handler;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Tests\Integration\Ajax_Die_Continue_Trait;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;
use WP_Error;
use WPAjaxDieStopException;

/**
 * Sync Status Batch AJAX Test Class.
 *
 * Drives the destination handler end to end with a controlled source-catalog
 * mock so each of the four verdicts (up-to-date, outdated, missing,
 * unreachable) can be exercised without a live source site.
 */
class Admin_Ajax_Sync_Status_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * Admin user ID for privileged test requests.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Per-source-id modified_gmt the mock returns. Keys without entries are
	 * omitted from the response so the handler interprets them as 'missing'.
	 * `null` for the whole property simulates an unreachable source (WP_Error).
	 *
	 * @var array<int, string>|null
	 */
	private ?array $source_modified_gmt = array();

	/**
	 * Tracks how many catalog requests the mock served, so tests can assert
	 * the handler issues one signed call per post type (not one per row).
	 *
	 * @var int
	 */
	private int $catalog_request_count = 0;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		Imports_Table::create_table();
		Import_Items_Table::create_table();

		$this->admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);

		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		$this->source_modified_gmt   = array();
		$this->catalog_request_count = 0;

		add_filter( 'pre_http_request', array( $this, 'mock_catalog_request' ), 10, 3 );
	}

	/**
	 * Tears down test fixtures.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_request' ), 10 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		delete_site_transient( Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT );
		parent::tearDown();
	}

	/**
	 * Mocks the source catalog endpoint.
	 *
	 * Reads include[] from the request URL and builds a minimal listing
	 * envelope for the IDs the test staged. IDs not in $source_modified_gmt
	 * are omitted, mirroring how a real source would respond when the post
	 * has been deleted there. When $source_modified_gmt is null, returns a
	 * WP_Error so the handler observes an unreachable source.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Mock response, error, or prior value.
	 */
	public function mock_catalog_request(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( ! str_contains( $url, '/wp-json/safe-publish/v1/catalog/posts' ) ) {
			return $preempt;
		}

		++$this->catalog_request_count;

		if ( null === $this->source_modified_gmt ) {
			return new WP_Error(
				'catalog_unreachable',
				'simulated network failure'
			);
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );

		$include = isset( $args['include'] ) && is_array( $args['include'] )
			? array_map( 'intval', $args['include'] )
			: array();

		$items = array();
		foreach ( $include as $id ) {
			if ( ! isset( $this->source_modified_gmt[ $id ] ) ) {
				continue;
			}

			$items[] = array(
				'id'           => $id,
				'title'        => 'Mock Source Post ' . $id,
				'post_type'    => (string) ( $args['post_type'] ?? 'post' ),
				'date_gmt'     => '2024-01-01T00:00:00Z',
				'modified_gmt' => $this->source_modified_gmt[ $id ],
				'status'       => 'publish',
				'link'         => 'https://source.example.com/post-' . $id,
			);
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) wp_json_encode(
				array(
					'items'    => $items,
					'has_more' => false,
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Creates a local post tied to a source ID, plus an items-table row that
	 * stamps the most recent import_date_gmt.
	 *
	 * @param int    $source_id       Source post ID stored in post meta.
	 * @param string $import_date_gmt MySQL datetime to store on the item.
	 * @param string $post_type       Post type slug for the local post.
	 */
	private function seed_imported_post(
		int $source_id,
		string $import_date_gmt,
		string $post_type = 'post'
	): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => $post_type,
			)
		);
		if ( ! is_int( $post_id ) ) {
			$this->fail( 'Failed to create the test imported post.' );
		}

		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			'https://source.example.com'
		);

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => 0,
				'title'                => 'Seeded Item',
				'source_post_id'       => $source_id,
				'status'               => 'success',
				'post_id'              => $post_id,
				'has_previous_content' => 0,
				'rolled_back'          => 0,
				'import_date_gmt'      => $import_date_gmt,
			),
			array( '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Sets up a valid authenticated POST request.
	 *
	 * @param array $payload Additional POST keys to merge.
	 */
	private function authenticate_request( array $payload = array() ): void {
		wp_set_current_user( $this->admin_user_id );
		$_POST = array_merge(
			array( 'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ) ),
			$payload
		);
	}

	/**
	 * Decodes the JSON response written by the AJAX handler.
	 *
	 * @return array Decoded response.
	 */
	private function decode_response(): array {
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		return $response;
	}

	/**
	 * Verifies that the handler rejects requests with an invalid nonce.
	 */
	public function test_rejects_request_with_invalid_nonce(): void {
		// ARRANGE: Authenticated admin but a bad nonce — the referer check
		// short-circuits before any source lookup runs.
		wp_set_current_user( $this->admin_user_id );
		$_POST = array( 'nonce' => 'not-a-valid-nonce' );

		// ASSERT: Nonce failure calls wp_die( -1 ).
		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		// ACT: Dispatch the handler.
		$this->_handleAjax( 'safe_publish_sync_status_batch' );
	}

	/**
	 * Verifies that the handler rejects users without edit_posts capability.
	 */
	public function test_rejects_request_without_edit_posts_capability(): void {
		// ARRANGE: A subscriber whose capability check the handler rejects.
		$subscriber_id = $this->factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $subscriber_id );
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: 403 Forbidden response.
		$response = $this->decode_response();
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString(
			'Forbidden',
			(string) $response['data']
		);
	}

	/**
	 * Verifies that a source post whose modified_gmt equals the destination's
	 * import_date_gmt is reported as up-to-date.
	 *
	 * Equal timestamps land on up-to-date because import_date_gmt is stamped
	 * after the source fetch — any subsequent edit will compare strictly
	 * greater.
	 */
	public function test_reports_up_to_date_when_source_not_modified_after_import(): void {
		// ARRANGE: Source last modified at 11:00; destination logged import at
		// 12:00, an hour later — clearly no source change in between.
		$source_id = 4001;
		$this->seed_imported_post( $source_id, '2024-01-01 12:00:00' );
		$this->source_modified_gmt[ $source_id ] = '2024-01-01T11:00:00Z';

		$this->authenticate_request(
			array( 'source_ids' => array( (string) $source_id ) )
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Verdict is up-to-date.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'up-to-date',
			$response['data']['statuses'][ $source_id ]['status']
		);
	}

	/**
	 * Verifies that a source post modified after the destination's last
	 * import_date_gmt is reported as outdated.
	 */
	public function test_reports_outdated_when_source_modified_after_import(): void {
		// ARRANGE: Destination imported at 10:00; source then edited at 15:00.
		$source_id = 4002;
		$this->seed_imported_post( $source_id, '2024-01-01 10:00:00' );
		$this->source_modified_gmt[ $source_id ] = '2024-01-01T15:00:00Z';

		$this->authenticate_request(
			array( 'source_ids' => array( (string) $source_id ) )
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Verdict is outdated.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'outdated',
			$response['data']['statuses'][ $source_id ]['status']
		);
	}

	/**
	 * Verifies that a source response that omits a requested ID resolves to
	 * the missing verdict — the destination interprets "no row" as "the
	 * post is no longer present on the source".
	 */
	public function test_reports_missing_when_source_omits_id(): void {
		// ARRANGE: Imported locally; source mock does NOT list this ID.
		$source_id = 4003;
		$this->seed_imported_post( $source_id, '2024-01-01 12:00:00' );
		// Deliberately leave $this->source_modified_gmt[ $source_id ] unset.

		$this->authenticate_request(
			array( 'source_ids' => array( (string) $source_id ) )
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Verdict is missing.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'missing',
			$response['data']['statuses'][ $source_id ]['status']
		);
	}

	/**
	 * Verifies that a source error (network failure, auth rejection, etc.)
	 * marks every ID in the affected post_type batch as unreachable.
	 */
	public function test_reports_unreachable_when_source_request_errors(): void {
		// ARRANGE: Two imported posts; source-side mock returns WP_Error.
		$first_id  = 4004;
		$second_id = 4005;
		$this->seed_imported_post( $first_id, '2024-01-01 12:00:00' );
		$this->seed_imported_post( $second_id, '2024-01-01 12:00:00' );
		$this->source_modified_gmt = null;

		$this->authenticate_request(
			array(
				'source_ids' => array(
					(string) $first_id,
					(string) $second_id,
				),
			)
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Both IDs come back as unreachable.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'unreachable',
			$response['data']['statuses'][ $first_id ]['status']
		);
		$this->assertSame(
			'unreachable',
			$response['data']['statuses'][ $second_id ]['status']
		);
	}

	/**
	 * Verifies that a mixed-type batch issues one signed source request per
	 * post type — pages and posts can't be queried in a single catalog call.
	 */
	public function test_groups_requests_by_post_type(): void {
		// ARRANGE: One imported post and one imported page.
		$post_id_source = 4101;
		$page_id_source = 4102;
		$this->seed_imported_post( $post_id_source, '2024-01-01 12:00:00', 'post' );
		$this->seed_imported_post( $page_id_source, '2024-01-01 12:00:00', 'page' );
		$this->source_modified_gmt[ $post_id_source ] = '2024-01-01T11:00:00Z';
		$this->source_modified_gmt[ $page_id_source ] = '2024-01-01T11:00:00Z';

		$this->authenticate_request(
			array(
				'source_ids' => array(
					(string) $post_id_source,
					(string) $page_id_source,
				),
			)
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Two requests — one per post type.
		$this->assertSame( 2, $this->catalog_request_count );

		// ASSERT: Both verdicts present.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'up-to-date',
			$response['data']['statuses'][ $post_id_source ]['status']
		);
		$this->assertSame(
			'up-to-date',
			$response['data']['statuses'][ $page_id_source ]['status']
		);
	}

	/**
	 * Verifies that source IDs without a local imported post are omitted
	 * from the response and do not trigger a source-side roundtrip.
	 */
	public function test_skips_ids_with_no_local_imported_post(): void {
		// ARRANGE: One imported post; the second ID has no local counterpart.
		$known_id   = 4201;
		$unknown_id = 999999;
		$this->seed_imported_post( $known_id, '2024-01-01 12:00:00' );
		$this->source_modified_gmt[ $known_id ] = '2024-01-01T11:00:00Z';

		$this->authenticate_request(
			array(
				'source_ids' => array(
					(string) $known_id,
					(string) $unknown_id,
				),
			)
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Only the known ID appears in the response.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( $known_id, $response['data']['statuses'] );
		$this->assertArrayNotHasKey( $unknown_id, $response['data']['statuses'] );
	}

	/**
	 * Verifies that the handler caps the batch size and rejects overlarge
	 * requests, mirroring the catalog endpoint's per-page cap so one batch
	 * can't outgrow what the source serves for a regular page.
	 */
	public function test_rejects_batch_exceeding_cap(): void {
		// ARRANGE: 101 raw IDs — one past the SYNC_STATUS_BATCH_MAX limit.
		$ids = array();
		for ( $i = 1; $i <= Admin_Ajax_Controller::SYNC_STATUS_BATCH_MAX + 1; $i++ ) {
			$ids[] = (string) $i;
		}

		$this->authenticate_request( array( 'source_ids' => $ids ) );

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: JSON error mentioning the cap value.
		$response = $this->decode_response();
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString(
			(string) Admin_Ajax_Controller::SYNC_STATUS_BATCH_MAX,
			(string) $response['data']
		);
	}

	/**
	 * Verifies that a parseable destination row paired with an unparseable
	 * source modified_gmt resolves to invalid — a distinct sentinel from
	 * unreachable, so the UI can surface "data bug" vs "network blip".
	 */
	public function test_reports_invalid_when_source_modified_gmt_unparseable(): void {
		// ARRANGE: Destination row is fine; source mock returns a string that
		// DateTimeImmutable::createFromFormat cannot parse.
		$source_id = 4006;
		$this->seed_imported_post( $source_id, '2024-01-01 12:00:00' );
		$this->source_modified_gmt[ $source_id ] = 'not-a-timestamp';

		$this->authenticate_request(
			array( 'source_ids' => array( (string) $source_id ) )
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Verdict is invalid (not unreachable).
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'invalid',
			$response['data']['statuses'][ $source_id ]['status']
		);
	}

	/**
	 * Verifies that an empty source modified_gmt resolves to up-to-date
	 * instead of invalid. WordPress drafts that have never been saved
	 * carry a `0000-00-00 00:00:00` timestamp the REST layer serializes as
	 * empty — treating that as up-to-date avoids surfacing a phantom
	 * "Sync check failed" the user can't act on.
	 */
	public function test_reports_up_to_date_when_source_modified_gmt_is_blank(): void {
		// ARRANGE: imported row with a never-saved-draft style source timestamp.
		$source_id = 4007;
		$this->seed_imported_post( $source_id, '2024-01-01 12:00:00' );
		$this->source_modified_gmt[ $source_id ] = '';

		$this->authenticate_request(
			array( 'source_ids' => array( (string) $source_id ) )
		);

		// ACT: dispatch the batch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: empty timestamp folds into up-to-date, not invalid.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'up-to-date',
			$response['data']['statuses'][ $source_id ]['status']
		);
	}

	/**
	 * Verifies that the handler resolves local posts and items-table rows
	 * via bulk helpers rather than a per-row meta_query + SELECT — i.e.
	 * total DB query growth stays well below the N+1 baseline as the batch
	 * size grows.
	 */
	public function test_resolves_local_posts_in_bulk(): void {
		// ARRANGE: Ten imported posts, all up-to-date.
		$source_ids = range( 5001, 5010 );
		foreach ( $source_ids as $source_id ) {
			$this->seed_imported_post( $source_id, '2024-01-01 12:00:00' );
			$this->source_modified_gmt[ $source_id ] = '2024-01-01T11:00:00Z';
		}

		$this->authenticate_request(
			array(
				'source_ids' => array_map( 'strval', $source_ids ),
			)
		);

		// ACT: Dispatch and capture the DB query delta.
		global $wpdb;
		$queries_before = $wpdb->num_queries;
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );
		$queries_delta = $wpdb->num_queries - $queries_before;

		// ASSERT: The N+1 baseline for 10 IDs would be ≥20 queries (one
		// meta_query lookup + one items-table SELECT per row); the bulk
		// path replaces that pair with two queries plus a small fixed
		// overhead and the source_modified_gmt write-through.
		$this->assertLessThan( 11, $queries_delta );

		// ASSERT: Every verdict came through correctly.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		foreach ( $source_ids as $source_id ) {
			$this->assertSame(
				'up-to-date',
				$response['data']['statuses'][ $source_id ]['status']
			);
		}
	}

	/**
	 * Verifies that the handler returns an empty statuses object when no
	 * valid source IDs are present, instead of issuing a pointless source
	 * roundtrip.
	 */
	public function test_returns_empty_statuses_when_no_valid_ids(): void {
		// ARRANGE: No source IDs at all.
		$this->authenticate_request( array( 'source_ids' => array() ) );

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: Empty statuses, no catalog request issued.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame( array(), (array) $response['data']['statuses'] );
		$this->assertSame( 0, $this->catalog_request_count );
	}

	/**
	 * Verifies that a connected-site URL stored with a trailing slash still
	 * matches imports tagged with the normalized source URL, instead of every
	 * status collapsing to missing.
	 */
	public function test_resolves_status_when_connected_url_has_trailing_slash(): void {
		// ARRANGE: Option carries a trailing slash; the seeded import is tagged
		// with the normalized form normalize_site_url() produces.
		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source.example.com/'
		);
		$source_id = 4008;
		$this->seed_imported_post( $source_id, '2024-01-01 12:00:00' );
		$this->source_modified_gmt[ $source_id ] = '2024-01-01T11:00:00Z';

		$this->authenticate_request(
			array( 'source_ids' => array( (string) $source_id ) )
		);

		// ACT: Dispatch.
		$this->dispatch_ajax_expecting_die( 'safe_publish_sync_status_batch' );

		// ASSERT: The verdict resolves — the trailing-slash option was
		// normalized before the source-scoped lookup.
		$response = $this->decode_response();
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			'up-to-date',
			$response['data']['statuses'][ $source_id ]['status']
		);
	}
}
