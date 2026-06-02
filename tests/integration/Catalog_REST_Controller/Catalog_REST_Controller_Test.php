<?php
/**
 * Integration tests for the source-side Catalog_REST_Controller.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Catalog_REST_Controller;

use Safe_Publish\API\Catalog_REST_Controller;
use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Catalog REST Controller integration tests.
 *
 * Exercises the controller in-process via the REST server: HMAC permission
 * gate, search/filter/sort behavior, has_more derivation, post-type
 * restriction, and the listing payload shape.
 */
class Catalog_REST_Controller_Test extends WP_UnitTestCase {

	/**
	 * REST server used to dispatch routes.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * HMAC authenticator under test.
	 *
	 * @var HMAC_Authenticator
	 */
	private HMAC_Authenticator $authenticator;

	/**
	 * Registers the controller and a fresh REST server for each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			'integration-test-secret-key-32chars-ok',
			home_url()
		);

		( new Catalog_REST_Controller( $this->authenticator ) )->init();

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );
	}

	/**
	 * Resets the global REST server between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Verifies that the route is unreachable when the request has not been
	 * authenticated by HMAC.
	 */
	public function test_request_without_hmac_auth_is_rejected(): void {
		// ARRANGE: Authenticator stays in its default unauthenticated state.

		// ACT: Dispatch a catalog request.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/safe-publish/v1/catalog/posts' )
		);

		// ASSERT: REST core returns a permission error (401/403).
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Verifies that an HMAC-authenticated request succeeds and the envelope
	 * carries the expected items/has_more shape.
	 */
	public function test_request_with_hmac_auth_returns_envelope(): void {
		// ARRANGE: One published post and an authenticated request.
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch.
		$response = $this->dispatch();

		// ASSERT: 200 with the envelope keys present.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'has_more', $data );
		$this->assertFalse( $data['has_more'] );
		$this->assertCount( 1, $data['items'] );
	}

	/**
	 * Verifies that the listing item carries every field the destination's
	 * normalizer expects.
	 */
	public function test_item_payload_carries_full_listing_shape(): void {
		// ARRANGE: A specific post we can pin assertions against.
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Hello World',
				'post_name'   => 'hello-world',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch.
		$response = $this->dispatch();
		$item     = $response->get_data()['items'][0];

		// ASSERT: All listing fields present and well-formed.
		$this->assertSame( $post_id, $item['id'] );
		$this->assertSame( 'Hello World', $item['title'] );
		$this->assertSame( 'post', $item['post_type'] );
		$this->assertSame( 'publish', $item['status'] );
		$this->assertNotSame( '', $item['link'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$item['date_gmt']
		);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$item['modified_gmt']
		);
	}

