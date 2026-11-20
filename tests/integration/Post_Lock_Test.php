<?php
/**
 * Post lock integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Post_Lock;
use WP_UnitTestCase;

/**
 * Tests the option-row lock that serializes imports and rollbacks of a post.
 */
class Post_Lock_Test extends WP_UnitTestCase {

	use Failing_Query_Trait;
	use Held_Post_Lock_Trait;

	/**
	 * Source post ID the lock is taken for.
	 */
	private const SOURCE_ID = 8400;

	/**
	 * Tear down test environment.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->restore_failing_queries();

		parent::tearDown();
	}

	/**
	 * Verifies that acquiring a free lock stores a non-autoloaded row stamped
	 * with the acquisition time.
	 */
	public function test_acquire_takes_a_free_lock(): void {
		// ARRANGE: No request holds the lock.
		$before = time();

		// ACT: Acquire it.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: The lock is held, and its row stays out of autoloaded
		// options.
		$this->assertInstanceOf( Post_Lock::class, $lock );
		$row = $this->post_lock_row( self::SOURCE_ID );
		$this->assertIsArray( $row );
		$this->assertSame( 'off', $row['autoload'] );
		$this->assertGreaterThanOrEqual( $before, (int) $row['option_value'] );
		$this->assertLessThanOrEqual( time(), (int) $row['option_value'] );
	}

	/**
	 * Verifies that a lock another request holds is refused and left intact.
	 */
	public function test_acquire_refuses_a_held_lock(): void {
		// ARRANGE: Another request took the lock a minute ago.
		$held_at = time() - 60;
		$this->hold_post_lock( self::SOURCE_ID, $held_at );

		// ACT: Try to acquire it.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: The lock is refused, and the holder's row is unchanged.
		$this->assertNull( $lock );
		$this->assertSame(
			array(
				'option_value' => (string) $held_at,
				'autoload'     => 'off',
			),
			$this->post_lock_row( self::SOURCE_ID )
		);
	}

	/**
	 * Verifies that a lock held past its TTL is taken over.
	 */
	public function test_acquire_takes_over_an_expired_lock(): void {
		// ARRANGE: A request that took the lock outlived the TTL.
		$this->hold_post_lock( self::SOURCE_ID, time() - Post_Lock::TTL - 1 );
		$before = time();

		// ACT: Acquire the lock.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: The lock is held again, stamped with the new acquisition.
		$this->assertInstanceOf( Post_Lock::class, $lock );
		$row = $this->post_lock_row( self::SOURCE_ID );
		$this->assertIsArray( $row );
		$this->assertGreaterThanOrEqual( $before, (int) $row['option_value'] );
	}

	/**
	 * Verifies that a lock another request takes right before this one writes
	 * is refused, so the write itself decides the race.
	 */
	public function test_acquire_refuses_a_lock_taken_during_its_write(): void {
		// ARRANGE: A rival request inserts the lock row just before this
		// request's insert reaches the database.
		$held_at = time() - 60;
		$this->before_lock_query(
			'INSERT',
			fn () => $this->hold_post_lock( self::SOURCE_ID, $held_at )
		);

		// ACT: Acquire the lock against the rival.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: The lock is refused, and the rival's row is unchanged.
		$this->assertNull( $lock );
		$this->assertSame(
			array(
				'option_value' => (string) $held_at,
				'autoload'     => 'off',
			),
			$this->post_lock_row( self::SOURCE_ID )
		);
	}

