<?php
/**
 * Media element matching tests for Content_Media_Processor
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\External_Posts_API;

/**
 * Tests element matching behavior of Content_Media_Processor.
 *
 * Verifies that the processor correctly identifies URLs in recognized element
 * attributes (img, video, audio, source, a, embed, object) and leaves URLs in
 * all other contexts untouched. Links are only processed when the href points
 * to a file with an uploadable extension.
 */
class Media_Processor_Matching_Test extends External_Posts_API_Test_Base {

	/**
	 * Verifies that the processor finds and replaces a media URL in a
	 * recognized element/attribute combination.
	 *
	 * @dataProvider provider_should_replace
	 *
	 * @param string $content     HTML with a source-domain URL.
	 * @param string $description Human-readable case description.
	 */
	public function test_replaces_url_in_media_attributes(
		string $content,
		string $description
	): void {
		// ARRANGE: Record attachment count before processing.
		$source_site        = 'https://example.com';
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content through the media processor.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: The source-domain URL was replaced.
		$this->assertStringNotContainsString(
			'example.com/',
			$result,
			"Source URL should be replaced for: {$description}"
		);

		// ASSERT: At least one attachment was created.
		$this->assertGreaterThan(
			$attachments_before,
			$this->get_attachment_count(),
			"Attachment should be created for: {$description}"
		);
	}

