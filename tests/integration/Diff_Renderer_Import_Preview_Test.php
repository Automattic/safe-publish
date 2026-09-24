<?php
/**
 * Diff renderer import-preview integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use WP_REST_Request;

/**
 * Covers the comparison running incoming content through the import rewrite
 * before diffing, so it reports what an update would change rather than the
 * rewrite the import already applied.
 *
 * @psalm-suppress InvalidArgument
 */
class Diff_Renderer_Import_Preview_Test extends Integration_Test_Case {

	use Image_Byte_Mock_Trait;

	private const SOURCE         = 'https://source.example.com';
	private const SOURCE_POST_ID = 99123;
	private const SOURCE_REF_ID  = 99500;
	private const SOURCE_LINK_ID = 99501;
	private const IMAGE_URL      = self::SOURCE . '/wp-content/uploads/2024/01/photo.jpg';

	/**
	 * Local post the comparison runs against.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Destination post standing in for the imported nav-link target.
	 *
	 * @var int
	 */
	private int $link_post_id;

	/**
	 * Request URLs attempted since record_http_attempts() was called.
	 *
	 * @var list<string>
	 */
	private array $http_attempts = array();

	/**
	 * Sets up the connected source, the local post, and the two imported
	 * targets the fixture content references.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );

		$this->post_id = self::factory()->post->create();
		$this->mark_imported( $this->post_id, self::SOURCE_POST_ID );

		$this->mark_imported(
			self::factory()->post->create( array( 'post_type' => 'wp_block' ) ),
			self::SOURCE_REF_ID
		);

		$this->link_post_id = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$this->mark_imported( $this->link_post_id, self::SOURCE_LINK_ID );
	}

	/**
	 * Verifies that content the import already rewrote reports no differences
	 * when the source side is unchanged.
	 */
	public function test_imported_content_reports_no_differences(): void {
		// ARRANGE: Import the fixture content and store it as the local post.
		$source = $this->source_content();
		$this->store_imported( $source );

		// ACT: Compare the stored result against the untouched source.
		$result = $this->render_diff( $source );

		// ASSERT: Nothing is reported on either view.
		$this->assertSame( '', $result['contentDiffHtml'] );
		foreach ( $result['blockDiffs'] as $block ) {
			$this->assertSame(
				'unchanged',
				$block['status'],
				sprintf(
					'Block %s should be unchanged.',
					(string) ( $block['current']['name'] ?? 'freeform' )
				)
			);
		}
	}

	/**
	 * Verifies that a genuine source edit is still reported, and that it is
	 * the only block reported.
	 */
	public function test_single_edited_paragraph_is_the_only_block_reported(): void {
		// ARRANGE: Import the fixture, then edit one paragraph on the source.
		$this->store_imported( $this->source_content() );
		$edited = $this->source_content( 'Body, revised.' );

		// ACT: Compare against the edited source.
		$result = $this->render_diff( $edited );

		// ASSERT: Exactly the edited paragraph reports.
		$modified = array_values(
			array_filter(
				$result['blockDiffs'],
				static fn ( array $block ): bool => 'unchanged' !== $block['status']
			)
		);
		$this->assertCount( 1, $modified );
		$this->assertSame( 'core/paragraph', $modified[0]['current']['name'] );
		$this->assertStringContainsString(
			'Body, revised.',
			(string) $modified[0]['incoming']['rendered']
		);
	}

	/**
	 * Verifies that a reference repointed to a post the destination has not
	 * imported is still reported, so precision does not cost recall.
	 */
	public function test_reference_to_unimported_target_still_reports(): void {
		// ARRANGE: Import the fixture, then repoint the reusable block at a
		// source ID no destination post carries.
		$this->store_imported( $this->source_content() );
		$repointed = str_replace(
			'"ref":' . self::SOURCE_REF_ID,
			'"ref":99777',
			$this->source_content()
		);

		// ACT: Compare against the repointed source.
		$result = $this->render_diff( $repointed );

		// ASSERT: The reusable block reports.
		$this->assertSame(
			array( 'core/block' ),
			$this->modified_block_names( $result )
		);
	}

