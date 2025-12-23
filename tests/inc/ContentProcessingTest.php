<?php
declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Content Processing Test.
 *
 * Tests content manipulation and sanitization.
 */
class ContentProcessingTest extends TestCase {

	public function test_gutenberg_block_detection(): void {
		$content_with_blocks    = '<!-- wp:paragraph --><p>Test content</p><!-- /wp:paragraph -->';
		$content_without_blocks = '<p>Regular HTML content</p>';

		$this->assertTrue( false !== strpos( $content_with_blocks, '<!-- wp:' ) );
		$this->assertFalse( false !== strpos( $content_without_blocks, '<!-- wp:' ) );
	}

	public function test_image_src_extraction_from_html(): void {
		$html = '<img src="https://example.com/image.jpg" alt="Test" />';

		preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches );

		$this->assertNotEmpty( $matches );
		$this->assertEquals( 'https://example.com/image.jpg', $matches[1] );
	}

	public function test_url_extraction_from_content(): void {
		$content = '<a href="https://example.com/page">Link</a>';

		preg_match( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		$this->assertNotEmpty( $matches );
		$this->assertEquals( 'https://example.com/page', $matches[1] );
	}

	public function test_wp_image_class_pattern(): void {
		$html = '<img class="wp-image-123" src="test.jpg" />';

		preg_match( '/wp-image-(\d+)/', $html, $matches );

		$this->assertNotEmpty( $matches );
		$this->assertEquals( '123', $matches[1] );
	}

	public function test_block_comment_pattern(): void {
		$content = '<!-- wp:paragraph {"align":"center"} -->';

		$this->assertTrue( false !== strpos( $content, '<!-- wp:' ) );
		$this->assertTrue( false !== strpos( $content, '-->' ) );
	}

	public function test_html_tag_pattern(): void {
		$html = '<div class="test"><p>Content</p></div>';

		preg_match_all( '/<\/?([a-z]+)[^>]*>/i', $html, $matches );

		$this->assertNotEmpty( $matches );
		$this->assertContains( 'div', $matches[1] );
		$this->assertContains( 'p', $matches[1] );
	}

	public function test_media_file_extensions(): void {
		$extensions = array( '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.mp4', '.mov', '.mp3' );
		$test_url   = 'https://example.com/image.jpg';

		$has_media = false;
		foreach ( $extensions as $ext ) {
			if ( false !== strpos( $test_url, $ext ) ) {
				$has_media = true;
				break;
			}
		}

		$this->assertTrue( $has_media );
	}

	public function test_oembed_url_patterns(): void {
		$youtube_url = 'https://www.youtube.com/watch?v=abc123';
		$vimeo_url   = 'https://vimeo.com/123456789';
		$twitter_url = 'https://twitter.com/user/status/123456';

		$this->assertTrue( false !== strpos( $youtube_url, 'youtube.com' ) );
		$this->assertTrue( false !== strpos( $vimeo_url, 'vimeo.com' ) );
		$this->assertTrue( false !== strpos( $twitter_url, 'twitter.com' ) );
	}

	public function test_relative_url_detection(): void {
		$absolute_url      = 'https://example.com/path/to/file.jpg';
		$relative_url      = '/path/to/file.jpg';
		$protocol_relative = '//cdn.example.com/file.jpg';

		$this->assertTrue( filter_var( $absolute_url, FILTER_VALIDATE_URL ) !== false );
		$this->assertFalse( filter_var( $relative_url, FILTER_VALIDATE_URL ) !== false );
		$this->assertFalse( filter_var( $protocol_relative, FILTER_VALIDATE_URL ) !== false );
	}

	public function test_html_entity_handling(): void {
		$encoded = htmlspecialchars( '<script>alert("test")</script>', ENT_QUOTES, 'UTF-8' );

		$this->assertStringContainsString( '&lt;', $encoded );
		$this->assertStringContainsString( '&gt;', $encoded );
		$this->assertStringNotContainsString( '<script>', $encoded );
	}
}
