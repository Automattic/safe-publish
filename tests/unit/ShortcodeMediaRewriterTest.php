<?php
/**
 * Shortcode_Media_Rewriter unit tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Content\Shortcode_Media_Rewriter;

/**
 * Tests the Shortcode_Media_Rewriter [audio]/[video] media import logic.
 */
class ShortcodeMediaRewriterTest extends TestCase {

	private const SOURCE = 'https://source.example.com';

	/**
	 * Builds a rewriter with a static source URL => import-result map. Absent
	 * keys resolve to null (third-party); a false value models a download
	 * failure; a string value models a destination URL.
	 *
	 * @param array<string, string|false> $map Source URL => import result.
	 * @return Shortcode_Media_Rewriter
	 */
	private function build_rewriter( array $map = array() ): Shortcode_Media_Rewriter {
		return new Shortcode_Media_Rewriter(
			static fn ( string $url ): string|false|null => $map[ $url ] ?? null
		);
	}

	/**
	 * Verifies that an [audio] src URL is rewritten to its destination URL.
	 */
	public function test_audio_src_rewritten(): void {
		// ARRANGE: An [audio] shortcode whose src the importer resolves.
		$rewriter = $this->build_rewriter(
			array( self::SOURCE . '/a.mp3' => 'https://dest.example.com/a.mp3' )
		);
		$content  = '[audio src="' . self::SOURCE . '/a.mp3"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: The destination URL replaces the source URL.
		$this->assertSame(
			'[audio src="https://dest.example.com/a.mp3"]',
			$result
		);
	}

	/**
	 * Verifies that each [audio] codec attribute is rewritten.
	 *
	 * @dataProvider audio_codec_provider
	 *
	 * @param string $attr Codec attribute name.
	 */
	public function test_audio_codec_attrs_rewritten( string $attr ): void {
		// ARRANGE: An [audio] shortcode carrying one codec attribute.
		$source   = self::SOURCE . '/a.' . $attr;
		$rewriter = $this->build_rewriter(
			array( $source => 'https://dest.example.com/a.' . $attr )
		);
		$content  = sprintf( '[audio %s="%s"]', $attr, $source );

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: The codec attribute now points at the destination.
		$this->assertSame(
			sprintf(
				'[audio %s="https://dest.example.com/a.%s"]',
				$attr,
				$attr
			),
			$result
		);
	}

	/**
	 * Supplies the audio codec attribute names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function audio_codec_provider(): array {
		return array(
			'mp3'  => array( 'mp3' ),
			'ogg'  => array( 'ogg' ),
			'flac' => array( 'flac' ),
			'm4a'  => array( 'm4a' ),
			'wav'  => array( 'wav' ),
		);
	}

	/**
	 * Verifies that [video] src and poster URLs are both rewritten.
	 */
	public function test_video_src_and_poster_rewritten(): void {
		// ARRANGE: A [video] shortcode with src and poster URLs.
		$rewriter = $this->build_rewriter(
			array(
				self::SOURCE . '/v.mp4'     => 'https://dest.example.com/v.mp4',
				self::SOURCE . '/thumb.jpg' => 'https://dest.example.com/thumb.jpg',
			)
		);
		$content  = '[video src="' . self::SOURCE . '/v.mp4" poster="'
			. self::SOURCE . '/thumb.jpg"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Both URLs are repointed at the destination.
		$this->assertSame(
			'[video src="https://dest.example.com/v.mp4" poster='
				. '"https://dest.example.com/thumb.jpg"]',
			$result
		);
	}

	/**
	 * Verifies that each [video] codec attribute is rewritten.
	 *
	 * @dataProvider video_codec_provider
	 *
	 * @param string $attr Codec attribute name.
	 */
	public function test_video_codec_attrs_rewritten( string $attr ): void {
		// ARRANGE: A [video] shortcode carrying one codec attribute.
		$source   = self::SOURCE . '/v.' . $attr;
		$rewriter = $this->build_rewriter(
			array( $source => 'https://dest.example.com/v.' . $attr )
		);
		$content  = sprintf( '[video %s="%s"]', $attr, $source );

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: The codec attribute now points at the destination.
		$this->assertSame(
			sprintf(
				'[video %s="https://dest.example.com/v.%s"]',
				$attr,
				$attr
			),
			$result
		);
	}