	/**
	 * Data provider: content where the media URL should be found and replaced.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provider_should_replace(): array {
		$url = 'https://example.com/photo.jpg';

		return array(
			// -- img src attribute variations --
			'img_double_quotes'       => array(
				'<img src="' . $url . '">',
				'img src with double quotes',
			),
			'img_single_quotes'       => array(
				"<img src='" . $url . "'>",
				'img src with single quotes',
			),
			'img_uppercase_tag'       => array(
				'<IMG SRC="' . $url . '">',
				'uppercase IMG and SRC',
			),
			'img_mixed_case'          => array(
				'<Img Src="' . $url . '">',
				'mixed-case tag and attribute',
			),
			'img_attrs_before_src'    => array(
				'<img class="photo" alt="A" src="'
					. $url . '">',
				'attributes before src',
			),
			'img_attrs_after_src'     => array(
				'<img src="' . $url
					. '" class="photo" alt="A">',
				'attributes after src',
			),
			'img_self_closing'        => array(
				'<img src="' . $url . '"/>',
				'self-closing img (no space)',
			),
			'img_self_closing_space'  => array(
				'<img src="' . $url . '" />',
				'self-closing img (with space)',
			),
			'img_newline_before_src'  => array(
				"<img\nsrc=\"" . $url . '">',
				'newline before src',
			),
			'img_tab_before_src'      => array(
				"<img\tsrc=\"" . $url . '">',
				'tab before src',
			),
			'img_multiple_spaces'     => array(
				'<img  src="' . $url . '">',
				'multiple spaces before src',
			),
			'img_crlf_before_src'     => array(
				"<img\r\nsrc=\"" . $url . '">',
				'CRLF before src',
			),
			'img_mixed_whitespace'    => array(
				"<img \n\t src=\"" . $url . '">',
				'mixed space, newline, tab before src',
			),
			'img_data_attr_before'    => array(
				'<img data-custom="v" src="' . $url . '">',
				'data-* attribute before src',
			),
			'img_many_attrs'          => array(
				'<img class="x" alt="y" width="100"'
					. ' src="' . $url . '" height="50">',
				'src surrounded by many attributes',
			),
			'img_multiline_tag'       => array(
				"<img\n  class=\"photo\"\n  src=\""
					. $url . "\"\n  alt=\"test\"\n>",
				'attributes on separate lines',
			),
			'img_boolean_attr_before' => array(
				'<img hidden src="' . $url . '">',
				'boolean attribute before src',
			),
			'img_spaces_around_eq'    => array(
				'<img src = "' . $url . '">',
				'spaces around = sign',
			),
			'img_space_before_eq'     => array(
				'<img src ="' . $url . '">',
				'space before = sign only',
			),
			'img_space_after_eq'      => array(
				'<img src= "' . $url . '">',
				'space after = sign only',
			),
			'img_unquoted_src'        => array(
				'<img src=' . $url . '>',
				'unquoted src value',
			),
			'img_gt_in_prior_attr'    => array(
				'<img alt="a > b" src="' . $url . '">',
				'> inside preceding attribute value',
			),
			'img_gt_single_q_prior'   => array(
				"<img alt='a > b' src=\"" . $url . '">',
				'> in single-quoted preceding attr',
			),

			// -- video/audio/source src --
			'video_src'               => array(
				'<video src="' . $url . '" controls></video>',
				'src on video element',
			),
			'audio_src'               => array(
				'<audio src="' . $url . '" controls></audio>',
				'src on audio element',
			),
			'source_src_in_video'     => array(
				'<video><source src="' . $url
					. '" type="image/jpeg"></video>',
				'source src inside video',
			),
			'source_src_in_audio'     => array(
				'<audio><source src="' . $url
					. '" type="audio/mpeg"></audio>',
				'source src inside audio',
			),

			// -- video poster --
			'video_poster'            => array(
				'<video poster="' . $url
					. '" controls></video>',
				'poster on video element',
			),
			'video_poster_after_src'  => array(
				'<video src="/local.mp4" poster="'
					. $url . '"></video>',
				'poster after src on video',
			),

			// -- srcset --
			'img_srcset_single'       => array(
				'<img srcset="' . $url . ' 300w">',
				'img srcset single descriptor',
			),
			'img_srcset_multiple'     => array(
				'<img srcset="'
					. 'https://example.com/small.jpg 300w, '
					. 'https://example.com/large.jpg 600w">',
				'img srcset multiple descriptors',
			),
			'source_srcset'           => array(
				'<picture><source srcset="' . $url
					. '" type="image/jpeg">'
					. '<img src="/fallback.jpg"></picture>',
				'source srcset inside picture',
			),

			// -- file download links --
			'a_href_file_url'         => array(
				'<a href="' . $url . '">link</a>',
				'href on anchor to file URL',
			),

			// -- embed/object --
			'embed_src'               => array(
				'<embed src="' . $url . '">',
				'src on embed element',
			),
			'object_data'             => array(
				'<object data="' . $url
					. '"></object>',
				'data on object element',
			),
		);
	}

	/**
	 * Verifies that a URL is NOT touched when it appears outside recognized
	 * media element attributes.
	 *
	 * @dataProvider provider_should_not_replace
	 *
	 * @param string $content     HTML with a URL in a non-matching context.
	 * @param string $description Human-readable case description.
	 */
	public function test_ignores_url_outside_media_attributes(
		string $content,
		string $description
	): void {
		// ARRANGE: Reset tracking and record attachment count.
		$source_site = 'https://example.com';
		$this->content_media_processor->reset_failed_media();
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Content is byte-for-byte identical.
		$this->assertSame(
			$content,
			$result,
			"Content must be unchanged for: {$description}"
		);

		// ASSERT: No attachment was created.
		$this->assert_no_new_attachments(
			$attachments_before,
			"No attachment for: {$description}"
		);

		// ASSERT: No download was attempted.
		$this->assertSame(
			array(),
			$this->content_media_processor->get_failed_media(),
			"No failed media for: {$description}"
		);
	}

