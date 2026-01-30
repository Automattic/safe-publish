<?php
/**
 * Import History Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Safe_Publish\Admin\Import_History;
use WP_Error;

/**
 * Import History Test.
 *
 * Tests the Import_History class functionality for tracking import sessions and rollbacks.
 */
class ImportHistoryTest extends MockeryTestCase {

	/**
	 * @var Import_History Import_History instance for testing.
	 */
	private Import_History $import_history;

	/**
	 * @var array Storage for mock post meta.
	 */
	private static array $post_meta = array();

	/**
	 * @var array Storage for mock posts.
	 */
	private static array $posts = array();

	/**
	 * @var int Counter for auto-incrementing post IDs.
	 */
	private static int $post_id_counter = 1;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->import_history  = new Import_History();
		self::$post_meta       = array();
		self::$posts           = array();
		self::$post_id_counter = 1;

		// Reset global test storage.
		global $safe_publish_test_posts, $safe_publish_test_post_meta, $safe_publish_test_post_id_counter;
		$safe_publish_test_posts           = array();
		$safe_publish_test_post_meta       = array();
		$safe_publish_test_post_id_counter = 1;
	}

	/**
	 * Tears down test fixtures.
	 */
	#[\Override]
	protected function tearDown(): void {
		Mockery::close();
		self::$post_meta = array();
		self::$posts     = array();

		// Reset global test storage.
		global $safe_publish_test_posts, $safe_publish_test_post_meta, $safe_publish_test_post_id_counter;
		$safe_publish_test_posts           = array();
		$safe_publish_test_post_meta       = array();
		$safe_publish_test_post_id_counter = 1;

		parent::tearDown();
	}

	/**
	 * Helper to get mock post meta from global storage.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed Meta value.
	 */
	public static function get_mock_post_meta( int $post_id, string $meta_key ): mixed {
		global $safe_publish_test_post_meta;
		if ( isset( $safe_publish_test_post_meta[ $post_id ] ) && array_key_exists( $meta_key, $safe_publish_test_post_meta[ $post_id ] ) ) {
			return $safe_publish_test_post_meta[ $post_id ][ $meta_key ];
		}
		return '';
	}

	/**
	 * Helper to set mock post meta in global storage.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function set_mock_post_meta( int $post_id, string $meta_key, mixed $meta_value ): void {
		global $safe_publish_test_post_meta;
		if ( ! isset( $safe_publish_test_post_meta[ $post_id ] ) ) {
			$safe_publish_test_post_meta[ $post_id ] = array();
		}
		$safe_publish_test_post_meta[ $post_id ][ $meta_key ] = $meta_value;
	}

	/**
	 * Helper to create mock post in global storage.
	 *
	 * @param array $args Post args.
	 * @return int Post ID.
	 */
	public static function create_mock_post( array $args ): int {
		global $safe_publish_test_posts, $safe_publish_test_post_meta, $safe_publish_test_post_id_counter;

		$post_id = $safe_publish_test_post_id_counter++;

		$safe_publish_test_posts[ $post_id ] = (object) array_merge(
			array(
				'ID'           => $post_id,
				'post_title'   => '',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_parent'  => 0,
				'post_author'  => 1,
				'post_excerpt' => '',
			),
			$args,
			array( 'ID' => $post_id )
		);

		// Handle meta_input.
		if ( isset( $args['meta_input'] ) && is_array( $args['meta_input'] ) ) {
			if ( ! isset( $safe_publish_test_post_meta[ $post_id ] ) ) {
				$safe_publish_test_post_meta[ $post_id ] = array();
			}
			foreach ( $args['meta_input'] as $key => $value ) {
				$safe_publish_test_post_meta[ $post_id ][ $key ] = $value;
			}
		}

		return $post_id;
	}

	/**
	 * Helper to get mock post from global storage.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Post object or null.
	 */
	public static function get_mock_post( int $post_id ): ?object {
		global $safe_publish_test_posts;
		return $safe_publish_test_posts[ $post_id ] ?? null;
	}

	/**
	 * Helper to delete mock post from global storage.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if deleted.
	 */
	public static function delete_mock_post( int $post_id ): bool {
		global $safe_publish_test_posts, $safe_publish_test_post_meta;
		if ( isset( $safe_publish_test_posts[ $post_id ] ) ) {
			unset( $safe_publish_test_posts[ $post_id ] );
			unset( $safe_publish_test_post_meta[ $post_id ] );
			return true;
		}
		return false;
	}

	// =========================================================================
	// Tests for Constants
	// =========================================================================

	/**
	 * Verifies that the session post type constant is defined.
	 */
	public function test_session_post_type_constant_is_defined(): void {
		$this->assertEquals( 'safe_publish_import_session', Import_History::SESSION_POST_TYPE );
	}

	/**
	 * Verifies that the log post type constant is defined.
	 */
	public function test_log_post_type_constant_is_defined(): void {
		$this->assertEquals( 'safe_publish_import_log', Import_History::LOG_POST_TYPE );
	}

	// =========================================================================
	// Tests for create_session()
	// =========================================================================

	/**
	 * Verifies that create_session returns an integer post ID on success.
	 */
	public function test_create_session_returns_post_id(): void {
		$session_id = $this->import_history->create_session( 'https://example.com', 'bulk' );

		$this->assertIsInt( $session_id );
		$this->assertGreaterThan( 0, $session_id );
	}

	/**
	 * Verifies that create_session sets the correct post type.
	 */
	public function test_create_session_sets_correct_post_type(): void {
		$session_id = $this->import_history->create_session( 'https://example.com', 'bulk' );

		$post = self::get_mock_post( $session_id );
		$this->assertNotNull( $post );
		$this->assertEquals( Import_History::SESSION_POST_TYPE, $post->post_type );
	}

	/**
	 * Verifies that create_session sets session metadata.
	 */
	public function test_create_session_sets_metadata(): void {
		$source_url = 'https://example.com';
		$session_id = $this->import_history->create_session( $source_url, 'bulk' );

		$this->assertEquals( $source_url, self::get_mock_post_meta( $session_id, 'source_url' ) );
		$this->assertEquals( 'bulk', self::get_mock_post_meta( $session_id, 'session_type' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session_id, 'total_items' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session_id, 'successful' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session_id, 'failed' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session_id, 'updated' ) );
		$this->assertEquals( 'in_progress', self::get_mock_post_meta( $session_id, 'status' ) );
	}

	/**
	 * Verifies that create_session accepts different session types.
	 */
	public function test_create_session_accepts_single_type(): void {
		$session_id = $this->import_history->create_session( 'https://example.com', 'single' );

		$this->assertEquals( 'single', self::get_mock_post_meta( $session_id, 'session_type' ) );
	}

	/**
	 * Verifies that create_session defaults to bulk type.
	 */
	public function test_create_session_defaults_to_bulk_type(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->assertEquals( 'bulk', self::get_mock_post_meta( $session_id, 'session_type' ) );
	}

	// =========================================================================
	// Tests for log_import_action()
	// =========================================================================

	/**
	 * Verifies that log_import_action returns a log ID.
	 */
	public function test_log_import_action_returns_log_id(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'success',
			456
		);

		$this->assertIsInt( $log_id );
		$this->assertGreaterThan( 0, $log_id );
	}

	/**
	 * Verifies that log_import_action sets correct post type.
	 */
	public function test_log_import_action_sets_correct_post_type(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'success',
			456
		);

		$log = self::get_mock_post( $log_id );
		$this->assertEquals( Import_History::LOG_POST_TYPE, $log->post_type );
	}

	/**
	 * Verifies that log_import_action sets post parent to session ID.
	 */
	public function test_log_import_action_sets_post_parent(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'success',
			456
		);

		$log = self::get_mock_post( $log_id );
		$this->assertEquals( $session_id, $log->post_parent );
	}

	/**
	 * Verifies that log_import_action stores the title.
	 */
	public function test_log_import_action_stores_title(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'success',
			456
		);

		$log = self::get_mock_post( $log_id );
		$this->assertEquals( 'Test Post Title', $log->post_title );
	}

	/**
	 * Verifies that log_import_action stores metadata.
	 */
	public function test_log_import_action_stores_metadata(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'success',
			456
		);

		$this->assertEquals( $session_id, self::get_mock_post_meta( $log_id, 'session_id' ) );
		$this->assertEquals( 123, self::get_mock_post_meta( $log_id, 'external_id' ) );
		$this->assertEquals( 'success', self::get_mock_post_meta( $log_id, 'status' ) );
		$this->assertEquals( 456, self::get_mock_post_meta( $log_id, 'post_id' ) );
	}

	/**
	 * Verifies that log_import_action stores error message.
	 */
	public function test_log_import_action_stores_error_message(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'error',
			null,
			'Connection timeout'
		);

		$log      = self::get_mock_post( $log_id );
		$log_data = json_decode( $log->post_content, true );
		$this->assertEquals( 'Connection timeout', $log_data['error_message'] );
	}

	/**
	 * Verifies that log_import_action stores changes.
	 */
	public function test_log_import_action_stores_changes(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$changes    = array(
			'previous_content' => 'Old content',
			'previous_title'   => 'Old title',
		);
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'updated',
			456,
			null,
			$changes
		);

		$stored_changes = self::get_mock_post_meta( $log_id, 'content_changes' );
		$this->assertEquals( $changes, $stored_changes );
	}

	/**
	 * Verifies that log_import_action does not store changes when empty.
	 */
	public function test_log_import_action_does_not_store_empty_changes(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post Title',
			'success',
			456,
			null,
			array()
		);

		$stored_changes = self::get_mock_post_meta( $log_id, 'content_changes' );
		$this->assertEmpty( $stored_changes );
	}

	// =========================================================================
	// Tests for update_session_stats()
	// =========================================================================

	/**
	 * Verifies that update_session_stats increments total_items.
	 */
	public function test_update_session_stats_increments_total_items(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->update_session_stats( $session_id, 'success' );

		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'total_items' ) );
	}

	/**
	 * Verifies that update_session_stats increments successful on success.
	 */
	public function test_update_session_stats_increments_successful(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->update_session_stats( $session_id, 'success' );

		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'successful' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session_id, 'failed' ) );
	}

	/**
	 * Verifies that update_session_stats increments failed on error.
	 */
	public function test_update_session_stats_increments_failed(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->update_session_stats( $session_id, 'error' );

		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'failed' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session_id, 'successful' ) );
	}

	/**
	 * Verifies that update_session_stats increments both successful and updated.
	 */
	public function test_update_session_stats_increments_updated(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->update_session_stats( $session_id, 'updated' );

		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'successful' ) );
		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'updated' ) );
	}

	/**
	 * Verifies that update_session_stats accumulates stats correctly.
	 */
	public function test_update_session_stats_accumulates(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->update_session_stats( $session_id, 'success' );
		$this->import_history->update_session_stats( $session_id, 'success' );
		$this->import_history->update_session_stats( $session_id, 'error' );
		$this->import_history->update_session_stats( $session_id, 'updated' );

		$this->assertEquals( 4, self::get_mock_post_meta( $session_id, 'total_items' ) );
		$this->assertEquals( 3, self::get_mock_post_meta( $session_id, 'successful' ) );
		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'failed' ) );
		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'updated' ) );
	}

	// =========================================================================
	// Tests for complete_session()
	// =========================================================================

	/**
	 * Verifies that complete_session sets status to completed.
	 */
	public function test_complete_session_sets_completed_status(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->assertEquals( 'in_progress', self::get_mock_post_meta( $session_id, 'status' ) );

		$this->import_history->complete_session( $session_id );

		$this->assertEquals( 'completed', self::get_mock_post_meta( $session_id, 'status' ) );
	}

	/**
	 * Verifies that complete_session sets end_time.
	 */
	public function test_complete_session_sets_end_time(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->complete_session( $session_id );

		$end_time = self::get_mock_post_meta( $session_id, 'end_time' );
		$this->assertNotEmpty( $end_time );
	}

	// =========================================================================
	// Tests for store_content_diff()
	// =========================================================================

	/**
	 * Verifies that store_content_diff stores old and new content.
	 */
	public function test_store_content_diff_stores_content(): void {
		$post_id = self::create_mock_post( array( 'post_title' => 'Test Post' ) );

		$this->import_history->store_content_diff(
			$post_id,
			'Old content here',
			'New content here'
		);

		$diff_data = self::get_mock_post_meta( $post_id, 'safe_publish_content_history' );
		$this->assertIsArray( $diff_data );
		$this->assertEquals( 'Old content here', $diff_data['old_content'] );
		$this->assertEquals( 'New content here', $diff_data['new_content'] );
	}

	/**
	 * Verifies that store_content_diff sets diff_date.
	 */
	public function test_store_content_diff_sets_date(): void {
		$post_id = self::create_mock_post( array( 'post_title' => 'Test Post' ) );

		$this->import_history->store_content_diff(
			$post_id,
			'Old content',
			'New content'
		);

		$diff_data = self::get_mock_post_meta( $post_id, 'safe_publish_content_history' );
		$this->assertArrayHasKey( 'diff_date', $diff_data );
		$this->assertNotEmpty( $diff_data['diff_date'] );
	}

	// =========================================================================
	// Tests for generate_comprehensive_diff_html() via reflection
	// =========================================================================

	/**
	 * Verifies that diff HTML is generated for title changes.
	 */
	public function test_generate_diff_html_shows_title_changes(): void {
		$method = new \ReflectionMethod( Import_History::class, 'generate_comprehensive_diff_html' );
		
		$html = $method->invoke(
			$this->import_history,
			'Old Title',
			'New Title',
			'',
			'',
			'Content',
			'Content'
		);

		$this->assertStringContainsString( 'Title Changes', $html );
		$this->assertStringContainsString( 'Old Title', $html );
		$this->assertStringContainsString( 'New Title', $html );
	}

	/**
	 * Verifies that diff HTML is generated for excerpt changes.
	 */
	public function test_generate_diff_html_shows_excerpt_changes(): void {
		$method = new \ReflectionMethod( Import_History::class, 'generate_comprehensive_diff_html' );
		
		$html = $method->invoke(
			$this->import_history,
			'Title',
			'Title',
			'Old Excerpt',
			'New Excerpt',
			'Content',
			'Content'
		);

		$this->assertStringContainsString( 'Excerpt Changes', $html );
		$this->assertStringContainsString( 'Old Excerpt', $html );
		$this->assertStringContainsString( 'New Excerpt', $html );
	}

	/**
	 * Verifies that diff HTML is generated for content changes.
	 */
	public function test_generate_diff_html_shows_content_changes(): void {
		$method = new \ReflectionMethod( Import_History::class, 'generate_comprehensive_diff_html' );
		
		$html = $method->invoke(
			$this->import_history,
			'Title',
			'Title',
			'',
			'',
			'Old Content',
			'New Content'
		);

		$this->assertStringContainsString( 'Content Changes', $html );
		$this->assertStringContainsString( 'Old Content', $html );
		$this->assertStringContainsString( 'New Content', $html );
	}

	/**
	 * Verifies that diff HTML does not show title section when titles are same.
	 */
	public function test_generate_diff_html_hides_unchanged_title(): void {
		$method = new \ReflectionMethod( Import_History::class, 'generate_comprehensive_diff_html' );
		
		$html = $method->invoke(
			$this->import_history,
			'Same Title',
			'Same Title',
			'',
			'',
			'Old Content',
			'New Content'
		);

		$this->assertStringNotContainsString( 'Title Changes', $html );
	}

	/**
	 * Verifies that diff HTML does not show excerpt section when excerpts are same.
	 */
	public function test_generate_diff_html_hides_unchanged_excerpt(): void {
		$method = new \ReflectionMethod( Import_History::class, 'generate_comprehensive_diff_html' );
		
		$html = $method->invoke(
			$this->import_history,
			'Title',
			'Title',
			'Same Excerpt',
			'Same Excerpt',
			'Old Content',
			'New Content'
		);

		$this->assertStringNotContainsString( 'Excerpt Changes', $html );
	}

	/**
	 * Verifies that diff HTML contains proper CSS classes.
	 */
	public function test_generate_diff_html_has_css_classes(): void {
		$method = new \ReflectionMethod( Import_History::class, 'generate_comprehensive_diff_html' );
		
		$html = $method->invoke(
			$this->import_history,
			'Old',
			'New',
			'',
			'',
			'Old Content',
			'New Content'
		);

		$this->assertStringContainsString( 'safe-publish-diff-container', $html );
		$this->assertStringContainsString( 'safe-publish-diff-section', $html );
		$this->assertStringContainsString( 'safe-publish-diff-before', $html );
		$this->assertStringContainsString( 'safe-publish-diff-after', $html );
	}

	// =========================================================================
	// Integration-style Tests (testing method interactions)
	// =========================================================================

	/**
	 * Verifies a complete import workflow.
	 */
	public function test_complete_import_workflow(): void {
		// Create session.
		$session_id = $this->import_history->create_session( 'https://example.com', 'bulk' );
		$this->assertIsInt( $session_id );

		// Log multiple imports.
		$this->import_history->log_import_action(
			$session_id,
			100,
			'Post 1',
			'success',
			500
		);
		$this->import_history->update_session_stats( $session_id, 'success' );

		$this->import_history->log_import_action(
			$session_id,
			101,
			'Post 2',
			'updated',
			501,
			null,
			array( 'previous_content' => 'Old content' )
		);
		$this->import_history->update_session_stats( $session_id, 'updated' );

		$this->import_history->log_import_action(
			$session_id,
			102,
			'Post 3',
			'error',
			null,
			'Failed to fetch'
		);
		$this->import_history->update_session_stats( $session_id, 'error' );

		// Complete session.
		$this->import_history->complete_session( $session_id );

		// Verify final state.
		$this->assertEquals( 'completed', self::get_mock_post_meta( $session_id, 'status' ) );
		$this->assertEquals( 3, self::get_mock_post_meta( $session_id, 'total_items' ) );
		$this->assertEquals( 2, self::get_mock_post_meta( $session_id, 'successful' ) );
		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'failed' ) );
		$this->assertEquals( 1, self::get_mock_post_meta( $session_id, 'updated' ) );
	}

	/**
	 * Verifies that multiple sessions are independent.
	 */
	public function test_multiple_sessions_are_independent(): void {
		$session1_id = $this->import_history->create_session( 'https://site1.com', 'bulk' );
		$session2_id = $this->import_history->create_session( 'https://site2.com', 'single' );

		$this->import_history->update_session_stats( $session1_id, 'success' );
		$this->import_history->update_session_stats( $session1_id, 'success' );
		$this->import_history->update_session_stats( $session2_id, 'error' );

		$this->assertEquals( 2, self::get_mock_post_meta( $session1_id, 'total_items' ) );
		$this->assertEquals( 2, self::get_mock_post_meta( $session1_id, 'successful' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session1_id, 'failed' ) );

		$this->assertEquals( 1, self::get_mock_post_meta( $session2_id, 'total_items' ) );
		$this->assertEquals( 0, self::get_mock_post_meta( $session2_id, 'successful' ) );
		$this->assertEquals( 1, self::get_mock_post_meta( $session2_id, 'failed' ) );
	}

	// =========================================================================
	// Edge Case Tests
	// =========================================================================

	/**
	 * Verifies that create_session handles empty source URL.
	 */
	public function test_create_session_with_empty_source_url(): void {
		$session_id = $this->import_history->create_session( '' );

		$this->assertIsInt( $session_id );
		$this->assertEquals( '', self::get_mock_post_meta( $session_id, 'source_url' ) );
	}

	/**
	 * Verifies that log_import_action handles null post_id.
	 */
	public function test_log_import_action_with_null_post_id(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Failed Import',
			'error',
			null,
			'Connection failed'
		);

		$this->assertNull( self::get_mock_post_meta( $log_id, 'post_id' ) );
	}

	/**
	 * Verifies that store_content_diff handles empty content.
	 */
	public function test_store_content_diff_with_empty_content(): void {
		$post_id = self::create_mock_post( array( 'post_title' => 'Test' ) );

		$this->import_history->store_content_diff( $post_id, '', 'New content' );

		$diff_data = self::get_mock_post_meta( $post_id, 'safe_publish_content_history' );
		$this->assertEquals( '', $diff_data['old_content'] );
		$this->assertEquals( 'New content', $diff_data['new_content'] );
	}

	/**
	 * Verifies that log_import_action stores content in JSON format.
	 */
	public function test_log_import_action_stores_json_content(): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );
		$log_id     = $this->import_history->log_import_action(
			$session_id,
			123,
			'Test Post',
			'success',
			456
		);

		$log     = self::get_mock_post( $log_id );
		$decoded = json_decode( $log->post_content, true );

		$this->assertIsArray( $decoded );
		$this->assertEquals( $session_id, $decoded['session_id'] );
		$this->assertEquals( 123, $decoded['external_id'] );
		$this->assertEquals( 'success', $decoded['status'] );
		$this->assertEquals( 456, $decoded['post_id'] );
	}

	// =========================================================================
	// Data Provider Tests
	// =========================================================================

	/**
	 * Data provider for session status tests.
	 *
	 * @return array
	 */
	public static function session_status_provider(): array {
		return array(
			'success status' => array( 'success', 'successful', 1 ),
			'error status'   => array( 'error', 'failed', 1 ),
			'updated status' => array( 'updated', 'successful', 1 ),
		);
	}

	/**
	 * Verifies that update_session_stats handles different statuses correctly.
	 *
	 * @dataProvider session_status_provider
	 * @param string $status       Input status.
	 * @param string $expected_key Expected meta key to be incremented.
	 * @param int    $expected_val Expected value.
	 */
	public function test_update_session_stats_with_different_statuses(
		string $status,
		string $expected_key,
		int $expected_val
	): void {
		$session_id = $this->import_history->create_session( 'https://example.com' );

		$this->import_history->update_session_stats( $session_id, $status );

		$this->assertEquals( $expected_val, self::get_mock_post_meta( $session_id, $expected_key ) );
	}
}