	/**
	 * Supplies the video codec attribute names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function video_codec_provider(): array {
		return array(
			'mp4'  => array( 'mp4' ),
			'm4v'  => array( 'm4v' ),
			'webm' => array( 'webm' ),
			'ogv'  => array( 'ogv' ),
			'flv'  => array( 'flv' ),
		);
	}

	/**
	 * Verifies that audio and video attribute sets do not cross over: poster and
	 * a video codec on [audio], and an audio codec on [video], are left alone.
	 */
	public function test_attribute_scoping_is_tight(): void {
		// ARRANGE: [audio] with video-only attrs and [video] with an audio codec,
		// all resolvable were they in scope.
		$rewriter = $this->build_rewriter(
			array(
				self::SOURCE . '/x.jpg' => 'https://dest.example.com/x.jpg',
				self::SOURCE . '/x.mp4' => 'https://dest.example.com/x.mp4',
				self::SOURCE . '/x.mp3' => 'https://dest.example.com/x.mp3',
			)
		);
		$content  = '[audio poster="' . self::SOURCE . '/x.jpg" mp4="'
			. self::SOURCE . '/x.mp4"]'
			. '[video mp3="' . self::SOURCE . '/x.mp3"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Out-of-scope attributes are byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that a third-party URL (null import result) is left unchanged.
	 */
	public function test_third_party_url_left_unchanged(): void {
		// ARRANGE: src not in the map, so the importer returns null.
		$rewriter = $this->build_rewriter();
		$content  = '[video src="https://youtube.com/watch?v=abc"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that a failed download (false result) is recorded and its URL is
	 * left in place for the abort path.
	 */
	public function test_failed_download_recorded_and_left_in_place(): void {
		// ARRANGE: src whose import fails.
		$source   = self::SOURCE . '/broken.mp3';
		$rewriter = $this->build_rewriter( array( $source => false ) );
		$content  = '[audio src="' . $source . '"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: URL stays put and the failure is exposed via the getter.
		$this->assertSame( $content, $result );
		$this->assertSame(
			array( $source => '' ),
			$rewriter->get_failed_media()
		);
	}

	/**
	 * Verifies that an escaped shortcode ([[audio ...]]) is left unchanged even
	 * when its URL would otherwise resolve.
	 */
	public function test_escaped_shortcode_untouched(): void {
		// ARRANGE: Escaped shortcode wrapping a resolvable URL.
		$source   = self::SOURCE . '/a.mp3';
		$rewriter = $this->build_rewriter(
			array( $source => 'https://dest.example.com/a.mp3' )
		);
		$content  = '[[audio src="' . $source . '"]]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that multiple shortcodes in the same content are each handled.
	 */
	public function test_multiple_shortcodes_all_handled(): void {
		// ARRANGE: An [audio] and a [video] with distinct destinations.
		$rewriter = $this->build_rewriter(
			array(
				self::SOURCE . '/a.mp3' => 'https://dest.example.com/a.mp3',
				self::SOURCE . '/v.mp4' => 'https://dest.example.com/v.mp4',
			)
		);
		$content  = '[audio src="' . self::SOURCE . '/a.mp3"] between '
			. '[video src="' . self::SOURCE . '/v.mp4"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Both source URLs are gone, both destinations present.
		$this->assertStringContainsString(
			'src="https://dest.example.com/a.mp3"',
			$result
		);
		$this->assertStringContainsString(
			'src="https://dest.example.com/v.mp4"',
			$result
		);
		$this->assertStringNotContainsString( self::SOURCE, $result );
	}

	/**
	 * Verifies that single-quoted attribute values are handled and their quoting
	 * style is preserved.
	 */
	public function test_single_quoted_attrs_supported(): void {
		// ARRANGE: Single-quoted src.
		$source   = self::SOURCE . '/a.mp3';
		$rewriter = $this->build_rewriter(
			array( $source => 'https://dest.example.com/a.mp3' )
		);
		$content  = "[audio src='" . $source . "']";

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Value replaced, single quotes preserved.
		$this->assertSame(
			"[audio src='https://dest.example.com/a.mp3']",
			$result
		);
	}

	/**
	 * Verifies that the enclosing form ([video ...]...[/video]) rewrites the
	 * opening tag and leaves the closing tag intact.
	 */
	public function test_enclosing_form_rewrites_opening_tag(): void {
		// ARRANGE: Enclosing [video] with fallback text.
		$source   = self::SOURCE . '/v.mp4';
		$rewriter = $this->build_rewriter(
			array( $source => 'https://dest.example.com/v.mp4' )
		);
		$content  = '[video src="' . $source . '"]Fallback.[/video]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Opening src repointed, body and closing tag preserved.
		$this->assertSame(
			'[video src="https://dest.example.com/v.mp4"]Fallback.[/video]',
			$result
		);
	}

	/**
	 * Verifies that query parameters on the source URL are reapplied to the
	 * destination URL.
	 */
	public function test_query_parameters_reapplied(): void {
		// ARRANGE: src with a query string; importer returns a clean dest URL.
		$source   = self::SOURCE . '/v.mp4?t=30';
		$rewriter = $this->build_rewriter(
			array( $source => 'https://dest.example.com/v.mp4' )
		);
		$content  = '[video src="' . $source . '"]';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: The query parameter is restored on the destination URL.
		$this->assertSame(
			'[video src="https://dest.example.com/v.mp4?t=30"]',
			$result
		);
	}

	/**
	 * Verifies that content with no [audio]/[video] shortcodes is returned
	 * unchanged.
	 */
	public function test_content_without_shortcodes_unchanged(): void {
		// ARRANGE: Prose with an unrelated shortcode and a bare URL.
		$rewriter = $this->build_rewriter(
			array( self::SOURCE . '/a.mp3' => 'https://dest.example.com/a.mp3' )
		);
		$content  = '<p>Hello.</p>[gallery ids="1,2"] ' . self::SOURCE . '/a.mp3';

		// ACT: Run the rewriter.
		$result = $rewriter->rewrite_shortcode_media( $content, self::SOURCE );

		// ASSERT: Content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that empty content short-circuits cleanly.
	 */
	public function test_empty_content_unchanged(): void {
		// ARRANGE + ACT: Empty input.
		$result = $this->build_rewriter()->rewrite_shortcode_media( '', self::SOURCE );

		// ASSERT: Empty output.
		$this->assertSame( '', $result );
	}
}