	/**
	 * Verifies that the default sort is published date DESC (newest first).
	 */
	public function test_default_sort_is_published_date_desc(): void {
		// ARRANGE: Two posts with a known publish-date gap.
		$older = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2024-01-01 10:00:00',
				'post_date_gmt' => '2024-01-01 10:00:00',
			)
		);
		$newer = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2024-06-01 10:00:00',
				'post_date_gmt' => '2024-06-01 10:00:00',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch without orderby/order.
		$items = $this->dispatch_items();

		// ASSERT: Newer comes first.
		$this->assertSame( $newer, $items[0]['id'] );
		$this->assertSame( $older, $items[1]['id'] );
	}

	/**
	 * Verifies that orderby=title with order=asc returns posts alphabetically.
	 */
	public function test_orderby_title_asc(): void {
		// ARRANGE: Three posts whose titles sort alphabetically out of insertion order.
		$beta  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Beta',
			)
		);
		$alpha = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Alpha',
			)
		);
		$gamma = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Gamma',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch with title ASC.
		$items = $this->dispatch_items(
			array(
				'orderby' => 'title',
				'order'   => 'asc',
			)
		);

		// ASSERT: Alpha, Beta, Gamma in that order.
		$this->assertSame( array( $alpha, $beta, $gamma ), array_column( $items, 'id' ) );
	}

	/**
	 * Verifies that the status filter limits results to allowlisted statuses.
	 */
	public function test_status_filter_excludes_other_statuses(): void {
		// ARRANGE: A published post and a draft.
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Public',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Hidden',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Request only drafts.
		$items = $this->dispatch_items( array( 'status' => array( 'draft' ) ) );

		// ASSERT: Only the draft is returned.
		$this->assertCount( 1, $items );
		$this->assertSame( $draft_id, $items[0]['id'] );
	}

	/**
	 * Verifies that unknown statuses are dropped (not errors) and the
	 * filter falls back to the full allowlist when no usable status remains
	 * — matching the "no filter = show everything" UX of the toolbar.
	 */
	public function test_unknown_status_falls_back_to_full_allowlist(): void {
		// ARRANGE: One publish post and one draft; both should come back.
		$publish_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft_id   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Request a status not in the allowlist.
		$items = $this->dispatch_items(
			array( 'status' => array( 'inherit' ) )
		);

		// ASSERT: Both allowlisted-status posts come back.
		$ids = array_column( $items, 'id' );
		sort( $ids );
		$expected = array( $publish_id, $draft_id );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * Verifies that omitting the status param entirely returns posts in
	 * every allowlisted status, not just publish.
	 */
	public function test_omitted_status_returns_all_allowlisted_statuses(): void {
		// ARRANGE: One post in each allowlisted status that the factory can create directly.
		$publish_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft_id   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$pending_id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$private_id = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch without a status param.
		$items = $this->dispatch_items();

		// ASSERT: All four come back.
		$ids = array_column( $items, 'id' );
		sort( $ids );
		$expected = array( $publish_id, $draft_id, $pending_id, $private_id );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * Verifies that the title search override matches the title (and only
	 * the title), not the body — proving content matches don't leak in.
	 */
	public function test_search_matches_title_only(): void {
		// ARRANGE: Two posts; one has the search term only in its body.
		$matches_title = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Migration Guide',
				'post_content' => 'About anything.',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Unrelated',
				'post_content' => 'Talks about migration extensively.',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Search for 'migration'.
		$items = $this->dispatch_items( array( 'search' => 'migration' ) );

		// ASSERT: Only the title match comes back.
		$this->assertCount( 1, $items );
		$this->assertSame( $matches_title, $items[0]['id'] );
	}

	/**
	 * Verifies that multi-token search AND's each token against post_title.
	 */
	public function test_multi_token_search_ands_title_clauses(): void {
		// ARRANGE: Posts that match one token each plus one that matches both.
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Quick start',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Brown fox',
			)
		);
		$both = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Quick brown fox',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Search both tokens.
		$items = $this->dispatch_items( array( 'search' => 'quick brown' ) );

		// ASSERT: Only the title containing both tokens matches.
		$this->assertCount( 1, $items );
		$this->assertSame( $both, $items[0]['id'] );
	}

	/**
	 * Verifies that the OR'd slug branch on the search override matches a
	 * single-token search term equal to a post's slug even when no title
	 * contains the term.
	 */
	public function test_search_falls_back_to_slug_equality(): void {
		// ARRANGE: Post whose slug equals the search term, but whose title does not.
		$slug_match = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Totally Different Title',
				'post_name'   => 'sapphire',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Search by the slug as a free-text term.
		$items = $this->dispatch_items( array( 'search' => 'sapphire' ) );

		// ASSERT: Found via slug equality.
		$this->assertCount( 1, $items );
		$this->assertSame( $slug_match, $items[0]['id'] );
	}

	/**
	 * Verifies that the title search escapes LIKE wildcards in the search
	 * term, so a literal `%` only matches titles containing that character
	 * — not "anything around".
	 */
	public function test_search_escapes_like_wildcards(): void {
		// ARRANGE: One post whose title contains "100%" literally; a decoy
		// with "100" alone.
		$literal = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Battery at 100%',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'The 100 books to read',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Search for the literal "100%".
		$items = $this->dispatch_items( array( 'search' => '100%' ) );

		// ASSERT: Only the post containing the literal "100%" matches.
		$this->assertCount( 1, $items );
		$this->assertSame( $literal, $items[0]['id'] );
	}

	/**
	 * Verifies that the explicit `name` param performs exact slug lookup.
	 */
	public function test_name_param_returns_only_exact_slug_match(): void {
		// ARRANGE: Two posts with slugs that share a prefix.
		$exact = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'A',
				'post_name'   => 'launch',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'B',
				'post_name'   => 'launch-day',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Look up by exact slug.
		$items = $this->dispatch_items( array( 'name' => 'launch' ) );

		// ASSERT: Only the exact-match slug is returned.
		$this->assertCount( 1, $items );
		$this->assertSame( $exact, $items[0]['id'] );
	}

	/**
	 * Verifies that the published_after / published_before bounds gate the
	 * date_query against post_date.
	 */
	public function test_published_after_and_before_filter_results(): void {
		// ARRANGE: Three posts spanning the test bounds.
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2024-01-15 10:00:00',
				'post_date_gmt' => '2024-01-15 10:00:00',
			)
		);
		$inside = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2024-03-15 10:00:00',
				'post_date_gmt' => '2024-03-15 10:00:00',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2024-05-15 10:00:00',
				'post_date_gmt' => '2024-05-15 10:00:00',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Constrain to February through April.
		$items = $this->dispatch_items(
			array(
				'published_after'  => '2024-02-01',
				'published_before' => '2024-04-30',
			)
		);

		// ASSERT: Only the in-range post comes back.
		$this->assertCount( 1, $items );
		$this->assertSame( $inside, $items[0]['id'] );
	}

	/**
	 * Verifies that a date-only `published_before` includes posts published
	 * on that calendar day. Regression guard for the bug where
	 * createFromFormat('Y-m-d') without the `!` prefix inherits current
	 * time, and the upper bound was treated as midnight.
	 */
	public function test_published_before_includes_posts_on_same_calendar_day(): void {
		// ARRANGE: A post published at 10:00 on 2024-03-15.
		$same_day = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date'     => '2024-03-15 10:00:00',
				'post_date_gmt' => '2024-03-15 10:00:00',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Set both bounds to that same calendar day.
		$items = $this->dispatch_items(
			array(
				'published_after'  => '2024-03-15',
				'published_before' => '2024-03-15',
			)
		);

		// ASSERT: The post falls inside the inclusive range.
		$this->assertCount( 1, $items );
		$this->assertSame( $same_day, $items[0]['id'] );
	}

	/**
	 * Verifies that has_more flips on when the page is full and there's at
	 * least one more record beyond it.
	 */
	public function test_has_more_flips_true_when_extra_records_exist(): void {
		// ARRANGE: Three posts; request a page size of two.
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch with per_page=2.
		$data = $this->dispatch( array( 'per_page' => 2 ) )->get_data();

		// ASSERT: Page slice is exactly per_page; has_more is true.
		$this->assertCount( 2, $data['items'] );
		$this->assertTrue( $data['has_more'] );
	}

	/**
	 * Verifies that two consecutive pages together cover every record —
	 * pins against the off-by-one where `paged` + `per_page + 1` caused
	 * WP_Query to skip one record between pages.
	 */
	public function test_consecutive_pages_cover_every_record(): void {
		// ARRANGE: Three posts span a page boundary at per_page=2.
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		$this->force_hmac_authenticated( true );

		// ACT: Fetch both pages.
		$page_1_ids = array_column(
			$this->dispatch(
				array(
					'per_page' => 2,
					'page'     => 1,
				)
			)->get_data()['items'],
			'id'
		);
		$page_2_ids = array_column(
			$this->dispatch(
				array(
					'per_page' => 2,
					'page'     => 2,
				)
			)->get_data()['items'],
			'id'
		);

		// ASSERT: All 3 records accounted for across the two pages.
		$this->assertCount( 3, array_unique( array_merge( $page_1_ids, $page_2_ids ) ) );
	}

	/**
	 * Verifies that has_more is false on the last page.
	 */
	public function test_has_more_is_false_on_last_page(): void {
		// ARRANGE: Two posts; request page 1 with per_page=2.
		for ( $i = 0; $i < 2; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch with per_page=2.
		$data = $this->dispatch( array( 'per_page' => 2 ) )->get_data();

		// ASSERT: All items returned; no more pages.
		$this->assertCount( 2, $data['items'] );
		$this->assertFalse( $data['has_more'] );
	}

	/**
	 * Verifies that per_page is capped at 100 to keep query cost bounded.
	 */
	public function test_per_page_is_capped_at_one_hundred(): void {
		// ARRANGE: 101 posts so the cap can be observed.
		for ( $i = 0; $i < 101; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch asking for 500 per page.
		$data = $this->dispatch( array( 'per_page' => 500 ) )->get_data();

		// ASSERT: Hard cap honored.
		$this->assertCount( 100, $data['items'] );
		$this->assertTrue( $data['has_more'] );
	}

	/**
	 * Verifies that non-positive per_page values clamp to the floor of 1
	 * rather than returning everything or erroring. The destination's AJAX
	 * layer floors before the request goes out, so this primarily defends
	 * direct HMAC-signed REST callers.
	 *
	 * @dataProvider non_positive_per_page_provider
	 *
	 * @param int $per_page Non-positive per_page value to send.
	 */
	public function test_per_page_clamps_to_floor_on_non_positive_values(
		int $per_page
	): void {
		// ARRANGE: Three posts so the floor and a normal page are distinguishable.
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch with the non-positive value.
		$data = $this->dispatch( array( 'per_page' => $per_page ) )->get_data();

		// ASSERT: Exactly one item came back (the floor); more available.
		$this->assertCount( 1, $data['items'] );
		$this->assertTrue( $data['has_more'] );
	}

	/**
	 * Non-positive per_page values that should clamp to 1.
	 *
	 * @return array<string, array{int}>
	 */
	public function non_positive_per_page_provider(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
		);
	}

	/**
	 * Verifies that pages (post_type=page) route through the post-type filter.
	 */
	public function test_post_type_param_routes_to_requested_type(): void {
		// ARRANGE: One post and one page.
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'A post',
			)
		);
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'A page',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Request post_type=page.
		$items = $this->dispatch_items( array( 'post_type' => 'page' ) );

		// ASSERT: Only the page comes back.
		$this->assertCount( 1, $items );
		$this->assertSame( $page_id, $items[0]['id'] );
		$this->assertSame( 'page', $items[0]['post_type'] );
	}

	/**
	 * Verifies that requesting an internal (non-REST) post type is rejected,
	 * proving the show_in_rest/public allowlist on the controller.
	 */
	public function test_request_for_non_rest_post_type_is_rejected(): void {
		// ARRANGE: Register a private post type the controller should refuse.
		register_post_type(
			'sp_private',
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);
		$this->force_hmac_authenticated( true );

		try {
			// ACT: Try to query it.
			$response = $this->dispatch( array( 'post_type' => 'sp_private' ) );

			// ASSERT: 400 with the controller's invalid post-type code.
			$this->assertSame( 400, $response->get_status() );
			$data = $response->get_data();
			$this->assertSame(
				'safe_publish_catalog_invalid_post_type',
				$data['code'] ?? ''
			);
		} finally {
			unregister_post_type( 'sp_private' );
		}
	}

	/**
	 * Verifies that a malformed date param returns 400 rather than silently
	 * falling through with no constraint.
	 */
	public function test_malformed_date_returns_400(): void {
		// ARRANGE: Authenticated session.
		$this->force_hmac_authenticated( true );

		// ACT: Send an obviously bogus date.
		$response = $this->dispatch( array( 'published_after' => 'not-a-date' ) );

		// ASSERT: 400 with the controller's invalid-date code.
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			'safe_publish_catalog_invalid_date',
			$data['code'] ?? ''
		);
	}

	/**
	 * Verifies that include[] short-circuits to a post__in lookup and returns
	 * the matched posts in include order.
	 */
	public function test_include_returns_only_named_ids_in_request_order(): void {
		// ARRANGE: Three posts; we'll ask for the first and third in reversed
		// publish order to prove include order trumps the default date sort.
		$first  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$third  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Ask for first and third, in that order.
		$items = $this->dispatch_items( array( 'include' => array( $first, $third ) ) );

		// ASSERT: Two items returned, in the order specified by include.
		$this->assertSame( array( $first, $third ), array_column( $items, 'id' ) );
		// ASSERT: Untargeted post stays out of the response.
		$this->assertNotContains(
			$second,
			array_column( $items, 'id' )
		);
	}

	/**
	 * Verifies that include[] omits any requested ID that doesn't exist on
	 * the source, so the destination can interpret "no row" as "missing".
	 */
	public function test_include_omits_unknown_ids(): void {
		// ARRANGE: One real post; ask for it alongside a non-existent ID.
		$real = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Include the real ID and a bogus one.
		$items = $this->dispatch_items( array( 'include' => array( $real, 999999 ) ) );

		// ASSERT: Only the real ID comes back.
		$this->assertSame( array( $real ), array_column( $items, 'id' ) );
	}

	/**
	 * Verifies that include[] honors the post_type filter — a request for
	 * post_type=page does not return a post even if its ID is in include[].
	 */
	public function test_include_honors_post_type_filter(): void {
		// ARRANGE: A post and a page sharing the same id space.
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$page_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$this->force_hmac_authenticated( true );

		// ACT: Ask for both, but constrain to post_type=page.
		$items = $this->dispatch_items(
			array(
				'post_type' => 'page',
				'include'   => array( $post_id, $page_id ),
			)
		);

		// ASSERT: Only the page comes back.
		$this->assertSame( array( $page_id ), array_column( $items, 'id' ) );
	}

	/**
	 * Verifies that include[] returns drafts/private statuses alongside
	 * published posts when no status filter is set, so the destination can
	 * still sync-check posts that have moved into a non-public status.
	 */
	public function test_include_returns_drafts_when_no_status_filter(): void {
		// ARRANGE: Posts in two statuses the destination might still sync.
		$publish = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Include both without specifying a status filter.
		$items = $this->dispatch_items( array( 'include' => array( $publish, $draft ) ) );

		// ASSERT: Both come back.
		$ids = array_column( $items, 'id' );
		sort( $ids );
		$expected = array( $publish, $draft );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * Verifies that has_more is always false on include[] responses, since
	 * the caller already names every ID they want.
	 */
	public function test_include_response_never_has_more(): void {
		// ARRANGE: A single post we'll include.
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->force_hmac_authenticated( true );

		// ACT: Dispatch with include.
		$data = $this->dispatch( array( 'include' => array( $post_id ) ) )->get_data();

		// ASSERT: has_more is false.
		$this->assertFalse( $data['has_more'] );
	}

	/**
	 * Verifies that include[] is capped at MAX_PER_PAGE so a single batch
	 * can't outgrow what a regular page would serve.
	 */
	public function test_include_caps_at_max_per_page(): void {
		// ARRANGE: 110 posts; ask for all of them by id.
		$ids = array();
		for ( $i = 0; $i < 110; $i++ ) {
			$ids[] = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}
		$this->force_hmac_authenticated( true );

		// ACT: Request 110 by include.
		$items = $this->dispatch_items( array( 'include' => $ids ) );

		// ASSERT: Capped at 100 (MAX_PER_PAGE).
		$this->assertCount( 100, $items );
	}

	/**
	 * Verifies that the post-types endpoint returns the WP built-in
	 * content types (post, page) and excludes back-office types that
	 * pass the public+show_in_rest filter but aren't catalog-servable
	 * (attachment, wp_navigation).
	 */
	public function test_post_types_endpoint_returns_only_catalog_eligible_types(): void {
		// ARRANGE: Authenticated session; rely on the WP default post types.
		$this->force_hmac_authenticated( true );

		// ACT: Hit the post-types route.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/safe-publish/v1/catalog/post-types' )
		);

		// ASSERT: 200, includes post + page, excludes attachment and wp_navigation.
		$this->assertSame( 200, $response->get_status() );
		$slugs = array_column( $response->get_data(), 'slug' );
		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
		$this->assertNotContains( 'attachment', $slugs );
		$this->assertNotContains( 'wp_navigation', $slugs );
	}

	/**
	 * Verifies that each post-types item carries the full shape the
	 * destination's dropdown expects.
	 */
	public function test_post_types_endpoint_item_shape(): void {
		// ARRANGE: Authenticated session; rely on the WP default post types.
		$this->force_hmac_authenticated( true );

		// ACT: Hit the post-types route and grab the items.
		$items = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/safe-publish/v1/catalog/post-types' )
		)->get_data();

		// ASSERT: Find 'post' and check it has the expected fields.
		$post_entry = null;
		foreach ( $items as $item ) {
			if ( 'post' === $item['slug'] ) {
				$post_entry = $item;
				break;
			}
		}
		$this->assertNotNull( $post_entry );
		$this->assertArrayHasKey( 'name', $post_entry );
		$this->assertArrayHasKey( 'label', $post_entry );
		$this->assertArrayHasKey( 'rest_base', $post_entry );
		$this->assertArrayHasKey( 'description', $post_entry );
		$this->assertSame( 'posts', $post_entry['rest_base'] );
	}

	/**
	 * Verifies that the post-types route is gated by HMAC auth like the
	 * sibling catalog/posts route.
	 */
	public function test_post_types_endpoint_requires_hmac_auth(): void {
		// ARRANGE: Authenticator stays in its default unauthenticated state.

		// ACT: Dispatch without auth.
		$response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/safe-publish/v1/catalog/post-types' )
		);

		// ASSERT: 401/403.
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Dispatches the catalog route with optional overrides.
	 *
	 * @param array $params Query params to set on the request.
	 * @return WP_REST_Response
	 */
	private function dispatch( array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/safe-publish/v1/catalog/posts' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatches the catalog route and returns the items array directly.
	 *
	 * @param array $params Query params to set on the request.
	 * @return array Items from the response payload.
	 */
	private function dispatch_items( array $params = array() ): array {
		return $this->dispatch( $params )->get_data()['items'];
	}

	/**
	 * Flips the authenticator's internal flag so the permission gate sees a
	 * signed request without us re-creating one.
	 *
	 * @param bool $authenticated True to mark as authenticated.
	 */
	private function force_hmac_authenticated( bool $authenticated ): void {
		$reflection = new ReflectionClass( $this->authenticator );
		$property   = $reflection->getProperty( 'authenticated' );
		$property->setValue( $this->authenticator, $authenticated );
	}
}
