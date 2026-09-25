<?php
/**
 * Diff renderer featured media integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Source_Post_Type_Resolver;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;
use WP_Error;
use WP_REST_Request;

/**
 * Covers the featured media section of the diff: What it reports, and when it
 * stays empty because the import resolves to the attachment the post already
 * holds.
 *
 * @psalm-suppress InvalidArgument
 */
class Diff_Renderer_Featured_Media_Test extends Integration_Test_Case {

	use Per_Source_Id_Media_Api_Mock_Trait;
	use Image_Byte_Mock_Trait;

	private const SOURCE             = 'https://source.example.com';
	private const OTHER_SOURCE       = 'https://other.example.com';
	private const SOURCE_POST_ID     = 4242;
	private const MEDIA_ID           = 9700001;
	private const OTHER_MEDIA_ID     = 9700002;
	private const MALFORMED_MEDIA_ID = 9700003;
	private const QUERY_MEDIA_ID     = 9700004;
	private const RELATIVE_MEDIA_ID  = 9700005;
	private const MEDIA_SRC          = 'https://source.example.com/wp-content/uploads/2025/01/featured.jpg';
	private const OTHER_SRC          = 'https://source.example.com/wp-content/uploads/2025/02/other.jpg';
	private const UNRELATED_SRC      = 'https://source.example.com/wp-content/uploads/2024/12/unrelated.jpg';
	private const OTHER_SOURCE_SRC   = 'https://other.example.com/wp-content/uploads/2025/03/featured.jpg';
	private const RELATIVE_SRC       = '/wp-content/uploads/2025/04/relative.jpg';
	private const MOVED_SRC          = 'https://source.example.com/wp-content/uploads/2026/05/featured.jpg';

	/**
	 * Local post the diff runs against.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Sets up the connected source and the local post claiming the source post.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Source_Post_Type_Resolver::reset_cache();
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );

		$this->post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Same Title',
				'post_content' => '<p>Same content.</p>',
				'post_excerpt' => 'Same excerpt.',
			)
		);
		update_post_meta(
			$this->post_id,
			Options::META_SOURCE_POST_ID,
			self::SOURCE_POST_ID
		);
		update_post_meta(
			$this->post_id,
			Options::META_SOURCE_SITE_URL,
			Options::get_connected_site_url_with_path()
		);
	}

	/**
	 * Serves the media records for the seeded source media IDs.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id(
		int $source_media_id
	): ?array {
		$source_urls = array(
			self::MEDIA_ID       => self::MEDIA_SRC,
			self::OTHER_MEDIA_ID => self::OTHER_SRC,
		);

		if ( ! isset( $source_urls[ $source_media_id ] ) ) {
			return null;
		}

		return array(
			'id'         => $source_media_id,
			'source_url' => $source_urls[ $source_media_id ],
			'media_type' => 'image',
			'mime_type'  => 'image/jpeg',
		);
	}

	/**
	 * Imports a featured image through the real importer, the way
	 * Post_Import_Service does.
	 *
	 * @param int    $media_id Source media ID.
	 * @param string $origin   Origin recorded on the attachment.
	 * @return int Attachment ID.
	 */
	private function import_featured(
		int $media_id,
		string $origin = self::SOURCE
	): int {
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		try {
			$attachment_id = ( new Media_Importer( new HTTP_Client() ) )
				->import_featured_image( $media_id, $origin, array() );
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
		}

		$this->assertIsInt( $attachment_id );

		return $attachment_id;
	}

	/**
	 * Sideloads a source URL the way an inline media import does, recording the
	 * original URL but no featured media meta.
	 *
	 * @param string $source_url Source media URL.
	 * @param string $origin     Origin recorded on the attachment.
	 * @return int Attachment ID.
	 */
	private function sideload(
		string $source_url,
		string $origin = self::SOURCE
	): int {
		$this->add_image_byte_response_mock();

		try {
			$attachment_id = ( new Media_Importer( new HTTP_Client() ) )
				->import_owned_media_as_attachment( $source_url, $origin );
		} finally {
			$this->remove_image_byte_response_mock();
		}

		$this->assertIsInt( $attachment_id );

		return $attachment_id;
	}

