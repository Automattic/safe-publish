<?php
/**
 * Integration tests for static media-block attachment-ID remapping.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use WP_Error;
use WP_HTML_Tag_Processor;

/**
 * Exercises the core/cover, core/file, and core/media-text handlers via the
 * public process_content() entry point, asserting that each block's sideloaded
 * media URL and its attachment-ID attr both point at the destination attachment,
 * and that third-party and failed imports leave the ID untouched.
 */
class Content_Processor_Static_Media_Id_Remap_Test extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;

	private const SOURCE      = 'https://source.example.com';
	private const THIRD_PARTY = 'https://cdn.example.org';
	private const MP4_FIXTURE = '/fixtures/media/test-tiny.mp4';

	/**
	 * System under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * Media importer, reused to resolve destination attachment IDs from URLs.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Builds a Content_Processor with real dependencies and wires the fixture
	 * HTTP mocks that stand in for live media downloads.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->media_importer = new Media_Importer( new HTTP_Client() );
		$this->processor      = new Content_Processor(
			$this->media_importer,
			new Content_Media_Processor( $this->media_importer ),
			new Shortcode_ID_Rewriter()
		);

		add_filter( 'pre_http_request', array( $this, 'serve_source_fixture' ), 10, 3 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'fix_empty_temp_files' ), 10, 1 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'fill_empty_mp4_temp' ), 10, 1 );
	}

	/**
	 * Removes the HTTP mocks.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve_source_fixture' ), 10 );
		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'fix_empty_temp_files' ), 10 );
		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'fill_empty_mp4_temp' ), 10 );
		parent::tearDown();
	}

	/**
	 * Serves fixture bytes for source-site image and mp4 URLs; other URLs fall
	 * through so downloads of them fail rather than hit the network.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $args    HTTP arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function serve_source_fixture(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( false !== $preempt || ! str_contains( $url, 'source.example.com' ) ) {
			return $preempt;
		}

		$extension = strtolower(
			pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION )
		);

		$images = array(
			'jpg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
		);

		if ( isset( $images[ $extension ] ) ) {
			return $this->get_fixture_response( 'test-1x1.' . $extension, $images[ $extension ] );
		}

		if ( 'mp4' === $extension ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$bytes = (string) file_get_contents( dirname( __DIR__ ) . self::MP4_FIXTURE );
			return array(
				'headers'       => array(
					'content-type'   => 'video/mp4',
					'content-length' => (string) strlen( $bytes ),
				),
				'body'          => $bytes,
				'response'      => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'       => array(),
				'filename'      => null,
				'http_response' => null,
			);
		}

		return $preempt;
	}

	/**
	 * Populates the empty temp file download_url() leaves behind for an mp4
	 * sideload with the fixture's bytes.
	 *
	 * @param array $file File array with 'tmp_name' and 'name' keys.
	 * @return array File array.
	 */
	public function fill_empty_mp4_temp( array $file ): array {
		$temp_path = $file['tmp_name'] ?? '';

		if ( ! file_exists( $temp_path ) || filesize( $temp_path ) > 0 ) {
			return $file;
		}

		if ( 'mp4' !== strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) ) ) {
			return $file;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents(
			$temp_path,
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			(string) file_get_contents( dirname( __DIR__ ) . self::MP4_FIXTURE )
		);
		clearstatcache( true, $temp_path );

		return $file;
	}

	/**
	 * Verifies that a core/cover image background has its url sideloaded and its
	 * id attr repointed at the destination attachment.
	 */
	public function test_remaps_cover_image_id(): void {
		// ARRANGE: a cover block whose background image and id reference the source.
		$source_id = 900001;
		$url       = self::SOURCE . '/bg.jpg';
		$content   = $this->cover_block( $url, $source_id );
		$before    = $this->get_attachment_count();

		// ACT: run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: exactly one attachment, and url + id both point at it.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$attrs = $this->block_attrs( $result, 'core/cover' );
		$this->assertStringContainsString( 'wp-content/uploads', (string) $attrs['url'] );
		$this->assertSame(
			$this->media_importer->get_attachment_id_from_url( (string) $attrs['url'] ),
			$attrs['id']
		);
		$this->assertNotSame( $source_id, $attrs['id'] );
		$this->assertStringNotContainsString( $url, $result );
		$this->assertSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that a core/file block has its href sideloaded and its id attr
	 * repointed, while its DOM-only fileId anchor id is left untouched.
	 */
	public function test_remaps_file_id_and_preserves_fileid(): void {
		// ARRANGE: a file block with a source href, id, and an anchor DOM id.
		$source_id = 900002;
		$href      = self::SOURCE . '/doc.jpg';
		$file_id   = 'wp-block-file--media-abc123';
		$content   = $this->file_block( $href, $source_id, $file_id );
		$before    = $this->get_attachment_count();

		// ACT: run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: one attachment; href + id point at it; fileId anchor id kept.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$attrs = $this->block_attrs( $result, 'core/file' );
		$this->assertStringContainsString( 'wp-content/uploads', (string) $attrs['href'] );
		$this->assertSame(
			$this->media_importer->get_attachment_id_from_url( (string) $attrs['href'] ),
			$attrs['id']
		);
		$this->assertNotSame( $source_id, $attrs['id'] );
		$this->assertStringContainsString( $file_id, $result );
		$this->assertStringNotContainsString( $href, $result );
	}

	/**
	 * Verifies that a core/media-text image, whose mediaUrl is sourced from
	 * innerHTML rather than stored in the block, has its mediaId repointed.
	 */
	public function test_remaps_media_text_image_media_id(): void {
		// ARRANGE: a media-text block with an image in its media figure.
		$source_id = 900003;
		$src       = self::SOURCE . '/photo.jpg';
		$content   = $this->media_text_block( $src, $source_id, 'image' );
		$before    = $this->get_attachment_count();

		// ACT: run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: one attachment; mediaId points at the swapped media src.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$attrs   = $this->block_attrs( $result, 'core/media-text' );
		$new_src = $this->first_media_src( $result );
		$this->assertStringContainsString( 'wp-content/uploads', $new_src );
		$this->assertSame(
			$this->media_importer->get_attachment_id_from_url( $new_src ),
			$attrs['mediaId']
		);
		$this->assertNotSame( $source_id, $attrs['mediaId'] );
		$this->assertStringNotContainsString( $src, $result );
		// mediaUrl is HTML-sourced; the handler must not fabricate it as an attr.
		$this->assertArrayNotHasKey( 'mediaUrl', $attrs );
	}

	/**
	 * Verifies that a core/media-text video, whose media src lives in a video
	 * element, has its mediaId repointed — covering the video extraction branch.
	 */
	public function test_remaps_media_text_video_media_id(): void {
		// ARRANGE: a media-text block with a video in its media figure.
		$source_id = 900004;
		$src       = self::SOURCE . '/clip.mp4';
		$content   = $this->media_text_block( $src, $source_id, 'video' );
		$before    = $this->get_attachment_count();

		// ACT: run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: one attachment; mediaId points at the swapped video src.
		$this->assertSame( $before + 1, $this->get_attachment_count() );
		$attrs   = $this->block_attrs( $result, 'core/media-text' );
		$new_src = $this->first_media_src( $result );
		$this->assertStringContainsString( 'wp-content/uploads', $new_src );
		$this->assertSame(
			$this->media_importer->get_attachment_id_from_url( $new_src ),
			$attrs['mediaId']
		);
		$this->assertNotSame( $source_id, $attrs['mediaId'] );
	}

	/**
	 * Verifies that a third-party cover background is left untouched: no
	 * attachment is created, and the id and url are unchanged.
	 */
	public function test_leaves_third_party_cover_untouched(): void {
		// ARRANGE: a cover block whose background lives on a third-party domain.
		$source_id = 900005;
		$url       = self::THIRD_PARTY . '/bg.jpg';
		$content   = $this->cover_block( $url, $source_id );
		$before    = $this->get_attachment_count();

		// ACT: run the full processing pipeline.
		$result = (string) $this->processor->process_content( $content, self::SOURCE );

		// ASSERT: no import, and both attrs are preserved verbatim.
		$this->assertSame( $before, $this->get_attachment_count() );
		$attrs = $this->block_attrs( $result, 'core/cover' );
		$this->assertSame( $source_id, $attrs['id'] );
		$this->assertSame( $url, $attrs['url'] );
		$this->assertSame( array(), $this->processor->get_failed_media() );
	}

	/**
	 * Verifies that a failed file import leaves the id untouched and records the
	 * source url as a media failure rather than writing a bogus attachment id.
	 */
	public function test_failed_file_import_leaves_id_untouched(): void {
		// ARRANGE: a file block whose href is forced to fail on download.
		$source_id = 900006;
		$href      = self::SOURCE . '/broken.jpg';
		$content   = $this->file_block( $href, $source_id, 'wp-block-file--media-xyz' );

		$fail = static function ( $preempt, array $args, string $url ) use ( $href ) {
			unset( $args );
			return $url === $href
				? new WP_Error( 'http_request_failed', 'forced failure for test' )
				: $preempt;
		};
		add_filter( 'pre_http_request', $fail, 5, 3 );

		$before = $this->get_attachment_count();

		try {
			// ACT: run the full processing pipeline.
			$result = (string) $this->processor->process_content( $content, self::SOURCE );
		} finally {
			remove_filter( 'pre_http_request', $fail, 5 );
		}

		// ASSERT: no attachment; id preserved; the source url is a recorded failure.
		$this->assertSame( $before, $this->get_attachment_count() );
		$attrs = $this->block_attrs( $result, 'core/file' );
		$this->assertSame( $source_id, $attrs['id'] );
		$failed = $this->processor->get_failed_media();
		$this->assertArrayHasKey( $href, $failed );
		$this->assertSame( 'core/file', $failed[ $href ] );
	}

	/**
	 * Builds a core/cover block with an image background and matching id.
	 *
	 * @param string $url Background image url.
	 * @param int    $id  Attachment id attr.
	 * @return string Block markup.
	 */
	private function cover_block( string $url, int $id ): string {
		return '<!-- wp:cover ' . (string) wp_json_encode(
			array(
				'url'      => $url,
				'id'       => $id,
				'dimRatio' => 50,
			)
		) . ' -->' . "\n"
			. '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-'
			. $id . '" alt="" src="' . $url . '" data-object-fit="cover"/>'
			. '<div class="wp-block-cover__inner-container"></div></div>' . "\n"
			. '<!-- /wp:cover -->';
	}

	/**
	 * Builds a core/file block with two file anchors and a DOM anchor id.
	 *
	 * @param string $href    File url.
	 * @param int    $id      Attachment id attr.
	 * @param string $file_id Anchor DOM id (the block's fileId).
	 * @return string Block markup.
	 */
	private function file_block( string $href, int $id, string $file_id ): string {
		return '<!-- wp:file ' . (string) wp_json_encode(
			array(
				'id'   => $id,
				'href' => $href,
			)
		) . ' -->' . "\n"
			. '<div class="wp-block-file"><a id="' . $file_id . '" href="' . $href . '">document</a>'
			. '<a href="' . $href . '" class="wp-block-file__button wp-element-button" download>Download</a></div>'
			. "\n" . '<!-- /wp:file -->';
	}

	/**
	 * Builds a core/media-text block whose media figure holds an image or video.
	 *
	 * @param string $src   Media src.
	 * @param int    $id    mediaId attr.
	 * @param string $type  Media type ('image' or 'video').
	 * @return string Block markup.
	 */
	private function media_text_block( string $src, int $id, string $type ): string {
		$media = 'video' === $type
			? '<video controls src="' . $src . '"></video>'
			: '<img src="' . $src . '" alt=""/>';

		return '<!-- wp:media-text ' . (string) wp_json_encode(
			array(
				'mediaId'   => $id,
				'mediaType' => $type,
			)
		) . ' -->' . "\n"
			. '<div class="wp-block-media-text is-stacked-on-mobile">'
			. '<figure class="wp-block-media-text__media">' . $media . '</figure>'
			. '<div class="wp-block-media-text__content">'
			. '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->'
			. '</div></div>' . "\n" . '<!-- /wp:media-text -->';
	}

	/**
	 * Returns the attrs of the first block matching the given name.
	 *
	 * @param string $content    Serialized block content.
	 * @param string $block_name Block name to find.
	 * @return array<string, mixed> Block attrs, or empty when not found.
	 */
	private function block_attrs( string $content, string $block_name ): array {
		foreach ( parse_blocks( $content ) as $block ) {
			$found = $this->find_block( $block, $block_name );
			if ( null !== $found ) {
				return $found['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Recursively finds the first block matching a name in a subtree.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param string               $name  Block name to find.
	 * @return array<string, mixed>|null The block, or null when not found.
	 */
	private function find_block( array $block, string $name ): ?array {
		if ( ( $block['blockName'] ?? '' ) === $name ) {
			return $block;
		}

		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			$found = $this->find_block( $inner, $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Returns the src of the first img or video in the given content.
	 *
	 * @param string $content Serialized block content.
	 * @return string The media src, or '' when none is present.
	 */
	private function first_media_src( string $content ): string {
		$processor = new WP_HTML_Tag_Processor( $content );
		while ( $processor->next_tag() ) {
			if ( ! in_array( $processor->get_tag(), array( 'IMG', 'VIDEO' ), true ) ) {
				continue;
			}

			$src = $processor->get_attribute( 'src' );
			if ( is_string( $src ) && '' !== $src ) {
				return $src;
			}
		}

		return '';
	}
}
