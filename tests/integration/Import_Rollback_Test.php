<?php
/**
 * Import rollback integration tests
 *
 * Tests the abort-and-undo behavior when an import step fails: orphaned
 * attachment cleanup, featured image failures, tracking meta failures,
 * custom meta failures, and term failures.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Closure;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Options;
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
			new Content_Media_Processor( $media_importer )
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager()
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
	 * whose `source_url` points to a `.jpg` URL that the base-class image mock
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
		// sideloaded before wp_insert_post runs. An unknown taxonomy in the terms
		// data triggers the failure after the post is written.
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'terms'          => array( 'nonexistent_taxonomy_xyz' => array( 'Some Term' ) ),
		);

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

		// ASSERT: Import failed due to the unknown taxonomy.
		$this->assertFalse( $result['success'], 'Import should fail when a term taxonomy does not exist.' );
		$this->assertStringContainsString( 'nonexistent_taxonomy_xyz', $result['error'] );

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

		// ASSERT: Post fields and tracking meta must be unchanged: the import
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
	 * An unknown taxonomy in the terms data causes
	 * Meta_Terms_Manager::update_terms() to return a WP_Error. Post fields,
	 * tracking meta, and custom meta must all be restored to their pre-update
	 * values.
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

		// ARRANGE: Fresh content returns updated fields, meta, and an unknown
		// taxonomy to trigger term failure.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Term Rollback Title',
			'content' => '<p>Updated content.</p>',
			'meta'    => array( 'my_field' => 'updated' ),
			'terms'   => array(
				'category'                  => array(
					'Rollback Test Category',
				),
				'nonexistent_taxonomy_term' => array(
					'Some Term',
				),
			),
		);

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a taxonomy error.
		$this->assertFalse(
			$result['success'],
			'Update import should fail for unknown taxonomy.'
		);
		$this->assertStringContainsString(
			'nonexistent_taxonomy_term',
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
		// attachment. An unknown taxonomy then triggers a rollback.
		$new_inline_url            = 'https://source.example.com/new-inline-image.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p>Updated content '
				. '<img src="' . $new_inline_url . '" alt="new">'
				. '</p>',
			'terms'   => array(
				'category'                  => array(
					'Inline Media Cleanup Category',
				),
				'nonexistent_taxonomy_term' => array( 'Some Term' ),
			),
		);

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a taxonomy error.
		$this->assertFalse(
			$result['success'],
			'Update import should fail for unknown taxonomy.'
		);
		$this->assertStringContainsString(
			'nonexistent_taxonomy_term',
			$result['error']
		);

		// ASSERT: The newly sideloaded inline media attachment was cleaned up.
		$this->assert_no_new_attachments(
			$attachments_before_update,
			'New inline media must be deleted after rollback.'
		);
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
}
