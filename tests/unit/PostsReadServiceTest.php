<?php
/**
 * Public posts read service contract tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Posts_Read_Service;
use Safe_Publish\API\Post_Type_Fetcher;
use WP_Error;

/**
 * Verifies public validation and source response contracts.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostsReadServiceTest extends TestCase {

	/**
	 * Verifies that missing URL validation precedes credential validation.
	 */
	public function test_missing_url_precedes_auth(): void {
		// ARRANGE: No source URL or credential is configured.
		set_test_env( 'SAFE_PUBLISH_SHARED_SECRET', null );
		$service = $this->service();

		// ACT: Both source-reading methods receive default inputs.
		$listing = $service->list_posts( array() );
		$types   = $service->fetch_post_types( array() );

		// ASSERT: Both expose the original URL error before any auth error.
		foreach ( array( $listing, $types ) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame(
				'Source site URL is required.',
				$result->get_error_message()
			);
		}
	}

	/**
	 * Verifies that missing and short credentials fail even an empty batch.
	 */
	public function test_auth_precedes_empty_batch(): void {
		// ARRANGE: The service receives no IDs and invalid credentials.
		$service = $this->service();
		foreach ( array( null, 'short' ) as $secret ) {
			set_test_env( 'SAFE_PUBLISH_SHARED_SECRET', $secret );

			// ACT: Validate the batch before attempting its empty shortcut.
			$result = $service->sync_status_batch( array() );

			// ASSERT: Auth errors retain the transport status and distinction.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame(
				array( 'status' => 401 ),
				$result->get_error_data()
			);
			$this->assertSame(
				null === $secret
					? 'posts_read_missing_secret'
					: 'posts_read_short_secret',
				$result->get_error_code()
			);
		}
	}

	/**
	 * Verifies that the empty sync response serializes as an object map.
	 */
	public function test_empty_batch_keeps_json_map(): void {
		// ARRANGE: Valid credentials and IDs that sanitize to zero.
		set_test_env(
			'SAFE_PUBLISH_SHARED_SECRET',
			'posts-service-test-secret'
		);
		$service = $this->service();

		// ACT: Resolve a batch with no positive IDs.
		$result = $service->sync_status_batch(
			array( 'source_ids' => array( 0, '0', 'invalid' ) )
		);

		// ASSERT: The UI sees an object, not an array, for empty statuses.
		$this->assertSame( '{"statuses":{}}', wp_json_encode( $result ) );
	}

	/**
	 * Verifies that the batch cap applies after deduplication.
	 */
	public function test_batch_cap(): void {
		// ARRANGE: Valid credentials and batches around the unique-ID limit.
		set_test_env(
			'SAFE_PUBLISH_SHARED_SECRET',
			'posts-service-test-secret'
		);
		$service = $this->service();

		// ACT: Submit 101 raw entries with 100 unique IDs, then 101 unique IDs.
		$accepted = $service->sync_status_batch(
			array(
				'source_ids' => array_merge( range( 1, 100 ), array( 100 ) ),
			)
		);
		$rejected = $service->sync_status_batch(
			array( 'source_ids' => range( 1, 101 ) )
		);

		// ASSERT: Accept the deduplicated limit and reject the oversized batch.
		$this->assertSame( '{"statuses":{}}', wp_json_encode( $accepted ) );
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame(
			'Sync status check is limited to 100 posts at a time.',
			$rejected->get_error_message()
		);
	}

	/**
	 * Verifies that post-type payloads and source errors pass through intact.
	 */
	public function test_post_types_preserve_payload_and_errors(): void {
		// ARRANGE: A fetcher returns success and then a source failure.
		set_test_env(
			'SAFE_PUBLISH_SHARED_SECRET',
			'posts-service-test-secret'
		);
		$types   = array(
			array(
				'slug' => 'post',
				'name' => 'Posts',
			),
		);
		$error   = new WP_Error( 'source_failure', 'Source unavailable.' );
		$fetcher = $this->createMock( Post_Type_Fetcher::class );
		$fetcher->expects( $this->exactly( 2 ) )
			->method( 'fetch_post_types' )
			->with(
				'https://source.example.com/blog',
				array( 'shared_secret' => 'posts-service-test-secret' )
			)
			->willReturnOnConsecutiveCalls( $types, $error );
		$service = $this->service( $fetcher );
		$input   = array(
			'source_site_url' => ' https://source.example.com/blog ',
		);

		// ACT: Fetch twice through the public interface.
		$success = $service->fetch_post_types( $input );
		$failure = $service->fetch_post_types( $input );

		// ASSERT: No payload wrapping or error replacement occurs.
		$this->assertSame( $types, $success );
		$this->assertSame( $error, $failure );
	}

	/**
	 * Constructs the public reader with unused dependencies kept inert.
	 *
	 * @param Post_Type_Fetcher|null $fetcher Optional source type double.
	 * @return Posts_Read_Service Public reader.
	 */
	private function service(
		?Post_Type_Fetcher $fetcher = null
	): Posts_Read_Service {
		return new Posts_Read_Service(
			new Fake_Catalog_Source_Posts_API(),
			new History_Repository(),
			new Fake_Import_Status_Service(),
			$fetcher ?? $this->createMock( Post_Type_Fetcher::class ),
			new Attention_Issues_Repository()
		);
	}
}