	/**
	 * Verifies that an expired lock another request takes over right before
	 * this one does is refused, so only one request wins the takeover.
	 */
	public function test_acquire_refuses_a_takeover_made_during_its_write(): void {
		global $wpdb;

		// ARRANGE: An expired lock a rival request takes over just before this
		// request's takeover reaches the database.
		$this->hold_post_lock( self::SOURCE_ID, time() - Post_Lock::TTL - 1 );
		$taken_at = time() - 60;
		$this->before_lock_query(
			'UPDATE',
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			static fn () => $wpdb->update(
				$wpdb->options,
				array( 'option_value' => (string) $taken_at ),
				array( 'option_name' => Post_Lock::OPTION_PREFIX . self::SOURCE_ID )
			)
		);

		// ACT: Acquire the lock against the rival.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: The lock is refused, and the rival's row is unchanged.
		$this->assertNull( $lock );
		$this->assertSame(
			array(
				'option_value' => (string) $taken_at,
				'autoload'     => 'off',
			),
			$this->post_lock_row( self::SOURCE_ID )
		);
	}

	/**
	 * Verifies that a released lock can be acquired again.
	 */
	public function test_release_frees_the_lock(): void {
		// ARRANGE: The lock is held.
		$lock = Post_Lock::acquire( self::SOURCE_ID );
		$this->assertInstanceOf( Post_Lock::class, $lock );

		// ACT: Release it.
		$lock->release();

		// ASSERT: The row is gone, and the lock can be acquired again.
		$this->assertNull( $this->post_lock_row( self::SOURCE_ID ) );
		$this->assertInstanceOf(
			Post_Lock::class,
			Post_Lock::acquire( self::SOURCE_ID )
		);
	}

	/**
	 * Verifies that a request which outlived its lock leaves the lock another
	 * request took over in place when it releases.
	 */
	public function test_release_keeps_a_lock_another_request_took_over(): void {
		global $wpdb;

		// ARRANGE: The lock is held, then expires and is taken over by another
		// request.
		$lock = Post_Lock::acquire( self::SOURCE_ID );
		$this->assertInstanceOf( Post_Lock::class, $lock );
		$taken_at = time() + Post_Lock::TTL + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => (string) $taken_at ),
			array( 'option_name' => Post_Lock::OPTION_PREFIX . self::SOURCE_ID )
		);

		// ACT: Release the outlived lock.
		$lock->release();

		// ASSERT: The other request still holds the lock.
		$this->assertSame(
			array(
				'option_value' => (string) $taken_at,
				'autoload'     => 'off',
			),
			$this->post_lock_row( self::SOURCE_ID )
		);
		$this->assertNull( Post_Lock::acquire( self::SOURCE_ID ) );
	}

	/**
	 * Verifies that a lock is held per source post.
	 */
	public function test_acquire_ignores_another_posts_lock(): void {
		// ARRANGE: This request holds a different post's lock.
		$this->assertInstanceOf(
			Post_Lock::class,
			Post_Lock::acquire( self::SOURCE_ID + 1 )
		);

		// ACT: Acquire this post's lock.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: Both locks are held.
		$this->assertInstanceOf( Post_Lock::class, $lock );
		$this->assertIsArray( $this->post_lock_row( self::SOURCE_ID + 1 ) );
	}

	/**
	 * Verifies that a lock whose queries fail counts as not acquired.
	 */
	public function test_acquire_refuses_when_the_database_fails(): void {
		// ARRANGE: Every lock query fails.
		$this->fail_queries_matching( Post_Lock::OPTION_PREFIX );

		// ACT: Try to acquire the lock.
		$lock = Post_Lock::acquire( self::SOURCE_ID );

		// ASSERT: The lock is refused.
		$this->assertNull( $lock );
	}

	/**
	 * Runs a rival write once, right before this request's next lock query of
	 * one kind reaches the database.
	 *
	 * @param string   $statement Leading SQL keyword, e.g. INSERT.
	 * @param callable $rival     Rival write.
	 */
	private function before_lock_query( string $statement, callable $rival ): void {
		$callback = static function ( $query ) use (
			&$callback,
			$statement,
			$rival
		) {
			$query = (string) $query;

			if (
				0 !== stripos( ltrim( $query ), $statement )
				|| ! str_contains( $query, Post_Lock::OPTION_PREFIX )
			) {
				return $query;
			}

			remove_filter( 'query', $callback );
			$rival();

			return $query;
		};

		add_filter( 'query', $callback );
	}
}