	/**
	 * Verifies that a destination slug changed after the import reports, and
	 * that the preview matches what a re-import would store — the update
	 * really would rewrite the link.
	 */
	public function test_slug_changed_after_import_reports_a_real_change(): void {
		// ARRANGE: Import the fixture under permalinks the slug reaches, then
		// rename the nav-link target.
		$this->set_permalink_structure( '/%postname%/' );
		$source = $this->source_content();
		$this->store_imported( $source );
		wp_update_post(
			array(
				'ID'        => $this->link_post_id,
				'post_name' => 'about-us',
			)
		);

		// ACT: Compare, and re-import the same source for comparison.
		$result    = $this->render_diff( $source );
		$reimport  = $this->import( $source );
		$preview   = $this->preview( $source );
		$permalink = get_permalink( $this->link_post_id );

		// ASSERT: The nav link reports, and the preview agrees with the
		// re-import on the URL it would store.
		$this->assertSame(
			array( 'core/navigation' ),
			$this->modified_block_names( $result )
		);
		$this->assertSame( $reimport, $preview );
		$this->assertStringContainsString( (string) $permalink, $preview );
	}

	/**
	 * Verifies that a reference left unresolved at import time reports once
	 * its target is imported, since the update would repoint it.
	 */
	public function test_reference_resolved_after_import_reports(): void {
		// ARRANGE: Import while the target is missing, then supply it.
		$source = $this->reference_only_content( 99801 );
		$this->store_imported( $source );
		$late_post = self::factory()->post->create(
			array( 'post_type' => 'wp_block' )
		);
		$this->mark_imported( $late_post, 99801 );

		// ACT: Compare against the same, untouched source.
		$result = $this->render_diff( $source );

		// ASSERT: The reference reports, now that it resolves.
		$this->assertSame(
			array( 'core/block' ),
			$this->modified_block_names( $result )
		);
	}

	/**
	 * Verifies that the preview attempts no outbound request and leaves every
	 * post and meta row as it found them.
	 *
	 * Asserts on the attempt rather than the outcome: the bootstrap blocks
	 * unmocked requests, so a download the importer does try would fail
	 * quietly and leave the library untouched all the same.
	 */
	public function test_preview_writes_nothing_and_downloads_nothing(): void {
		// ARRANGE: Content whose media the destination has never imported,
		// plus a cross-post gallery whose set the import would pull and
		// reparent, behind a filter recording every request the run attempts.
		$referenced = self::factory()->post->create();
		$this->mark_imported( $referenced, 99900 );
		$content = $this->image_block( self::SOURCE . '/wp-content/uploads/new.jpg' )
			. "\n\n" . '<!-- wp:paragraph --><p>[audio src="'
			. self::SOURCE . '/wp-content/uploads/clip.mp3"]'
			. '[gallery id="99900"]</p><!-- /wp:paragraph -->';
		$before  = $this->content_snapshot();
		$this->record_http_attempts();

		// ACT: Preview it.
		$preview = $this->preview( $content );

		// ASSERT: Nothing was requested, no row moved, and the unimported URLs
		// are left for the host swap alone.
		$this->assertSame( array(), $this->http_attempts );
		$this->assertSame( $before, $this->content_snapshot() );
		$this->assertStringContainsString( '/wp-content/uploads/new.jpg', $preview );
		$this->assertStringNotContainsString( self::SOURCE, $preview );
	}

	/**
	 * Verifies that the preview reproduces the import's output exactly once
	 * the media and references it needs are already imported, so the two
	 * cannot drift apart.
	 */
	public function test_preview_matches_the_import_for_resolved_content(): void {
		// ARRANGE: Import the fixture once so its media is in the library.
		$source   = $this->source_content();
		$imported = $this->import_with_media( $source );

		// ACT: Preview the same source, with no download mock in place.
		$preview = $this->preview( $source );

		// ASSERT: Byte-identical to what the import stored.
		$this->assertSame( $imported, $preview );
	}

	/**
	 * Verifies that the preview reproduces the destination attachment the
	 * import sideloaded, including the URL its dedup suffix produced.
	 */
	public function test_preview_reproduces_the_imported_attachment(): void {
		// ARRANGE: Import an image block so the attachment exists.
		$this->import_with_media( $this->image_block( self::IMAGE_URL ) );
		$attachment_id  = $this->imported_attachment_id( self::IMAGE_URL );
		$attachment_url = (string) wp_get_attachment_url( $attachment_id );

		// ACT: Preview the same block.
		$preview = $this->preview( $this->image_block( self::IMAGE_URL ) );

		// ASSERT: The destination ID, URL and class all come back.
		$this->assertStringContainsString( '"id":' . $attachment_id, $preview );
		$this->assertStringContainsString( '"url":"' . $attachment_url . '"', $preview );
		$this->assertStringContainsString( 'wp-image-' . $attachment_id, $preview );
	}