	/**
	 * Data provider: content where the URL must NOT be touched.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provider_should_not_replace(): array {
		$url = 'https://example.com/photo.jpg';

		return array(
			// -- data-* attribute look-alikes --
			'img_data_src'       => array(
				'<img data-src="' . $url . '">',
				'data-src must not match as src',
			),
			'img_data_srcset'    => array(
				'<img data-srcset="' . $url . ' 300w">',
				'data-srcset must not match as srcset',
			),
			'source_data_srcset' => array(
				'<source data-srcset="' . $url . ' 300w">',
				'data-srcset on source element',
			),

			// -- other prefixed look-alikes --
			'img_nosrc'          => array(
				'<img nosrc="' . $url . '">',
				'nosrc must not match as src',
			),

			// -- non-media elements with src --
			'div_src'            => array(
				'<div src="' . $url . '">text</div>',
				'src on div',
			),
			'span_src'           => array(
				'<span src="' . $url . '">text</span>',
				'src on span',
			),
			'anchor_src'         => array(
				'<a src="' . $url . '">link</a>',
				'src on anchor',
			),
			'script_src'         => array(
				'<script src="' . $url . '"></script>',
				'src on script',
			),
			'iframe_src'         => array(
				'<iframe src="' . $url . '"></iframe>',
				'src on iframe',
			),
			'input_src'          => array(
				'<input type="image" src="' . $url . '">',
				'src on input[type=image]',
			),
			'custom_element_src' => array(
				'<my-player src="' . $url
					. '"></my-player>',
				'src on custom element',
			),

			// -- poster on non-video elements --
			'img_poster'         => array(
				'<img poster="' . $url . '">',
				'poster on img (not video)',
			),
			'audio_poster'       => array(
				'<audio poster="' . $url . '"></audio>',
				'poster on audio (not video)',
			),
			'div_poster'         => array(
				'<div poster="' . $url . '">text</div>',
				'poster on div',
			),

			// -- srcset on non-media elements --
			'div_srcset'         => array(
				'<div srcset="' . $url . ' 300w">text</div>',
				'srcset on div',
			),
			'video_srcset'       => array(
				'<video srcset="' . $url
					. ' 300w"></video>',
				'srcset on video (not img/source)',
			),

			// -- URL outside any tag --
			'url_in_text'        => array(
				'<p>Visit ' . $url . ' for info</p>',
				'URL in paragraph text',
			),
			'url_in_data_attr'   => array(
				'<div data-bg="' . $url . '">text</div>',
				'URL in generic data attribute',
			),

			// -- hrefs to pages (no file extension) --
			'a_href_page_slug'   => array(
				'<a href="https://example.com/about/">'
					. 'About</a>',
				'href to page slug (no extension)',
			),
			'a_href_page_query'  => array(
				'<a href="https://example.com/?p=123">'
					. 'Post</a>',
				'href to page query string',
			),

			// -- data-* look-alike on object --
			'object_data_attr'   => array(
				'<object data-src="' . $url
					. '"></object>',
				'data-src must not match as data',
			),
		);
	}

	/**
	 * Verifies that when > appears inside a preceding attribute value, the
	 * surrounding attributes and content are preserved exactly while the media
	 * URL is still replaced.
	 */
	public function test_gt_in_attribute_preserves_surrounding_markup(): void {
		// ARRANGE: img with > in alt text before src.
		$source_site = 'https://example.com';
		$url         = 'https://example.com/photo.jpg';
		$content     = '<img alt="A > B" class="hero" src="'
			. $url . '" width="100">';

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Media URL was replaced.
		$this->assertStringNotContainsString(
			'example.com/',
			$result,
			'Source URL should be replaced'
		);

		// ASSERT: Alt text with > is preserved exactly.
		$this->assertStringContainsString(
			'alt="A > B"',
			$result,
			'Alt text containing > must be preserved'
		);

		// ASSERT: Surrounding attributes are preserved.
		$this->assertStringContainsString(
			'class="hero"',
			$result,
			'class attribute must be preserved'
		);
		$this->assertStringContainsString(
			'width="100"',
			$result,
			'width attribute must be preserved'
		);
	}

