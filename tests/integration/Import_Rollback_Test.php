<?php
/**
 * Import rollback integration tests
 *
 * Tests the abort-and-undo behavior when an import step fails: Orphaned
 * attachment cleanup, featured image failures, tracking meta failures,
 * custom meta failures, and term failures.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Closure;
use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Import Rollback Test Class.
 */
class Import_Rollback_Test extends Source_Posts_API_Test_Base {

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
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Intercept wp/v2/media JSON API calls at higher priority than the
		// base-class image mock (priority 10) so the API endpoint returns
		// valid JSON before the image URL itself is fetched.
		add_filter(
			'pre_http_request',
			array( $this, 'mock_media_api_request' ),
			5,
			3
		);

		$this->repository = new History_Repository();

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Tears down test state.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_media_api_request' ),
			5
		);
		parent::tearDown();
	}

	/**
	 * Intercepts wp/v2/media JSON API requests and returns a mock response
	 * whose source_url points to a .jpg URL that the base-class image mock
	 * can then serve as a real fixture file.
	 *
	 * Registered at priority 5 — runs before the base-class image mock at 10.
	 *
	 * @param false|array|WP_Error $preempt Early-return value passed by WP.
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Preemptive response or false.
	 */
	public function mock_media_api_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( ! str_contains( $url, 'wp-json/wp/v2/media/' ) ) {
			return $preempt;
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array( 'source_url' => 'https://source.example.com/featured.jpg' )
			),
			'headers'  => array( 'content-type' => 'application/json' ),
		);
	}

	/**
	 * Verifies that partially-downloaded attachments are cleaned up when an
	 * import is aborted due to a media failure.
	 *
	 * All blocks are processed independently before the failure check runs, so
	 * any successful downloads that preceded a failure will have created real
	 * attachments. All of those must be deleted on abort to leave the media
	 * library in a clean state.
	 */
	public function test_orphaned_attachments_are_deleted_when_import_is_aborted(): void {
		// ARRANGE: Content with two images — first succeeds, second fails (nonexistent).
		$good_url   = 'https://source.example.com/real-image.jpg';
		$broken_url = 'https://source.example.com/nonexistent-partial.jpg';

		$this->mock_post_overrides = array(
			'content' => '<p>'
				. '<img src="' . $good_url . '" alt="good">'
				. '<img src="' . $broken_url . '" alt="broken">'
				. '</p>',
		);

		$session_id         = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();

		$post_data = array(
			'id'        => 8301,
			'title'     => 'Post With Partial Media Failure',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/partial-media-failure',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import aborted due to the broken image.
		$this->assertFalse(
			$result['success'],
			'Import should fail when one of multiple images cannot be downloaded.'
		);
		$this->assertStringContainsString( 'nonexistent-partial.jpg', $result['error'] );

		// ASSERT: No post was created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8301',
				)
			),
			'No post should be created when a media import fails.'
		);

		// ASSERT: The attachment created for the successful image was cleaned up.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Attachments created before the failure must be deleted when the import is aborted.'
		);
	}

	/**
	 * Verifies that sideloaded attachments (including featured image) are
	 * deleted when the import fails at the terms-update step on the create
	 * path.
	 */
	public function test_sideloaded_attachments_cleaned_up_when_terms_update_fails(): void {
		// ARRANGE: Fresh content includes a featured image so one attachment is
		// sideloaded before wp_insert_post runs. A term that cannot be created
		// triggers the failure after the post is written.
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'terms'          => array( 'category' => array( 'Uncreatable Term' ) ),
		);
		$filter                    = $this->fail_term_creation();

		$session_id         = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();

		$post_data = array(
			'id'        => 9210,
			'title'     => 'Post With Unknown Taxonomy',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/unknown-taxonomy-test',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: Import failed at the terms step.
		$this->assertFalse( $result['success'], 'Import should fail when a term cannot be created.' );
		$this->assertStringContainsString( 'Simulated term insertion failure.', $result['error'] );

		// ASSERT: No post was created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9210',
				)
			),
			'No post should remain after a failed import.'
		);

		// ASSERT: The sideloaded featured image attachment was cleaned up.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Sideloaded attachments must be deleted when the terms update fails.'
		);
	}

	/**
	 * Verifies that the import aborts without creating a draft when the
	 * featured image cannot be imported.
	 *
	 * The featured image is sideloaded before the post is inserted, so a
	 * failure here means no post is ever written to the DB.
	 */
	public function test_import_aborts_and_deletes_draft_when_featured_image_fails(): void {
		// ARRANGE: Fresh-content response includes featured_media > 0 so the
		// import path attempts to fetch the featured image. The fail filter
		// runs at priority 6 — after mock_media_api_request (priority 5) — so
		// it can override that response and return a 404, causing
		// import_featured_image() to return false.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 9101,
			'title'     => 'Post With Failed Featured Image',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/failed-featured-image',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: Import must fail with a featured image error.
		$this->assertFalse( $result['success'], 'Import should fail when the featured image cannot be imported.' );
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: The orphaned draft must have been deleted.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9101',
				)
			),
			'The post must not exist when the featured image import fails before insertion.'
		);
	}

	/**
	 * Verifies that the import aborts without modifying the post when the
	 * featured image cannot be imported on re-import.
	 *
	 * The featured image is sideloaded before the post is written, so a failure
	 * here leaves the existing post untouched.
	 */
	public function test_import_aborts_without_deleting_post_when_featured_image_fails_on_update(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Import the post once with no featured image so it exists in
		// the DB.
		$post_data = array(
			'id'        => 9102,
			'title'     => 'Post For Featured Image Update Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/featured-image-update-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		// ARRANGE: Re-import with featured_media > 0, but make the media API fail.
		// The fail filter runs at priority 6 — after mock_media_api_request
		// (priority 5) — so it can override that response and return a 404.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: Import must fail with a featured image error.
		$this->assertFalse( $result['success'], 'Re-import should fail when the featured image cannot be imported.' );
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: The existing post must still be present in the DB.
		$this->assertNotNull(
			get_post( $post_id ),
			'The existing post must not be deleted when featured image import fails on the update path.'
		);
	}

	/**
	 * Verifies that the existing post is not modified when the featured image
	 * import fails on the bulk update path.
	 *
	 * The featured image is sideloaded before the post is written, so a failure
	 * aborts the import before any DB write. Title, content, and tracking meta
	 * must all be identical to their values before the import attempt began.
	 */
	public function test_import_restores_post_on_featured_image_failure_during_bulk_update(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Import the post once with clean content so it exists in the DB.
		$post_data = array(
			'id'        => 9103,
			'title'     => 'Original Title',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/restore-on-failure-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );
		$original_link    = get_post_meta( $post_id, Options::META_SOURCE_LINK, true );

		// ARRANGE: Fresh content will return updated title/content and a featured
		// image. The fail filter makes the media API return 404 to trigger failure.
		$this->mock_post_overrides = array(
			'title'          => 'Updated Title',
			'content'        => '<p>Updated content that must not be saved.</p>',
			'featured_media' => 100,
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: Import must fail.
		$this->assertFalse( $result['success'], 'Re-import should fail when the featured image cannot be imported.' );
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: Post fields and tracking meta must be unchanged: The import
		// aborted before any DB write.
		$this->assertSame( $original_title, get_post_field( 'post_title', $post_id ), 'Title must be unchanged after failed update.' );
		$this->assertSame( $original_content, get_post_field( 'post_content', $post_id ), 'Content must be unchanged after failed update.' );
		$this->assertSame( $original_link, get_post_meta( $post_id, Options::META_SOURCE_LINK, true ), 'Source link meta must be unchanged after failed update.' );
	}

	/**
	 * Verifies that the update path returns an error when wp_update_post()
	 * fails silently by returning 0.
	 *
	 * The wp_insert_post_empty_content filter forces wp_update_post() to return
	 * 0 before any DB write occurs. The function must surface a WP_Error and
	 * leave the post unchanged instead of proceeding to write meta against a
	 * stale post.
	 */
	public function test_bulk_update_fails_on_silent_post_update_failure(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post once to create it in the DB.
		$post_data = array(
			'id'        => 9150,
			'title'     => 'Post For Silent Update Failure Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/silent-update-failure',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values.
		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );

		// ARRANGE: Fresh content returns updated fields.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Silent Failure Title',
			'content' => '<p>Updated content.</p>',
		);

		// ARRANGE: Force wp_update_post() to return 0 by short-circuiting the
		// empty-content check inside wp_insert_post().
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		// ASSERT: Import must report failure.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when wp_update_post returns 0.'
		);

		// ASSERT: Post fields and tracking meta must remain at their
		// pre-update values; no writes should have hit the stale post.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must remain unchanged when wp_update_post fails silently.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must remain unchanged when wp_update_post fails silently.'
		);
	}

	/**
	 * Verifies that the post is fully rolled back when custom meta update fails
	 * on the bulk update path.
	 *
	 * The filter blocks writes for a specific custom meta key, causing
	 * Meta_Terms_Manager::update_meta() to return a WP_Error. Post fields,
	 * tracking meta, and featured image must all be restored to their
	 * pre-update values.
	 */
	public function test_bulk_update_rolls_back_post_on_custom_meta_failure(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post with initial meta and a featured image.
		$post_data = array(
			'id'             => 9130,
			'title'          => 'Post For Meta Rollback Test',
			'content'        => '<p>Original content.</p>',
			'link'           => 'https://source.example.com/meta-rollback-test',
			'post_type'      => 'posts',
			'featured_media' => 100,
			'meta'           => array( 'custom_field' => 'original_value' ),
		);

		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'meta'           => array( 'custom_field' => 'original_value' ),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values for rollback assertions.
		$original_title        = get_post_field( 'post_title', $post_id );
		$original_content      = get_post_field( 'post_content', $post_id );
		$original_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		$original_link         = get_post_meta(
			$post_id,
			Options::META_SOURCE_LINK,
			true
		);
		$original_meta         = get_post_meta(
			$post_id,
			'custom_field',
			true
		);

		$this->assertGreaterThan(
			0,
			$original_thumbnail_id,
			'Initial import should set a featured image.'
		);

		// ARRANGE: Fresh content returns updated fields, meta, and a new
		// featured image so the rollback has a thumbnail change to restore.
		$this->mock_post_overrides = array(
			'title'          => 'Updated Meta Rollback Title',
			'content'        => '<p>Updated content.</p>',
			'featured_media' => 200,
			'meta'           => array( 'custom_field' => 'updated_value' ),
		);

		// ARRANGE: Block update_post_meta for 'custom_field' to simulate a DB
		// failure during Meta_Terms_Manager::update_meta.
		$block_meta = function (
			$check,
			$object_id,
			$meta_key,
			$meta_value,
			$prev_value
		) {
			unset( $object_id, $meta_value, $prev_value );
			if ( 'custom_field' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 5 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: Import must fail with a meta error.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when custom meta cannot be written.'
		);
		$this->assertStringContainsString(
			'custom_field',
			$result['error']
		);

		// ASSERT: Post fields must be rolled back.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must be restored after custom meta failure.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must be restored after custom meta failure.'
		);

		// ASSERT: Featured image must be rolled back to the original.
		$this->assertSame(
			$original_thumbnail_id,
			(int) get_post_thumbnail_id( $post_id ),
			'Featured image must be restored after custom meta failure.'
		);

		// ASSERT: Tracking meta must be rolled back.
		$this->assertSame(
			$original_link,
			get_post_meta(
				$post_id,
				Options::META_SOURCE_LINK,
				true
			),
			'Source link meta must be restored after custom meta failure.'
		);

		// ASSERT: Custom meta must be unchanged. The filter blocked both the
		// import write and the rollback write for this key, but since the value
		// was already 'original_value' in the DB before the import, it remains
		// correct.
		$this->assertSame(
			$original_meta,
			get_post_meta( $post_id, 'custom_field', true ),
			'Custom meta must remain at its original value.'
		);
	}

	/**
	 * Verifies that the post is fully rolled back when term assignment fails on
	 * the bulk update path.
	 *
	 * A term that cannot be created makes Meta_Terms_Manager::update_terms()
	 * return a WP_Error. Post fields, tracking meta, and custom meta must all
	 * be restored to their pre-update values.
	 */
	public function test_bulk_update_rolls_back_post_on_term_failure(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post with initial meta and a category.
		$post_data = array(
			'id'        => 9140,
			'title'     => 'Post For Term Rollback Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/term-rollback-test',
			'post_type' => 'posts',
			'meta'      => array( 'my_field' => 'original' ),
			'terms'     => array(
				'category' => array( 'Rollback Test Category' ),
			),
		);

		$this->mock_post_overrides = array(
			'meta'  => array( 'my_field' => 'original' ),
			'terms' => array(
				'category' => array( 'Rollback Test Category' ),
			),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values for rollback assertions.
		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );
		$original_meta    = get_post_meta( $post_id, 'my_field', true );
		$original_terms   = wp_get_object_terms(
			$post_id,
			'category',
			array( 'fields' => 'ids' )
		);

		// ARRANGE: Fresh content returns updated fields, meta, and a term that
		// cannot be created, to trigger term failure.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Term Rollback Title',
			'content' => '<p>Updated content.</p>',
			'meta'    => array( 'my_field' => 'updated' ),
			'terms'   => array(
				'category' => array(
					'Rollback Test Category',
					'Uncreatable Term',
				),
			),
		);
		$filter                    = $this->fail_term_creation();

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: Import must fail at the terms step.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when a term cannot be created.'
		);
		$this->assertStringContainsString(
			'Simulated term insertion failure.',
			$result['error']
		);

		// ASSERT: Post fields must be rolled back.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must be restored after term failure.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must be restored after term failure.'
		);

		// ASSERT: Custom meta must be rolled back.
		$this->assertSame(
			$original_meta,
			get_post_meta( $post_id, 'my_field', true ),
			'Custom meta must be restored after term failure.'
		);

		// ASSERT: Term assignments must be rolled back.
		$restored_terms = wp_get_object_terms(
			$post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame(
			$original_terms,
			$restored_terms,
			'Category terms must be restored after term failure.'
		);
	}

	/**
	 * Verifies that terms cleared because the source sent their taxonomy empty
	 * are restored when a later taxonomy fails, so an aborted update never
	 * leaves the clear applied.
	 *
	 * Uses a custom taxonomy: The snapshot's post fields carry post_category
	 * and tags_input, so only a custom taxonomy relies on the snapshot's own
	 * term restore.
	 */
	public function test_rollback_restores_cleared_terms(): void {
		register_taxonomy( 'sp_rollback_topic', 'post' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import a post with a custom-taxonomy term and a category.
		$post_data = array(
			'id'        => 9141,
			'title'     => 'Post For Cleared Term Rollback',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/cleared-term-rollback',
			'post_type' => 'posts',
		);

		$this->mock_post_overrides = array(
			'safe_publish_terms' => array(
				'sp_rollback_topic' => array(
					array(
						'id'   => 9241,
						'name' => 'Doomed Topic',
						'slug' => 'doomed-topic',
					),
				),
				'category'          => array(
					array(
						'id'   => 9242,
						'name' => 'Rollback Category',
						'slug' => 'rollback-category',
					),
				),
			),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		$original_topics = wp_get_object_terms(
			$post_id,
			'sp_rollback_topic',
			array( 'fields' => 'ids' )
		);
		$this->assertNotSame( array(), $original_topics );

		// ARRANGE: The source empties the custom taxonomy, and the category
		// that follows carries a term whose creation fails.
		$this->mock_post_overrides['safe_publish_terms'] = array(
			'sp_rollback_topic' => array(),
			'category'          => array(
				array(
					'id'   => 9242,
					'name' => 'Rollback Category',
					'slug' => 'rollback-category',
				),
				array(
					'id'   => 9243,
					'name' => 'Uncreatable Term',
					'slug' => 'uncreatable-term',
				),
			),
		);

		$filter = static fn() => new WP_Error(
			'insert_term_failed',
			'Simulated term insertion failure.'
		);
		add_filter( 'pre_insert_term', $filter );

		// ACT: Re-import, clearing the custom taxonomy before category fails.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: The update failed and the cleared terms are back.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when a term cannot be created.'
		);
		$this->assertSame(
			$original_topics,
			wp_get_object_terms(
				$post_id,
				'sp_rollback_topic',
				array( 'fields' => 'ids' )
			),
			'Cleared terms must be restored when the update is rolled back.'
		);
	}

	/**
	 * Verifies that the new featured-image attachment is deleted when the bulk
	 * update path rolls back due to a custom meta failure, while the original
	 * thumbnail is preserved.
	 *
	 * On re-import, the source returns a different featured_media id whose
	 * source_url is distinct from the previously imported one, so a brand-new
	 * attachment is sideloaded rather than the existing one being reused.
	 */
	public function test_bulk_update_cleans_up_new_featured_image_on_meta_failure(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Initial import with featured_media => 100.
		$post_data = array(
			'id'             => 9170,
			'title'          => 'Post For Featured Image Cleanup Test',
			'content'        => '<p>Original content.</p>',
			'link'           => 'https://source.example.com/featured-image-cleanup-test',
			'post_type'      => 'posts',
			'featured_media' => 100,
			'meta'           => array( 'custom_field' => 'original_value' ),
		);

		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'meta'           => array( 'custom_field' => 'original_value' ),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id               = $first['post_id'];
		$original_thumbnail_id = (int) get_post_thumbnail_id( $post_id );

		$this->assertGreaterThan(
			0,
			$original_thumbnail_id,
			'Initial import should set a featured image.'
		);

		$attachments_before_update = $this->get_attachment_count();

		// ARRANGE: Re-import with a different featured_media id and a distinct
		// source URL so a new attachment is sideloaded rather than the existing
		// one being reused via get_attachment_by_url(). Registered at priority
		// 6 so it overrides the base mock_media_api_request at priority 5 for
		// the targeted media ID.
		$distinct_media_filter = $this->make_distinct_media_url_filter(
			200,
			'featured-200.jpg'
		);
		add_filter( 'pre_http_request', $distinct_media_filter, 6, 3 );

		$this->mock_post_overrides = array(
			'featured_media' => 200,
			'meta'           => array( 'custom_field' => 'updated_value' ),
		);

		// ARRANGE: Block the custom_field write so update_meta() fails after the
		// new featured image is sideloaded and assigned as the thumbnail.
		$block_meta = function (
			$check,
			$object_id,
			$meta_key,
			$meta_value,
			$prev_value
		) {
			unset( $object_id, $meta_value, $prev_value );
			if ( 'custom_field' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 5 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'update_post_metadata', $block_meta, 10 );
		remove_filter( 'pre_http_request', $distinct_media_filter, 6 );

		// ASSERT: Import must fail with a meta error.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when custom meta cannot be written.'
		);

		// ASSERT: Original thumbnail must still be the post's featured image.
		$this->assertSame(
			$original_thumbnail_id,
			(int) get_post_thumbnail_id( $post_id ),
			'Original thumbnail must be restored after rollback.'
		);
		$this->assertNotNull(
			get_post( $original_thumbnail_id ),
			'Original featured-image attachment must remain in the media library.'
		);

		// ASSERT: No new attachments remain — the sideloaded one for media 200
		// must have been deleted.
		$this->assert_no_new_attachments(
			$attachments_before_update,
			'New featured-image attachment must be deleted after rollback.'
		);
	}

	/**
	 * Verifies that inline media sideloaded for the new content is deleted when
	 * the bulk update path rolls back due to a term failure.
	 *
	 * The first import creates a post with no inline media. The re-import's
	 * fresh content references a new image URL, so process_content() sideloads
	 * a new attachment before persist_updated_post() is called. An unknown
	 * taxonomy then triggers the rollback path.
	 */
	public function test_bulk_update_cleans_up_new_inline_media_on_term_failure(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Initial import with no inline media.
		$post_data = array(
			'id'        => 9180,
			'title'     => 'Post For Inline Media Cleanup Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/inline-media-cleanup-test',
			'post_type' => 'posts',
			'terms'     => array(
				'category' => array( 'Inline Media Cleanup Category' ),
			),
		);

		$this->mock_post_overrides = array(
			'terms' => array(
				'category' => array( 'Inline Media Cleanup Category' ),
			),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );

		$attachments_before_update = $this->get_attachment_count();

		// ARRANGE: Fresh content references a new inline image whose URL has
		// never been imported before, so process_content() sideloads a new
		// attachment. An uncreatable term then triggers a rollback.
		$new_inline_url            = 'https://source.example.com/new-inline-image.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p>Updated content '
				. '<img src="' . $new_inline_url . '" alt="new">'
				. '</p>',
			'terms'   => array(
				'category' => array(
					'Inline Media Cleanup Category',
					'Uncreatable Term',
				),
			),
		);
		$filter                    = $this->fail_term_creation();

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: Import must fail at the terms step.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when a term cannot be created.'
		);
		$this->assertStringContainsString(
			'Simulated term insertion failure.',
			$result['error']
		);

		// ASSERT: The newly sideloaded inline media attachment was cleaned up.
		$this->assert_no_new_attachments(
			$attachments_before_update,
			'New inline media must be deleted after rollback.'
		);
	}

	/**
	 * Verifies that a rollback restores backslashes verbatim.
	 *
	 * The snapshot holds raw database reads, so the restore has to re-slash it.
	 */
	public function test_rollback_restores_backslashes(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import a post whose fields and meta carry backslashes.
		$title   = 'Windows path A\B';
		$excerpt = 'Backslash sample: C:\builds\out.';
		$content = '<p>namespace App\Models; $re = "\d+";</p>';
		$meta    = array( 'my_field' => 'C:\builds\out' );

		$post_data = array(
			'id'        => 9150,
			'title'     => $title,
			'link'      => 'https://source.example.com/backslash-rollback',
			'post_type' => 'posts',
			'meta'      => $meta,
		);

		$this->mock_post_overrides = array(
			'title'   => $title,
			'excerpt' => $excerpt,
			'content' => $content,
			'meta'    => $meta,
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		// ARRANGE: The next fetch changes the fields and adds a term that
		// cannot be created, so the update aborts after the post write.
		$this->mock_post_overrides = array(
			'title'   => 'Updated title',
			'excerpt' => 'Updated excerpt.',
			'content' => '<p>Updated content.</p>',
			'meta'    => array( 'my_field' => 'updated' ),
			'terms'   => array( 'category' => array( 'Uncreatable Term' ) ),
		);
		$filter                    = $this->fail_term_creation();

		// ACT: Re-import, which fails at the terms step and rolls back.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: The update failed, so a rollback ran.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when a term cannot be created.'
		);

		// ASSERT: Every restored field still carries its backslashes.
		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertSame( $title, $post->post_title );
		$this->assertSame( $excerpt, $post->post_excerpt );
		$this->assertSame( $content, $post->post_content );
		$this->assertSame(
			$meta['my_field'],
			get_post_meta( $post_id, 'my_field', true )
		);
	}

	/**
	 * Verifies that inline media sideloaded earlier in the run is deleted when
	 * the featured image fails on the create path.
	 */
	public function test_inline_media_is_deleted_when_featured_image_fails_on_create(): void {
		// ARRANGE: One downloadable inline image, plus a featured image whose
		// media record 404s.
		$inline_url                = 'https://source.example.com/inline-create.jpg';
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'content'        => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		$session_id         = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();
		$created            = array();
		$recorder           = $this->record_created_attachments( $created );

		$post_data = array(
			'id'        => 9601,
			'title'     => 'Post With Inline Media And Failing Featured Image',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/create-inline-media-cleanup',
			'post_type' => 'posts',
		);

		// ACT: Attempt the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );
		remove_action( 'add_attachment', $recorder );

		// ASSERT: The import aborted on the featured image.
		$this->assertFalse(
			$result['success'],
			'Import should fail when the featured image cannot be imported.'
		);
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: The run downloaded the inline image, and the abort removed it.
		$this->assertNotSame(
			array(),
			$created,
			'The run must download the inline image before the abort.'
		);
		$this->assert_no_new_attachments(
			$attachments_before,
			'Inline media must be deleted when the featured image fails on create.'
		);
	}

	/**
	 * Verifies that inline media sideloaded earlier in the run is deleted when
	 * the featured image fails on the update path.
	 */
	public function test_inline_media_is_deleted_when_featured_image_fails_on_update(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Import once without media so the post exists.
		$post_data = array(
			'id'        => 9602,
			'title'     => 'Post For Update Featured Image Failure',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/update-inline-media-cleanup',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );

		// ARRANGE: Re-import with an inline image the run has to download and a
		// featured image that 404s.
		$inline_url                = 'https://source.example.com/inline-update.jpg';
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'content'        => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		$attachments_before = $this->get_attachment_count();
		$created            = array();
		$recorder           = $this->record_created_attachments( $created );

		// ACT: Re-import the same post, hitting the update path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );
		remove_action( 'add_attachment', $recorder );

		// ASSERT: The re-import aborted on the featured image.
		$this->assertFalse(
			$result['success'],
			'Re-import should fail when the featured image cannot be imported.'
		);
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: The run downloaded the inline image, and the abort removed it.
		$this->assertNotSame(
			array(),
			$created,
			'The run must download the inline image before the abort.'
		);
		$this->assert_no_new_attachments(
			$attachments_before,
			'Inline media must be deleted when the featured image fails on update.'
		);
	}

	/**
	 * Verifies that inline media sideloaded earlier in the run is deleted when
	 * the database layer rejects the post insert.
	 */
	public function test_inline_media_is_deleted_when_post_insert_is_rejected(): void {
		// ARRANGE: One downloadable inline image and a rejected post insert.
		$inline_url                = 'https://source.example.com/inline-insert.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$reject_insert = $this->reject_post_insert();

		$session_id         = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();
		$created            = array();
		$recorder           = $this->record_created_attachments( $created );

		$post_data = array(
			'id'        => 9603,
			'title'     => 'Post Whose Insert Is Rejected',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/rejected-insert-media-cleanup',
			'post_type' => 'posts',
		);

		// ACT: Attempt the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'wp_insert_post_empty_content', $reject_insert, 10 );
		remove_action( 'add_attachment', $recorder );

		// ASSERT: The insert was rejected.
		$this->assertFalse(
			$result['success'],
			'Import should fail when the post insert is rejected.'
		);

		// ASSERT: No post was created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9603',
				)
			),
			'No post should exist when the insert is rejected.'
		);

		// ASSERT: The run downloaded the inline image, and the abort removed it.
		$this->assertNotSame(
			array(),
			$created,
			'The run must download the inline image before the abort.'
		);
		$this->assert_no_new_attachments(
			$attachments_before,
			'Inline media must be deleted when the post insert is rejected.'
		);
	}

	/**
	 * Verifies that a featured-image abort on the create path names the media
	 * its cleanup could not delete, so the operator can remove it by hand.
	 */
	public function test_featured_image_abort_on_create_names_media_it_could_not_delete(): void {
		// ARRANGE: One downloadable inline image, a featured image that 404s,
		// and attachment deletion blocked so the cleanup leaves it behind.
		$inline_url                = 'https://source.example.com/inline-survivor.jpg';
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'content'        => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		$block_deletion = static fn() => false;
		add_filter( 'pre_delete_attachment', $block_deletion, 10, 3 );

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ACT: Attempt the import.
		$result = $this->import_service->import_post(
			array(
				'id'        => 9609,
				'title'     => 'Post Whose Cleanup Cannot Delete Its Media',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/cleanup-survivor',
				'post_type' => 'posts',
			),
			$session_id
		);

		remove_filter( 'pre_delete_attachment', $block_deletion, 10 );
		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: The surviving attachment is named in both the message and
		// the result payload.
		$this->assertFalse( $result['success'], 'The import should fail.' );

		$this->assert_survivor_reported( $result, $inline_url );
	}

	/**
	 * Verifies that a featured-image abort on the update path names the media
	 * its cleanup could not delete.
	 */
	public function test_featured_image_abort_on_update_names_media_it_could_not_delete(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import once without media so the post exists.
		$post_data = array(
			'id'        => 9612,
			'title'     => 'Post Whose Update Cleanup Cannot Delete Its Media',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/update-cleanup-survivor',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);

		// ARRANGE: Re-import with a downloadable inline image, a featured image
		// that 404s, and attachment deletion blocked.
		$inline_url                = 'https://source.example.com/inline-update-survivor.jpg';
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'content'        => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		$block_deletion = static fn() => false;
		add_filter( 'pre_delete_attachment', $block_deletion, 10, 3 );

		// ACT: Re-import the same post, hitting the update path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_delete_attachment', $block_deletion, 10 );
		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: The featured image aborted the re-import, and the surviving
		// attachment is named in both the message and the result payload.
		$this->assertFalse( $result['success'], 'The re-import should fail.' );
		$this->assertSame(
			'featured_image_import_failed',
			$result['original_error_code'] ?? null,
			'The featured image should be the reported cause.'
		);

		$this->assert_survivor_reported( $result, $inline_url );
	}

	/**
	 * Verifies that a rejected post insert names the media its cleanup could
	 * not delete.
	 */
	public function test_rejected_insert_names_media_it_could_not_delete(): void {
		// ARRANGE: One downloadable inline image, a rejected post insert, and
		// attachment deletion blocked.
		$inline_url                = 'https://source.example.com/inline-insert-survivor.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$reject_insert = $this->reject_post_insert();

		$block_deletion = static fn() => false;
		add_filter( 'pre_delete_attachment', $block_deletion, 10, 3 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ACT: Attempt the import.
		$result = $this->import_service->import_post(
			array(
				'id'        => 9613,
				'title'     => 'Post Whose Rejected Insert Leaves Media Behind',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/insert-cleanup-survivor',
				'post_type' => 'posts',
			),
			$session_id
		);

		remove_filter( 'pre_delete_attachment', $block_deletion, 10 );
		remove_filter( 'wp_insert_post_empty_content', $reject_insert, 10 );

		// ASSERT: The rejected insert aborted the import, and the surviving
		// attachment is named in both the message and the result payload.
		$this->assertFalse( $result['success'], 'The import should fail.' );
		$this->assertSame(
			'empty_content',
			$result['original_error_code'] ?? null,
			'The rejected insert should be the reported cause.'
		);

		$this->assert_survivor_reported( $result, $inline_url );
	}

	/**
	 * Verifies that a meta failure on the create path names the media its
	 * cleanup could not delete.
	 */
	public function test_meta_failure_on_create_names_media_it_could_not_delete(): void {
		// ARRANGE: One downloadable inline image and a custom meta key whose
		// write is blocked, with attachment deletion blocked too.
		$inline_url                = 'https://source.example.com/inline-meta-survivor.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $inline_url . '" alt="inline"></p>',
			'meta'    => array( 'custom_field' => 'value' ),
		);

		$block_meta = static function ( $check, $object_id, $meta_key ) {
			unset( $object_id );
			return 'custom_field' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 3 );

		$block_deletion = static fn() => false;
		add_filter( 'pre_delete_attachment', $block_deletion, 10, 3 );

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ACT: Attempt the import.
		$result = $this->import_service->import_post(
			array(
				'id'        => 9610,
				'title'     => 'Post Whose Meta Write Fails',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/meta-failure-survivor',
				'post_type' => 'posts',
			),
			$session_id
		);

		remove_filter( 'pre_delete_attachment', $block_deletion, 10 );
		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: The surviving attachment is named in the result payload.
		$this->assertFalse( $result['success'], 'The import should fail.' );

		$this->assert_survivor_reported( $result, $inline_url );
	}

	/**
	 * Verifies that a terms failure on the create path names the media its
	 * cleanup could not delete.
	 */
	public function test_terms_failure_on_create_names_media_it_could_not_delete(): void {
		// ARRANGE: One downloadable inline image and a term that cannot be
		// created, with attachment deletion blocked.
		$inline_url                = 'https://source.example.com/inline-terms-survivor.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $inline_url . '" alt="inline"></p>',
			'terms'   => array( 'category' => array( 'Uncreatable Term' ) ),
		);

		$fail_terms     = $this->fail_term_creation();
		$block_deletion = static fn() => false;
		add_filter( 'pre_delete_attachment', $block_deletion, 10, 3 );

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ACT: Attempt the import.
		$result = $this->import_service->import_post(
			array(
				'id'        => 9611,
				'title'     => 'Post Whose Term Write Fails',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/terms-failure-survivor',
				'post_type' => 'posts',
			),
			$session_id
		);

		remove_filter( 'pre_delete_attachment', $block_deletion, 10 );
		remove_filter( 'pre_insert_term', $fail_terms );

		// ASSERT: The surviving attachment is named in the result payload.
		$this->assertFalse( $result['success'], 'The import should fail.' );

		$this->assert_survivor_reported( $result, $inline_url );
	}

	/**
	 * Verifies that an abort keeps the media the preceding post of the same
	 * bulk run brought in, which the tracked list is scoped to exclude.
	 */
	public function test_abort_keeps_media_of_the_preceding_post_in_the_run(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$inline_url = 'https://source.example.com/inline-preceding.jpg';

		// ARRANGE: The first post of the run imports an inline image.
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$first = $this->import_service->import_post(
			array(
				'id'        => 9607,
				'title'     => 'First Post Of The Run',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/run-first-post',
				'post_type' => 'posts',
			),
			$session_id
		);
		$this->assertTrue( $first['success'], 'The first import should succeed.' );

		$kept_id = $this->find_attachment_by_original_url( $inline_url );
		$this->assertNotNull( $kept_id, 'The first import should create the attachment.' );

		// ARRANGE: The next post of the same run aborts on its featured image.
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'content'        => '<p>Content.</p>',
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		// ACT: Import the second post through the same service instance, as a
		// bulk run does.
		$result = $this->import_service->import_post(
			array(
				'id'        => 9608,
				'title'     => 'Second Post Of The Run',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/run-second-post',
				'post_type' => 'posts',
			),
			$session_id
		);

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: The second import aborted without taking the first post's
		// media with it.
		$this->assertFalse( $result['success'], 'The second import should fail.' );
		$this->assertNotNull(
			get_post( $kept_id ),
			"An abort must not delete the preceding post's media."
		);
	}

	/**
	 * Verifies that an aborted re-import keeps the media an earlier import
	 * already brought in, which the live post still uses.
	 */
	public function test_aborted_reimport_keeps_media_the_existing_post_uses(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$inline_url = 'https://source.example.com/inline-kept.jpg';

		// ARRANGE: A first import downloads the inline image and succeeds.
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $inline_url . '" alt="inline"></p>',
		);

		$post_data = array(
			'id'        => 9604,
			'title'     => 'Post Whose Media Must Survive A Failed Re-import',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/reimport-keeps-media',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );

		$kept_id = $this->find_attachment_by_original_url( $inline_url );
		$this->assertNotNull( $kept_id, 'The first import should create the attachment.' );

		// ARRANGE: Re-import the same content with a featured image that 404s,
		// so the run aborts after deduplicating onto the existing attachment.
		$this->mock_post_overrides['featured_media'] = 100;

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		// ACT: Re-import the same post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: The re-import aborted, and the deduplicated attachment the
		// live post uses is untouched.
		$this->assertFalse( $result['success'], 'Re-import should fail.' );
		$this->assertNotNull(
			get_post( $kept_id ),
			'An aborted re-import must not delete media it deduplicated onto.'
		);
	}

	/**
	 * Verifies that a discarded concurrent duplicate keeps a featured image the
	 * sideload deduplicated onto, leaving the winner's thumbnail intact.
	 */
	public function test_discarded_duplicate_keeps_deduplicated_featured_image(): void {
		// ARRANGE: An attachment already carrying the featured image's source
		// URL, so the losing run's sideload deduplicates onto it.
		$shared_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'featured.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta(
			$shared_id,
			Options::META_ORIGINAL_URL,
			'https://source.example.com/featured.jpg'
		);

		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$source_id   = 9605;
		$claim_rival = $this->make_rival_claim_filter( $source_id, $shared_id );
		add_filter( 'pre_http_request', $claim_rival, 4, 3 );

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => $source_id,
			'title'     => 'Concurrent Loser With Deduplicated Featured Image',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/duplicate-dedup-featured',
			'post_type' => 'posts',
		);

		// ACT: Import, losing the race to the claim the filter inserts.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $claim_rival, 4 );

		// ASSERT: The duplicate was discarded.
		$this->assertFalse( $result['success'], 'The losing import should fail.' );
		$this->assertStringContainsString( 'discarded', $result['error'] );

		// ASSERT: The attachment survives with the winner still pointing at it;
		// core scrubs _thumbnail_id site-wide when an attachment goes.
		$this->assertNotNull(
			get_post( $shared_id ),
			'A deduplicated featured image must survive the discard.'
		);

		$claims = $this->find_claim_post_ids( $source_id );
		$this->assertCount(
			1,
			$claims,
			'The loser must be deleted, leaving only the winning claim.'
		);
		$this->assertSame(
			$shared_id,
			get_post_thumbnail_id( $claims[0] ),
			"The winner's featured image must be intact."
		);
	}

	/**
	 * Verifies that a discarded concurrent duplicate still deletes a featured
	 * image it sideloaded itself.
	 */
	public function test_discarded_duplicate_deletes_its_own_featured_image(): void {
		// ARRANGE: No pre-existing attachment, so the losing run downloads the
		// featured image and owns it.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$source_id   = 9606;
		$claim_rival = $this->make_rival_claim_filter( $source_id );
		add_filter( 'pre_http_request', $claim_rival, 4, 3 );

		$session_id         = $this->repository->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();
		$created            = array();
		$recorder           = $this->record_created_attachments( $created );

		$post_data = array(
			'id'        => $source_id,
			'title'     => 'Concurrent Loser With Its Own Featured Image',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/duplicate-own-featured',
			'post_type' => 'posts',
		);

		// ACT: Import, losing the race to the claim the filter inserts.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $claim_rival, 4 );
		remove_action( 'add_attachment', $recorder );

		// ASSERT: The duplicate was discarded and took its own media with it.
		$this->assertFalse( $result['success'], 'The losing import should fail.' );
		$this->assertNotSame(
			array(),
			$created,
			'The losing run must sideload its own featured image.'
		);
		$this->assert_no_new_attachments(
			$attachments_before,
			'A featured image the losing run sideloaded must still be deleted.'
		);
	}

	/**
	 * Verifies that a discarded concurrent duplicate names the media its
	 * cleanup could not delete.
	 */
	public function test_discarded_duplicate_names_media_it_could_not_delete(): void {
		// ARRANGE: No pre-existing attachment, so the losing run downloads the
		// featured image, with attachment deletion blocked.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$source_id   = 9614;
		$claim_rival = $this->make_rival_claim_filter( $source_id );
		add_filter( 'pre_http_request', $claim_rival, 4, 3 );

		$block_deletion = static fn() => false;
		add_filter( 'pre_delete_attachment', $block_deletion, 10, 3 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ACT: Import, losing the race to the claim the filter inserts.
		$result = $this->import_service->import_post(
			array(
				'id'        => $source_id,
				'title'     => 'Concurrent Loser Whose Cleanup Cannot Delete',
				'content'   => '<p>Content.</p>',
				'link'      => 'https://source.example.com/duplicate-cleanup-survivor',
				'post_type' => 'posts',
			),
			$session_id
		);

		remove_filter( 'pre_delete_attachment', $block_deletion, 10 );
		remove_filter( 'pre_http_request', $claim_rival, 4 );

		// ASSERT: The discard aborted the import, and the featured image the
		// losing run downloaded is named in both the message and the result
		// payload.
		$this->assertFalse(
			$result['success'],
			'The losing import should fail.'
		);
		$this->assertSame(
			'duplicate_import',
			$result['original_error_code'] ?? null,
			'The discard should be the reported cause.'
		);

		$this->assert_survivor_reported(
			$result,
			'https://source.example.com/featured.jpg'
		);
	}

	/**
	 * Asserts that an aborted import reported the attachment its cleanup could
	 * not delete, in both the result payload and the error message.
	 *
	 * @param array  $result     Import result.
	 * @param string $inline_url Source URL of the blocked attachment.
	 */
	private function assert_survivor_reported(
		array $result,
		string $inline_url
	): void {
		$survivor_id = $this->find_attachment_by_original_url( $inline_url );
		$this->assertNotNull(
			$survivor_id,
			'The blocked attachment should remain.'
		);
		$this->assertSame(
			array( $survivor_id ),
			$result['media_ids'] ?? array(),
			'The result must carry the surviving attachment ID.'
		);
		$this->assertStringContainsString(
			(string) $survivor_id,
			$result['error'],
			'The error must name the surviving attachment.'
		);
	}

	/**
	 * Records attachment IDs created while the action is registered, so a test
	 * can assert the run downloaded media before asserting cleanup removed it.
	 *
	 * @param int[] $created Receives each new attachment ID.
	 * @return Closure The action, so the caller can remove it.
	 */
	private function record_created_attachments( array &$created ): Closure {
		$recorder = static function ( int $attachment_id ) use ( &$created ): void {
			$created[] = $attachment_id;
		};

		add_action( 'add_attachment', $recorder );

		return $recorder;
	}

	/**
	 * Returns the ID of the attachment recorded against a source media URL.
	 *
	 * @param string $original_url Source media URL.
	 * @return int|null Attachment ID, or null when none is recorded.
	 */
	private function find_attachment_by_original_url( string $original_url ): ?int {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'suppress_filters' => false,
				'meta_key'         => Options::META_ORIGINAL_URL,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $original_url,
			)
		);

		return array() === $attachments ? null : $attachments[0]->ID;
	}

	/**
	 * Returns the IDs of every post claiming a source post ID.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return int[] Post IDs, newest first.
	 */
	private function find_claim_post_ids( int $source_post_id ): array {
		return array_map(
			static fn( \WP_Post $post ): int => $post->ID,
			get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => 'any',
					'posts_per_page'   => 5,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => (string) $source_post_id,
				)
			)
		);
	}

	/**
	 * Returns a pre_http_request filter that inserts a rival claim for a source
	 * post the first time its record is fetched.
	 *
	 * Register at priority 4 — ahead of the media mocks — so the claim lands
	 * after the import's identity check and before its own insert, giving it
	 * the lower post ID the losing branch requires. Its thumbnail has to be set
	 * here too, since the claim does not exist before the import begins.
	 *
	 * @param int      $source_post_id Source post ID to claim.
	 * @param int|null $thumbnail_id   Attachment to set as the claim's featured
	 *                                 image.
	 * @return Closure
	 */
	private function make_rival_claim_filter(
		int $source_post_id,
		?int $thumbnail_id = null
	): Closure {
		$claimed = false;

		return static function (
			$preempt,
			array $_args,
			string $url
		) use (
			$source_post_id,
			$thumbnail_id,
			&$claimed
		) {
			if ( $claimed || 1 !== preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
				return $preempt;
			}

			$claimed   = true;
			$winner_id = wp_insert_post(
				array(
					'post_title'  => 'Concurrent winner',
					'post_status' => 'draft',
					'post_type'   => 'post',
					'meta_input'  => array(
						Options::META_SOURCE_POST_ID  => $source_post_id,
						Options::META_SOURCE_SITE_URL => 'https://source.example.com',
						Options::META_IMPORTED_FROM   => Options::META_IMPORTED_FROM_VALUE,
					),
				)
			);

			if ( is_int( $winner_id ) && null !== $thumbnail_id ) {
				set_post_thumbnail( $winner_id, $thumbnail_id );
			}

			return $preempt;
		};
	}

	/**
	 * Returns a pre_http_request filter that intercepts the wp/v2/media JSON
	 * endpoint for a specific media ID and returns a distinct source_url.
	 *
	 * Register at priority 6 so it runs after mock_media_api_request (priority
	 * 5) and overrides its response for the targeted media ID; other media
	 * IDs fall through to the default mock.
	 *
	 * @param int    $media_id Media ID to override.
	 * @param string $filename Source file name (must match a fixture extension).
	 * @return Closure
	 */
	private function make_distinct_media_url_filter(
		int $media_id,
		string $filename
	): Closure {
		return function ( $preempt, array $args, string $url ) use ( $media_id, $filename ) {
			unset( $args );
			if ( str_contains( $url, 'wp-json/wp/v2/media/' . $media_id ) ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'source_url' => 'https://source.example.com/' . $filename,
						)
					),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}
			return $preempt;
		};
	}

	/**
	 * Returns a pre_http_request filter that makes the media JSON API return
	 * 404.
	 *
	 * Registered at priority 6 so it runs after the mock at priority 5 and
	 * overrides the normal mock response to simulate a failed API request.
	 *
	 * @return Closure
	 */
	private function make_featured_image_fail_filter(): Closure {
		return function ( $preempt, array $args, string $url ) {
			unset( $args );
			if ( str_contains( $url, 'wp-json/wp/v2/media/' ) ) {
				return array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => 'Not Found',
					'headers'  => array(),
				);
			}
			return $preempt;
		};
	}

	/**
	 * Blocks term creation for the rest of the test, the trigger these rollback
	 * cases use to reach the terms-update failure path. Only terms that have to
	 * be created fail; an existing one still resolves.
	 *
	 * @return Closure The filter, so the caller can remove it.
	 */
	private function fail_term_creation(): Closure {
		$filter = static fn(): WP_Error => new WP_Error(
			'insert_term_failed',
			'Simulated term insertion failure.'
		);

		add_filter( 'pre_insert_term', $filter );

		return $filter;
	}

	/**
	 * Rejects the import's post insert, the trigger these cases use to reach
	 * the rejected-insert abort. Only posts are rejected, so the sideload's own
	 * attachment inserts still go through.
	 *
	 * @return Closure The filter, so the caller can remove it.
	 */
	private function reject_post_insert(): Closure {
		$filter = static fn( $maybe_empty, array $postarr ): bool =>
			'post' === ( $postarr['post_type'] ?? '' ) || (bool) $maybe_empty;

		add_filter( 'wp_insert_post_empty_content', $filter, 10, 2 );

		return $filter;
	}
}