	/**
	 * Verifies that a gallery's per-image URL and ID attributes are previewed
	 * too, which the block's own attrs carry separately from its markup.
	 */
	public function test_preview_reproduces_gallery_image_attributes(): void {
		// ARRANGE: Import a gallery carrying one image.
		$gallery = $this->gallery_block( self::IMAGE_URL );
		$this->import_with_media( $gallery );
		$attachment_id  = $this->imported_attachment_id( self::IMAGE_URL );
		$attachment_url = (string) wp_get_attachment_url( $attachment_id );

		// ACT: Preview the same gallery.
		$preview = $this->preview( $gallery );

		// ASSERT: The nested images entry carries the destination pair.
		$this->assertStringContainsString( '"id":' . $attachment_id, $preview );
		$this->assertStringContainsString( '"url":"' . $attachment_url . '"', $preview );
	}

	/**
	 * Verifies that media served from a third-party host is left untouched,
	 * so it reports no difference on either side.
	 */
	public function test_third_party_media_is_left_untouched(): void {
		// ARRANGE: An image block pointing at a host the source does not own.
		$third_party = 'https://cdn.example.net/photo.jpg';
		$content     = $this->image_block( $third_party );
		$this->store_imported( $content );

		// ACT: Compare against the same source content.
		$result = $this->render_diff( $content );

		// ASSERT: Nothing is reported, and the URL survived the preview.
		$this->assertSame( '', $result['contentDiffHtml'] );
		$this->assertStringContainsString(
			$third_party,
			$this->preview( $content )
		);
	}

	/**
	 * Verifies that classic content, which takes the non-block pass, reports
	 * no differences either.
	 */
	public function test_imported_classic_content_reports_no_differences(): void {
		// ARRANGE: Content carrying no block comments, so it takes the markup
		// pass rather than the block walk.
		$classic = '<p>Body with an <a href="' . self::SOURCE
			. '/about">internal link</a>.</p>' . "\n"
			. '<p><img src="' . self::IMAGE_URL
			. '" alt="" class="wp-image-900" /></p>';
		$this->store_imported( $classic );

		// ACT: Compare against the untouched source.
		$result = $this->render_diff( $classic );

		// ASSERT: Nothing is reported.
		$this->assertSame( '', $result['contentDiffHtml'] );
	}