	/**
	 * Renders the diff against a source post that differs only in its featured
	 * media ID.
	 *
	 * @param int    $incoming_featured_id Featured media ID the source advertises.
	 * @param bool   $fail_media_fetch     Optional. Whether the media fetch returns
	 *                                     an error. Default false.
	 * @param string $source_url_override  Optional. source_url the media record
	 *                                     serves for the advertised ID. Default ''.
	 * @return array<string, mixed> Diff result.
	 */
	private function render_diff(
		int $incoming_featured_id,
		bool $fail_media_fetch = false,
		string $source_url_override = ''
	): array {
		$bodies = array(
			self::MEDIA_ID           => self::MEDIA_SRC,
			self::OTHER_MEDIA_ID     => self::OTHER_SRC,
			self::MALFORMED_MEDIA_ID => array( 'not-a-string' ),
			self::QUERY_MEDIA_ID     => self::MEDIA_SRC . '?w=800',
			self::RELATIVE_MEDIA_ID  => self::RELATIVE_SRC,
		);

		if ( '' !== $source_url_override ) {
			$bodies[ $incoming_featured_id ] = $source_url_override;
		}

		$make_request = static function ( $url ) use (
			$incoming_featured_id,
			$fail_media_fetch,
			$bodies
		) {
			$url = (string) $url;

			if ( str_contains( $url, '/catalog/post-types' ) ) {
				return \_safe_publish_test_catalog_response();
			}

			// Keyed off the requested ID, so asking for the wrong media record
			// cannot pass by serving the expected body.
			if ( 1 === preg_match( '#/wp/v2/media/(\d+)#', $url, $matches ) ) {
				if ( $fail_media_fetch ) {
					return new WP_Error(
						'http_request_failed',
						'Media request timed out.'
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array( 'source_url' => $bodies[ (int) $matches[1] ] ?? '' )
					),
				);
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'title'          => array( 'raw' => 'Same Title' ),
						'content'        => array( 'raw' => '<p>Same content.</p>' ),
						'excerpt'        => array( 'raw' => 'Same excerpt.' ),
						'meta'           => array(),
						'featured_media' => $incoming_featured_id,
					)
				),
			);
		};

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'post' );

		$result = ( new Diff_Renderer() )->render_diff(
			$request,
			$make_request,
			array()
		);

		$this->assertIsArray( $result );

		return $result;
	}

	/**
	 * Asserts that no section of the diff carries changes, covering everything
	 * the client folds into its "no differences" state.
	 *
	 * @param array<string, mixed> $result Diff result.
	 */
	private function assertNoDifferences( array $result ): void {
		$this->assertSame( '', $result['contentDiffHtml'] );
		$this->assertSame( '', $result['nonContentDiffs']['title'] );
		$this->assertSame( '', $result['nonContentDiffs']['excerpt'] );
		$this->assertSame( '', $result['nonContentDiffs']['taxonomies'] );
		$this->assertSame( '', $result['nonContentDiffs']['meta'] );
		$this->assertSame( '', $result['nonContentDiffs']['featuredMedia'] );
		$this->assertSame(
			array(),
			array_filter(
				$result['blockDiffs'],
				static fn( array $block ): bool => 'unchanged' !== $block['status']
			)
		);
	}

	/**
	 * Verifies that an unchanged featured image leaves every diff section
	 * empty, so the client reaches its "no differences" state.
	 *
	 * @param string $stored_option Connected-site option value under test.
	 *
	 * @dataProvider option_shape_provider
	 */
	public function test_unchanged_featured_image_reports_no_difference(
		string $stored_option
	): void {
		// ARRANGE: The post holds the imported copy of the source's image.
		update_option( Options::OPTION_CONNECTED_SITE_URL, $stored_option );
		$attachment_id = $this->import_featured(
			self::MEDIA_ID,
			untrailingslashit( $stored_option )
		);
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff against a source post that changed nothing.
		$result = $this->render_diff( self::MEDIA_ID );

		// ASSERT: Nothing is reported, including the featured media section.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Verifies that a source image served from a new URL under an unchanged
	 * media ID reports no difference, so the comparison rests on the media ID
	 * and the origin it was imported from.
	 *
	 * @param string $stored_option Connected-site option value under test.
	 *
	 * @dataProvider option_shape_provider
	 */
	public function test_moved_source_url_matches_by_media_id(
		string $stored_option
	): void {
		// ARRANGE: The post holds the imported copy of the source's image.
		update_option( Options::OPTION_CONNECTED_SITE_URL, $stored_option );
		$attachment_id = $this->import_featured(
			self::MEDIA_ID,
			untrailingslashit( $stored_option )
		);
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff with the record serving a URL the destination
		// has no attachment for.
		$result = $this->render_diff( self::MEDIA_ID, false, self::MOVED_SRC );

		// ASSERT: The media ID alone identifies the destination copy.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Supplies connected-site option shapes; the import stores the origin
	 * without a trailing slash, so the lookup has to match either way.
	 *
	 * @return array<string, array{0: string}> Option values.
	 */
	public static function option_shape_provider(): array {
		return array(
			'no trailing slash'   => array( self::SOURCE ),
			'with trailing slash' => array( self::SOURCE . '/' ),
		);
	}

	/**
	 * Verifies that a source post repointed to a different image reports a
	 * featured media difference.
	 */
	public function test_repointed_featured_image_reports(): void {
		// ARRANGE: The post holds the copy of one image; the source now
		// advertises another.
		$attachment_id = $this->import_featured( self::MEDIA_ID );
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff.
		$result = $this->render_diff( self::OTHER_MEDIA_ID );

		// ASSERT: The featured media section reports, and it is the only one.
		$this->assertNotSame( '', $result['nonContentDiffs']['featuredMedia'] );
		$this->assertSame( '', $result['contentDiffHtml'] );

		// ASSERT: A difference the import applies carries no note.
		$this->assertStringNotContainsString(
			'safe-publish-diff-notes',
			$result['nonContentDiffs']['featuredMedia']
		);
	}

	/**
	 * Verifies that a repoint to an image the destination library already
	 * holds reports, since the import would swap the thumbnail for it.
	 */
	public function test_repoint_to_already_imported_image_reports(): void {
		// ARRANGE: Both images are imported; the post holds the first.
		$attachment_id = $this->import_featured( self::MEDIA_ID );
		$this->import_featured( self::OTHER_MEDIA_ID );
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff against a source post advertising the second.
		$result = $this->render_diff( self::OTHER_MEDIA_ID );

		// ASSERT: The section reports the swap the import would apply.
		$this->assertStringContainsString(
			self::OTHER_SRC,
			$result['nonContentDiffs']['featuredMedia']
		);
	}

	/**
	 * Verifies that a source image with no destination copy reports, even when
	 * the post has no thumbnail for it to differ from.
	 */
	public function test_unimported_featured_image_reports_without_thumbnail(): void {
		// ARRANGE: Nothing imported, and the post carries no thumbnail.
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->post_id ) );

		// ACT: Render the diff against a source post that advertises an image.
		$result = $this->render_diff( self::MEDIA_ID );

		// ASSERT: The section reports the image the import would add.
		$this->assertNotSame( '', $result['nonContentDiffs']['featuredMedia'] );
	}

	/**
	 * Verifies that a source image with no destination copy reports when the
	 * post holds an unrelated thumbnail.
	 */
	public function test_unimported_featured_image_reports_over_thumbnail(): void {
		// ARRANGE: The post holds an attachment sideloaded from another URL.
		$attachment_id = $this->sideload( self::UNRELATED_SRC );
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff against a source post that advertises an image.
		$result = $this->render_diff( self::MEDIA_ID );

		// ASSERT: The section reports the repoint the import would apply.
		$this->assertNotSame( '', $result['nonContentDiffs']['featuredMedia'] );
	}

	/**
	 * Verifies that an attachment sideloaded from the source URL, without the
	 * featured media meta, reports no difference: The import deduplicates
	 * against that URL and reuses it.
	 */
	public function test_url_matched_attachment_reports_no_difference(): void {
		// ARRANGE: An attachment sideloaded from the source URL, set by hand as
		// the post's thumbnail.
		$attachment_id = $this->sideload( self::MEDIA_SRC );
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff against a source post advertising that image.
		$result = $this->render_diff( self::MEDIA_ID );

		// ASSERT: Nothing is reported.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Verifies that an attachment imported from another source site does not
	 * satisfy the comparison, even when it carries the same media ID.
	 */
	public function test_other_source_attachment_reports(): void {
		// ARRANGE: An attachment the other source served, stamped with the same
		// featured media ID the import records.
		$attachment_id = $this->sideload(
			self::OTHER_SOURCE_SRC,
			self::OTHER_SOURCE
		);
		update_post_meta(
			$attachment_id,
			Options::META_FEATURED_MEDIA_ID,
			self::MEDIA_ID
		);
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff against the connected source.
		$result = $this->render_diff( self::MEDIA_ID );

		// ASSERT: The section reports.
		$this->assertNotSame( '', $result['nonContentDiffs']['featuredMedia'] );
	}

	/**
	 * Verifies that a source post that dropped its featured image reports the
	 * image the destination still holds, noted as one the import leaves alone.
	 */
	public function test_removed_featured_image_reports_with_note(): void {
		// ARRANGE: The post holds an imported image the source no longer has.
		$attachment_id = $this->import_featured( self::MEDIA_ID );
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff against a source post with no featured media.
		$result = $this->render_diff( 0 );

		// ASSERT: The section reports, and says the import will not apply it.
		$this->assertStringContainsString(
			'<em>None</em>',
			$result['nonContentDiffs']['featuredMedia']
		);
		$this->assertStringContainsString(
			'The import will not clear this image.',
			$result['nonContentDiffs']['featuredMedia']
		);
	}

	/**
	 * Verifies that a source media record whose source_url is not a string is
	 * treated as a missing image rather than cast into the markup, and that
	 * the unusable record is logged.
	 */
	public function test_non_string_source_url_counts_as_absent(): void {
		// ARRANGE: The post holds an unrelated image, so the preview renders.
		Audit_Log_Table::clear( 'content' );
		set_post_thumbnail(
			$this->post_id,
			$this->sideload( self::UNRELATED_SRC )
		);

		// ACT: Render the diff against a malformed media record.
		$result = $this->render_diff( self::MALFORMED_MEDIA_ID );

		// ASSERT: The record resolves to nothing, so no cast value reaches the
		// markup and the preview stays empty.
		$this->assertSame( '', $result['nonContentDiffs']['featuredMedia'] );

		// ASSERT: The unusable record is recorded rather than swallowed.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'content',
				'event_type' => Log_Events::CONTENT_FETCH_INVALID_RESPONSE,
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame(
			self::MALFORMED_MEDIA_ID,
			$events[0]['data']['source_post_id']
		);
	}

	/**
	 * Verifies that a query-bearing source_url resolves to the attachment
	 * sideloaded from its clean form.
	 */
	public function test_query_bearing_source_url_reports_no_difference(): void {
		// ARRANGE: The post holds the image sideloaded from the clean URL.
		set_post_thumbnail(
			$this->post_id,
			$this->sideload( self::MEDIA_SRC )
		);

		// ACT: Render the diff against a record serving the same URL with a
		// resize query string.
		$result = $this->render_diff( self::QUERY_MEDIA_ID );

		// ASSERT: Nothing is reported.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Verifies that a relative source_url is resolved against the source site
	 * before it reaches the preview markup.
	 */
	public function test_relative_source_url_renders_absolute(): void {
		// ARRANGE: No thumbnail, so the incoming image renders on its own.
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->post_id ) );

		// ACT: Render the diff against a record serving a host-relative URL.
		$result = $this->render_diff( self::RELATIVE_MEDIA_ID );

		// ASSERT: The markup points at the source site, not the destination.
		$this->assertStringContainsString(
			self::SOURCE . self::RELATIVE_SRC,
			$result['nonContentDiffs']['featuredMedia']
		);
	}

	/**
	 * Verifies that an unfetchable incoming media record with no destination
	 * copy reports nothing, rather than reading as a removal against the image
	 * the post holds.
	 */
	public function test_unknown_incoming_record_reports_nothing(): void {
		// ARRANGE: The post holds an unrelated image and nothing was imported
		// for the incoming media ID.
		set_post_thumbnail(
			$this->post_id,
			$this->sideload( self::UNRELATED_SRC )
		);

		// ACT: Render the diff with the media endpoint failing.
		$result = $this->render_diff( self::MEDIA_ID, true );

		// ASSERT: The preview reports nothing.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Verifies that an unfetchable incoming media record reports nothing even
	 * when its destination copy is known and the post holds another image.
	 */
	public function test_unreadable_record_with_known_copy_reports_nothing(): void {
		// ARRANGE: The incoming image is imported, but the post holds a
		// different one.
		$this->import_featured( self::MEDIA_ID );
		set_post_thumbnail(
			$this->post_id,
			$this->sideload( self::UNRELATED_SRC )
		);

		// ACT: Render the diff with the media endpoint failing.
		$result = $this->render_diff( self::MEDIA_ID, true );

		// ASSERT: The preview reports nothing rather than reading as a
		// removal against the image the post holds.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Verifies that a post with no featured image on either side reports
	 * nothing.
	 */
	public function test_no_featured_image_on_either_side_reports_nothing(): void {
		// ARRANGE: No thumbnail on the post.
		$this->assertSame( 0, (int) get_post_thumbnail_id( $this->post_id ) );

		// ACT: Render the diff against a source post with no featured media.
		$result = $this->render_diff( 0 );

		// ASSERT: Nothing is reported.
		$this->assertNoDifferences( $result );
	}

	/**
	 * Verifies that a failed source media fetch reports nothing and is still
	 * logged, so the preview stays silent while the failure is recorded.
	 */
	public function test_media_fetch_failure_reports_nothing_and_logs(): void {
		// ARRANGE: The post holds the imported copy, and the audit log is empty.
		Audit_Log_Table::clear( 'content' );
		$attachment_id = $this->import_featured( self::MEDIA_ID );
		set_post_thumbnail( $this->post_id, $attachment_id );

		// ACT: Render the diff with the media endpoint failing.
		$result = $this->render_diff( self::MEDIA_ID, true );

		// ASSERT: The unreadable record leaves the preview empty.
		$this->assertNoDifferences( $result );

		// ASSERT: The fetch failure is still recorded.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'content',
				'event_type' => Log_Events::CONTENT_FETCH_FAILED,
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( self::MEDIA_ID, $events[0]['data']['source_post_id'] );
	}
}