	/**
	 * Verifies that a media URL inside an HTML comment is not imported and the
	 * comment content is preserved exactly.
	 */
	public function test_url_inside_html_comment_not_imported(): void {
		// ARRANGE: An img tag inside an HTML comment.
		$source_site = 'https://example.com';
		$url         = 'https://example.com/photo.jpg';
		$content     = '<!-- <img src="' . $url . '"> -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Content is unchanged — comment preserved.
		$this->assertSame(
			$content,
			$result,
			'Comment content must not be modified'
		);

		// ASSERT: No attachment was created.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Media inside comments must not be imported'
		);
	}

	/**
	 * Verifies that a real media URL is imported while a commented-out one in
	 * the same content is left alone.
	 */
	public function test_comment_preserved_alongside_real_media(): void {
		// ARRANGE: A real img and a commented-out img.
		$source_site = 'https://example.com';
		$content     = '<!-- <img src="https://example.com/old.jpg"> -->'
			. '<img src="https://example.com/real.jpg">';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Real img was imported.
		$this->assertStringNotContainsString(
			'example.com/real.jpg',
			$result,
			'Real media URL should be replaced'
		);
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Exactly one attachment for the real img'
		);

		// ASSERT: Commented-out img is unchanged.
		$this->assertStringContainsString(
			'<!-- <img src="https://example.com/old.jpg"> -->',
			$result,
			'Commented-out img must be preserved exactly'
		);
	}

	/**
	 * Verifies that a source-domain media URL in malformed HTML (unclosed
	 * quote) is recorded as a failure so the caller can surface a warning.
	 */
	public function test_malformed_html_records_missed_url_as_failure(): void {
		// ARRANGE: Img with unclosed quote — regex cannot match.
		$source_site = 'https://example.com';
		$url         = 'https://example.com/photo.jpg';
		$content     = '<img src="' . $url;

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Content is unchanged (regex couldn't match).
		$this->assertSame(
			$content,
			$result,
			'Malformed HTML should pass through unchanged'
		);

		// ASSERT: No attachment was created.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Malformed HTML should not create attachments'
		);

		// ASSERT: The URL is recorded as unprocessable, not as a download
		// failure.
		$this->assertContains(
			$url,
			$this->content_media_processor->get_unprocessable_media(),
			'Missed URL should be in unprocessable_media'
		);
		$this->assertNotContains(
			$url,
			$this->content_media_processor->get_failed_media(),
			'Missed URL must not be in failed_media'
		);
	}

	/**
	 * Verifies that a media URL inside a script tag is not imported and the
	 * script content is preserved exactly.
	 */
	public function test_url_inside_script_not_imported(): void {
		// ARRANGE: An img template string inside a script.
		$source_site = 'https://example.com';
		$url         = 'https://example.com/photo.jpg';
		$content     = '<script type="text/javascript">'
			. 'var tpl = \'<img src="' . $url . '">\';</script>';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Content is unchanged.
		$this->assertSame(
			$content,
			$result,
			'Script content must not be modified'
		);

		// ASSERT: No attachment was created.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Media inside scripts must not be imported'
		);
	}

	/**
	 * Verifies that a media URL inside a style tag is not imported.
	 */
	public function test_url_inside_style_not_imported(): void {
		// ARRANGE: A background-image URL inside a style tag.
		$source_site = 'https://example.com';
		$content     = '<style>.hero { background-image:'
			. ' url("https://example.com/bg.jpg"); }'
			. '</style>';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$result = $this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: Content is unchanged.
		$this->assertSame(
			$content,
			$result,
			'Style content must not be modified'
		);

		// ASSERT: No attachment was created.
		$this->assert_no_new_attachments(
			$attachments_before,
			'URLs inside style tags must not be imported'
		);
	}

	/**
	 * Verifies that the missed-URL detection does not false-positive on
	 * source-domain media URLs that appear in non-media contexts (links, CSS,
	 * text).
	 *
	 * @dataProvider provider_detection_no_false_positive
	 *
	 * @param string $content     Content with a source-domain URL in a
	 *                            non-media context.
	 * @param string $description Human-readable case description.
	 */
	public function test_detection_no_false_positive(
		string $content,
		string $description
	): void {
		// ARRANGE: Reset tracking arrays.
		$source_site = 'https://example.com';
		$this->content_media_processor->reset_failed_media();
		$this->content_media_processor->reset_unprocessable_media();

		// ACT: Process content.
		$this->content_media_processor->process_content(
			$content,
			$source_site
		);

		// ASSERT: No false failure or detection recorded.
		$this->assertSame(
			array(),
			$this->content_media_processor->get_failed_media(),
			"No false failure for: {$description}"
		);
		$this->assertSame(
			array(),
			$this->content_media_processor->get_unprocessable_media(),
			"No false detection for: {$description}"
		);
	}

	/**
	 * Data provider: content with source-domain media URLs in non-media
	 * contexts that must NOT trigger a detection false positive.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provider_detection_no_false_positive(): array {
		$url = 'https://example.com/photo.jpg';

		return array(
			'url_in_link_href' => array(
				'<a href="' . $url . '">download</a>',
				'media URL in link href',
			),
			'url_in_css_bg'    => array(
				'<div style="background-image:'
					. ' url(\'' . $url . '\')">text</div>',
				'media URL in inline CSS',
			),
			'url_in_text'      => array(
				'<p>See ' . $url . ' for details</p>',
				'media URL in paragraph text',
			),
			'url_in_data_attr' => array(
				'<div data-src="' . $url . '">text</div>',
				'media URL in data attribute',
			),
			'url_in_script'    => array(
				'<script>var img = "' . $url . '";</script>',
				'media URL in script (stripped)',
			),
			'url_in_comment'   => array(
				'<!-- ' . $url . ' -->',
				'media URL in comment (stripped)',
			),
		);
	}
}
