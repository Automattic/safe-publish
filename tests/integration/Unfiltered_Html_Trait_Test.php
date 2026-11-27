<?php
/**
 * Integration tests for the unfiltered HTML grant helper
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_UnitTestCase;

/**
 * Exercises the helper kses-sensitive tests rely on, so a grant that misses the
 * acting user or leaks into later tests fails here.
 */
class Unfiltered_Html_Trait_Test extends WP_UnitTestCase {

	use Unfiltered_Html_Trait;

	/**
	 * Verifies that granting the acting author lets them save unfiltered HTML.
	 */
	public function test_grant_reaches_the_acting_author(): void {
		// ARRANGE: Act as an author, whose role lacks unfiltered_html.
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'author' ) )
		);
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$content = '<p>Kept.</p><script>alert(1)</script>';

		// ACT: Grant the acting user, then save the markup as them.
		$this->grant_current_user_unfiltered_html();
		$post_id = self::factory()->post->create(
			array( 'post_content' => $content )
		);

		// ASSERT: The author holds the capability and kses left the markup.
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$this->assertSame(
			$content,
			get_post_field( 'post_content', $post_id )
		);
	}

	/**
	 * Verifies that the grant skips the anonymous user, whose object later
	 * tests inherit.
	 */
	public function test_grant_skips_the_anonymous_user(): void {
		// ARRANGE: Act as no one.
		wp_set_current_user( 0 );

		// ACT: Grant the acting user.
		$this->grant_current_user_unfiltered_html();

		// ASSERT: The anonymous user still lacks the capability.
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
	}
}