	/**
	 * Builds the fixture content: a reusable-block reference, an internal
	 * link, a nav link, and an image, plus a paragraph a test can edit.
	 *
	 * @param string $paragraph Editable paragraph text.
	 * @return string Source content.
	 */
	private function source_content( string $paragraph = 'Body.' ): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:heading --><h2>Heading.</h2><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>' . $paragraph . '</p><!-- /wp:paragraph -->',
				'<!-- wp:block {"ref":' . self::SOURCE_REF_ID . '} /-->',
				'<!-- wp:paragraph --><p><a href="' . self::SOURCE
					. '/about">About</a></p><!-- /wp:paragraph -->',
				'<!-- wp:navigation -->'
					. '<!-- wp:navigation-link {"id":' . self::SOURCE_LINK_ID
					. ',"kind":"post-type","type":"page","label":"About","url":"'
					. self::SOURCE . '/about"} /-->'
					. '<!-- /wp:navigation -->',
				$this->image_block( self::IMAGE_URL ),
			)
		);
	}

	/**
	 * Builds content holding a single reusable-block reference.
	 *
	 * @param int $source_ref Source post ID the reference names.
	 * @return string Source content.
	 */
	private function reference_only_content( int $source_ref ): string {
		return '<!-- wp:block {"ref":' . $source_ref . '} /-->';
	}

	/**
	 * Builds a core/image block for a URL.
	 *
	 * @param string $url Image URL.
	 * @return string Block markup.
	 */
	private function image_block( string $url ): string {
		return '<!-- wp:image {"id":900,"sizeSlug":"large"} -->'
			. '<figure class="wp-block-image size-large">'
			. '<img src="' . $url . '" alt="" class="wp-image-900"/>'
			. '</figure><!-- /wp:image -->';
	}

	/**
	 * Builds a core/gallery block carrying one image in its attrs.
	 *
	 * @param string $url Image URL.
	 * @return string Block markup.
	 */
	private function gallery_block( string $url ): string {
		return '<!-- wp:gallery {"images":[{"id":900,"url":"' . $url . '"}]} -->'
			. '<figure class="wp-block-gallery has-nested-images">'
			. '<!-- wp:image {"id":900} --><figure class="wp-block-image">'
			. '<img src="' . $url . '" alt="" class="wp-image-900"/>'
			. '</figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
	}

	/**
	 * Records a destination post as imported from the connected source.
	 *
	 * @param int $post_id   Destination post.
	 * @param int $source_id Source post ID it was imported from.
	 */
	private function mark_imported( int $post_id, int $source_id ): void {
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, self::SOURCE );
	}

	/**
	 * Runs the real import over content, with media downloads mocked, and
	 * stores the result as the local post.
	 *
	 * @param string $source Source content.
	 */
	private function store_imported( string $source ): void {
		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => wp_slash( $this->import_with_media( $source ) ),
			)
		);
	}

	/**
	 * Runs the real import over content with the image-byte mock registered.
	 *
	 * @param string $source Source content.
	 * @return string Imported content.
	 */
	private function import_with_media( string $source ): string {
		$this->add_image_byte_response_mock();

		try {
			return $this->import( $source );
		} finally {
			$this->remove_image_byte_response_mock();
		}
	}

	/**
	 * Runs process_content() over content.
	 *
	 * @param string $source Source content.
	 * @return string Imported content.
	 */
	private function import( string $source ): string {
		$media_importer = new Media_Importer( new HTTP_Client() );

		$result = ( new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		) )->process_content( $source, self::SOURCE );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * Runs preview_content() over content with a resolve-only importer, the
	 * way the comparison does.
	 *
	 * @param string $source Source content.
	 * @return string Previewed content.
	 */
	private function preview( string $source ): string {
		$media_importer = new Media_Importer( new HTTP_Client(), true );

		$result = ( new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		) )->preview_content( $source, self::SOURCE );

		$this->assertIsString( $result );

		return $result;
	}

	/**
	 * Returns the attachment ID the import recorded for a source media URL,
	 * failing the test when the import recorded none.
	 *
	 * @param string $source_url Source media URL.
	 * @return int Attachment ID.
	 */
	private function imported_attachment_id( string $source_url ): int {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => Options::META_ORIGINAL_URL,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => $source_url,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$this->assertNotEmpty(
			$found,
			sprintf( 'No attachment was imported from %s.', $source_url )
		);

		return (int) $found[0];
	}

	/**
	 * Starts recording outbound requests without answering any of them, so
	 * the bootstrap's blocker still decides their outcome.
	 */
	private function record_http_attempts(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $args );
				$this->http_attempts[] = (string) $url;

				return $preempt;
			},
			1,
			3
		);
	}

	/**
	 * Snapshots every post and meta row a content pass could touch, so a
	 * comparison catches a new attachment, a reparent, a menu-order write and
	 * a meta write alike.
	 *
	 * @return array{posts: list<array>, meta: list<array>} Table snapshot.
	 */
	private function content_snapshot(): array {
		global $wpdb;

		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'posts' => (array) $wpdb->get_results(
				"SELECT ID, post_content, post_parent, menu_order, post_modified_gmt
				FROM {$wpdb->posts} ORDER BY ID",
				ARRAY_N
			),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'meta'  => (array) $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value
				FROM {$wpdb->postmeta} ORDER BY meta_id",
				ARRAY_N
			),
		);
	}

	/**
	 * Returns the block names the comparison reported as changed.
	 *
	 * @param array $result render_diff() result.
	 * @return list<string> Reported block names.
	 */
	private function modified_block_names( array $result ): array {
		$names = array();

		foreach ( $result['blockDiffs'] as $block ) {
			if ( 'unchanged' !== $block['status'] ) {
				$names[] = (string) ( $block['current']['name']
					?? $block['incoming']['name'] ?? 'freeform' );
			}
		}

		return $names;
	}

	/**
	 * Runs the diff preview against a source payload carrying given content.
	 *
	 * @param string $source Source post content.
	 * @return array render_diff() result.
	 */
	private function render_diff( string $source ): array {
		$make_request = static function ( $url ) use ( $source ) {
			if ( str_contains( (string) $url, '/catalog/post-types' ) ) {
				return \_safe_publish_test_catalog_response();
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'title'   => array( 'raw' => 'Title' ),
						'content' => array( 'raw' => $source ),
						'excerpt' => array( 'raw' => 'Excerpt.' ),
					)
				),
			);
		};

		$request = new WP_REST_Request(
			'POST',
			'/safe-publish/v1/diff-preview'
		);
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
}
