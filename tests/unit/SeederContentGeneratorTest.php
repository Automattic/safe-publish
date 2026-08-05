<?php
/**
 * Seeder Content Generator Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Safe_Publish\Seeder\Content_Generator;

/**
 * Tests the Seeder Content_Generator pure logic.
 */
class SeederContentGeneratorTest extends TestCase {

	/**
	 * Fixed Unix timestamp used as "now" in tests: 2025-01-01 00:00:00 UTC.
	 */
	private const REFERENCE_TIME = 1735689600;

	/**
	 * Builds a generator with sensible defaults so tests stay focused.
	 *
	 * @param string $editor      Editor mode.
	 * @param string $images_mode Image mode.
	 * @param int    $count       Batch count.
	 * @param int    $start       Batch start index.
	 * @param int    $date_offset Additional days into the past.
	 * @param string $prefix      Title prefix.
	 * @param string $type        Post type slug.
	 * @return Content_Generator Configured generator.
	 */
	private function build_generator(
		string $editor = 'gutenberg',
		string $images_mode = '1',
		int $count = 10,
		int $start = 1,
		int $date_offset = 0,
		string $prefix = '',
		string $type = 'post'
	): Content_Generator {
		return new Content_Generator(
			$type,
			$editor,
			$images_mode,
			$count,
			$start,
			$date_offset,
			$prefix,
			self::REFERENCE_TIME,
			'https://source.example.com'
		);
	}

	/**
	 * Verifies that the constructor rejects invalid editor values.
	 */
	public function test_constructor_throws_on_invalid_editor(): void {
		// ARRANGE: An unsupported editor value.
		$this->expectException( InvalidArgumentException::class );

		// ACT + ASSERT: Construction throws.
		$this->build_generator( editor: 'invalid' );
	}

	/**
	 * Verifies that the constructor rejects invalid image mode values.
	 */
	public function test_constructor_throws_on_invalid_images_mode(): void {
		// ARRANGE: An unsupported image mode.
		$this->expectException( InvalidArgumentException::class );

		// ACT + ASSERT: Construction throws.
		$this->build_generator( images_mode: 'invalid' );
	}

	/**
	 * Verifies that resolve_editor returns true for every index when
	 * configured as gutenberg.
	 */
	public function test_resolve_editor_returns_true_for_gutenberg(): void {
		// ARRANGE: Generator in gutenberg mode.
		$generator = $this->build_generator( editor: 'gutenberg' );

		// ACT + ASSERT: Every index uses the block editor.
		$this->assertTrue( $generator->resolve_editor( 1 ) );
		$this->assertTrue( $generator->resolve_editor( 5 ) );
		$this->assertTrue( $generator->resolve_editor( 100 ) );
	}

	/**
	 * Verifies that resolve_editor returns false for every index when
	 * configured as classic.
	 */
	public function test_resolve_editor_returns_false_for_classic(): void {
		// ARRANGE: Generator in classic mode.
		$generator = $this->build_generator( editor: 'classic' );

		// ACT + ASSERT: Every index uses the classic editor.
		$this->assertFalse( $generator->resolve_editor( 1 ) );
		$this->assertFalse( $generator->resolve_editor( 5 ) );
		$this->assertFalse( $generator->resolve_editor( 100 ) );
	}

	/**
	 * Verifies that resolve_editor rotates so two out of three posts use
	 * the block editor in mixed mode.
	 */
	public function test_resolve_editor_rotates_in_mixed_mode(): void {
		// ARRANGE: Generator in mixed mode.
		$generator = $this->build_generator( editor: 'mixed' );

		// ACT + ASSERT: Indices divisible by 3 fall back to classic.
		$this->assertTrue( $generator->resolve_editor( 1 ) );
		$this->assertTrue( $generator->resolve_editor( 2 ) );
		$this->assertFalse( $generator->resolve_editor( 3 ) );
		$this->assertTrue( $generator->resolve_editor( 4 ) );
		$this->assertTrue( $generator->resolve_editor( 5 ) );
		$this->assertFalse( $generator->resolve_editor( 6 ) );
	}

	/**
	 * Verifies that resolve_image_mode returns the configured mode unchanged
	 * when it is concrete (not 'auto').
	 *
	 * @dataProvider concrete_image_modes_provider
	 *
	 * @param string $mode Configured concrete image mode.
	 */
	public function test_resolve_image_mode_passes_through_concrete_modes(
		string $mode
	): void {
		// ARRANGE: Generator configured with a concrete mode.
		$generator = $this->build_generator( images_mode: $mode );

		// ACT + ASSERT: The same mode is returned regardless of index.
		$this->assertSame( $mode, $generator->resolve_image_mode( 1 ) );
		$this->assertSame( $mode, $generator->resolve_image_mode( 7 ) );
	}

	/**
	 * Data provider for concrete image modes.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function concrete_image_modes_provider(): array {
		return array(
			'one'     => array( '1' ),
			'two'     => array( '2' ),
			'resized' => array( '2-resized' ),
		);
	}

	/**
	 * Verifies that resolve_image_mode cycles through 1, 2, 2-resized in
	 * auto mode starting from index 1.
	 */
	public function test_resolve_image_mode_rotates_in_auto_mode(): void {
		// ARRANGE: Generator in auto mode.
		$generator = $this->build_generator( images_mode: 'auto' );

		// ACT + ASSERT: The rotation cycles every three indices.
		$this->assertSame( '1', $generator->resolve_image_mode( 1 ) );
		$this->assertSame( '2', $generator->resolve_image_mode( 2 ) );
		$this->assertSame( '2-resized', $generator->resolve_image_mode( 3 ) );
		$this->assertSame( '1', $generator->resolve_image_mode( 4 ) );
		$this->assertSame( '2', $generator->resolve_image_mode( 5 ) );
		$this->assertSame( '2-resized', $generator->resolve_image_mode( 6 ) );
	}

	/**
	 * Verifies that resolve_status returns publish for indices that aren't
	 * multiples of 5 or 6.
	 */
	public function test_resolve_status_publish_by_default(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT + ASSERT: Non-rotating indices stay on publish.
		$this->assertSame( 'publish', $generator->resolve_status( 1 ) );
		$this->assertSame( 'publish', $generator->resolve_status( 2 ) );
		$this->assertSame( 'publish', $generator->resolve_status( 7 ) );
	}

	/**
	 * Verifies that resolve_status returns draft every 5th index when not
	 * also divisible by 6.
	 */
	public function test_resolve_status_draft_every_fifth(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT + ASSERT: Indices 5, 10, 25 land on draft.
		$this->assertSame( 'draft', $generator->resolve_status( 5 ) );
		$this->assertSame( 'draft', $generator->resolve_status( 10 ) );
		$this->assertSame( 'draft', $generator->resolve_status( 25 ) );
	}

	/**
	 * Verifies that resolve_status returns private every 6th index, which
	 * takes priority over the draft rotation.
	 */
	public function test_resolve_status_private_every_sixth(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT + ASSERT: 6 and 12 are private; 30 (also divisible by 5) wins
		// private because the check runs first.
		$this->assertSame( 'private', $generator->resolve_status( 6 ) );
		$this->assertSame( 'private', $generator->resolve_status( 12 ) );
		$this->assertSame( 'private', $generator->resolve_status( 30 ) );
	}

	/**
	 * Verifies that resolve_date produces deterministic UTC dates when given
	 * a fixed reference time.
	 */
	public function test_resolve_date_is_deterministic(): void {
		// ARRANGE: A batch of 10 starting at index 1.
		$generator = $this->build_generator(
			count: 10,
			start: 1,
		);

		// ACT: Derive the date for the first (oldest) index.
		$date_first = $generator->resolve_date( 1 );

		// ACT: Derive the date for the last (newest) index.
		$date_last = $generator->resolve_date( 10 );

		// ASSERT: Oldest is 81 days back (round( 9 * 90 / 10 )) from the
		// 2025-01-01 reference time, newest is the reference itself.
		$this->assertSame( '2024-10-12 00:00:00', $date_first );
		$this->assertSame( '2025-01-01 00:00:00', $date_last );
	}

	/**
	 * Verifies that resolve_date shifts the entire batch further into the
	 * past when date_offset is set.
	 */
	public function test_resolve_date_applies_offset(): void {
		// ARRANGE: Same batch, with a 30-day offset.
		$generator = $this->build_generator(
			count: 10,
			start: 1,
			date_offset: 30,
		);

		// ACT: Derive the date for the newest index.
		$date_last = $generator->resolve_date( 10 );

		// ASSERT: Newest is 30 days back from the reference time.
		$this->assertSame( '2024-12-02 00:00:00', $date_last );
	}

	/**
	 * Verifies that image_label encodes both the image count and the
	 * resized-mode flag.
	 *
	 * @dataProvider image_label_provider
	 *
	 * @param string $mode      Resolved image mode.
	 * @param int    $img_count Number of images.
	 * @param string $expected  Expected label.
	 */
	public function test_image_label(
		string $mode,
		int $img_count,
		string $expected
	): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT: Compute the label.
		$label = $generator->image_label( $mode, $img_count );

		// ASSERT: Matches the expected encoding.
		$this->assertSame( $expected, $label );
	}

	/**
	 * Data provider for image_label.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function image_label_provider(): array {
		return array(
			'one image'          => array( '1', 1, '1P' ),
			'two images'         => array( '2', 2, '2P' ),
			'two resized images' => array( '2-resized', 2, '2PR' ),
			'no images one mode' => array( '1', 0, '0P' ),
			'no images resized'  => array( '2-resized', 0, '0PR' ),
		);
	}

	/**
	 * Verifies that the title for a gutenberg post omits the classic marker
	 * and contains the type, index, and image label.
	 */
	public function test_title_for_gutenberg(): void {
		// ARRANGE: A gutenberg generator.
		$generator = $this->build_generator( editor: 'gutenberg' );

		// ACT: Build the title for index 1 with one image.
		$title = $generator->title( 1, true, '1', 1 );

		// ASSERT: Format matches the seeder's historical output.
		$this->assertSame( 'Post 1 - 1P', $title );
	}

	/**
	 * Verifies that the title for a classic post includes the " C" marker.
	 */
	public function test_title_for_classic_includes_c_marker(): void {
		// ARRANGE: Any generator (mode doesn't affect title rendering).
		$generator = $this->build_generator();

		// ACT: Build the title with use_gutenberg=false.
		$title = $generator->title( 7, false, '2-resized', 2 );

		// ASSERT: Marker is present and resized images yield 2PR.
		$this->assertSame( 'Post 7 C - 2PR', $title );
	}

	/**
	 * Verifies that the configured prefix is prepended with a separating
	 * space.
	 */
	public function test_title_includes_prefix_with_space(): void {
		// ARRANGE: Generator with a non-empty prefix.
		$generator = $this->build_generator( prefix: 'Run2' );

		// ACT: Build the title for index 1.
		$title = $generator->title( 1, true, '1', 1 );

		// ASSERT: Prefix is followed by a single space then the rest.
		$this->assertSame( 'Run2 Post 1 - 1P', $title );
	}

	/**
	 * Verifies that the slug encodes type and index in the seeder's format.
	 */
	public function test_slug_format(): void {
		// ARRANGE: A page generator.
		$generator = $this->build_generator( type: 'page' );

		// ACT: Build the slug for index 3.
		$slug = $generator->slug( 3 );

		// ASSERT: Slug uses the seeder-{type}-{index} convention.
		$this->assertSame( 'seeder-page-3', $slug );
	}

	/**
	 * Verifies that the excerpt encodes type and index.
	 */
	public function test_excerpt_format(): void {
		// ARRANGE: A post generator.
		$generator = $this->build_generator();

		// ACT: Build the excerpt for index 12.
		$excerpt = $generator->excerpt( 12 );

		// ASSERT: Standard sentence form.
		$this->assertSame(
			'Excerpt for seeded post number 12.',
			$excerpt
		);
	}

	/**
	 * Verifies that gutenberg content includes the heading, paragraphs, and
	 * list blocks even when no images are provided.
	 */
	public function test_gutenberg_content_with_no_images(): void {
		// ARRANGE: Any generator and an empty image_refs.
		$generator = $this->build_generator();

		// ACT: Build content with no image references.
		$content = $generator->gutenberg_content( 1, array() );

		// ASSERT: Structural block comments are present.
		$this->assertStringContainsString( '<!-- wp:heading', $content );
		$this->assertStringContainsString( '<!-- wp:paragraph', $content );
		$this->assertStringContainsString( '<!-- wp:list', $content );

		// ASSERT: No image block was rendered.
		$this->assertStringNotContainsString( '<!-- wp:image', $content );
	}

	/**
	 * Verifies that gutenberg content embeds an image block per image_ref,
	 * referencing the caller-provided id and url verbatim and the fixed
	 * non-empty alt.
	 */
	public function test_gutenberg_content_embeds_image_blocks(): void {
		// ARRANGE: Two image references.
		$generator = $this->build_generator();
		$refs      = array(
			array(
				'id'  => 100,
				'url' => 'https://source.example.com/a.jpg',
			),
			array(
				'id'  => 101,
				'url' => 'https://source.example.com/b.jpg',
			),
		);

		// ACT: Build content with both images.
		$content = $generator->gutenberg_content( 5, $refs );

		// ASSERT: Each image's id and url appear in expected block syntax.
		$this->assertStringContainsString( '"id":100', $content );
		$this->assertStringContainsString( '"id":101', $content );
		$this->assertStringContainsString(
			'src="https://source.example.com/a.jpg"',
			$content
		);
		$this->assertStringContainsString(
			'class="wp-image-100"',
			$content
		);
		$this->assertStringContainsString(
			'alt="' . Content_Generator::BLOCK_IMAGE_ALT . '"',
			$content
		);
	}

	/**
	 * Verifies that classic content wraps the first image in a [caption]
	 * shortcode and leaves subsequent images inline.
	 */
	public function test_classic_content_caption_vs_inline(): void {
		// ARRANGE: Two image references.
		$generator = $this->build_generator();
		$refs      = array(
			array(
				'id'  => 200,
				'url' => 'https://source.example.com/a.jpg',
			),
			array(
				'id'  => 201,
				'url' => 'https://source.example.com/b.jpg',
			),
		);

		// ACT: Build content with both images.
		$content = $generator->classic_content( 3, $refs );

		// ASSERT: First image is wrapped in a [caption] shortcode bound to
		// its attachment id.
		$this->assertStringContainsString(
			'[caption id="attachment_200" align="aligncenter" width="800"]',
			$content
		);
		$this->assertStringContainsString(
			'Caption for seeded image 3.[/caption]',
			$content
		);

		// ASSERT: Second image appears inline (no shortcode wrap).
		$this->assertStringContainsString(
			'<p><img src="https://source.example.com/b.jpg"',
			$content
		);
	}

	/**
	 * Verifies that meta_values include the seeder tag, a rotating color,
	 * and a rotating priority within the documented range.
	 */
	public function test_meta_values_structure(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT: Collect meta values for a sample index.
		$meta = $generator->meta_values( 3 );

		// ASSERT: Tag key uses the published constant.
		$this->assertSame(
			'1',
			$meta[ Content_Generator::SEEDER_META_KEY ]
		);

		// ASSERT: Color rotates through the documented palette.
		$this->assertSame( 'yellow', $meta['seeder_color'] );

		// ASSERT: Priority stays within 1..10.
		$this->assertSame( 4, $meta['seeder_priority'] );
	}

	/**
	 * Verifies that meta_values cycles seeder_color across the palette and
	 * seeder_priority across 1..10.
	 */
	public function test_meta_values_rotate(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT + ASSERT: Colors cycle every four indices.
		$this->assertSame( 'green', $generator->meta_values( 1 )['seeder_color'] );
		$this->assertSame( 'blue', $generator->meta_values( 2 )['seeder_color'] );
		$this->assertSame( 'yellow', $generator->meta_values( 3 )['seeder_color'] );
		$this->assertSame( 'red', $generator->meta_values( 4 )['seeder_color'] );
		$this->assertSame( 'green', $generator->meta_values( 5 )['seeder_color'] );

		// ACT + ASSERT: Priority cycles every ten indices, offset by 1.
		$this->assertSame( 2, $generator->meta_values( 1 )['seeder_priority'] );
		$this->assertSame( 10, $generator->meta_values( 9 )['seeder_priority'] );
		$this->assertSame( 1, $generator->meta_values( 10 )['seeder_priority'] );
	}

	/**
	 * Verifies that term_assignments returns two terms per taxonomy and
	 * uses the published names/slugs from term_config().
	 */
	public function test_term_assignments_returns_two_per_taxonomy(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT: Collect term assignments for index 1.
		$terms = $generator->term_assignments( 1 );

		// ASSERT: Both seeded taxonomies are present with exactly two terms.
		$this->assertCount( 2, $terms['category'] );
		$this->assertCount( 2, $terms['post_tag'] );

		// ASSERT: Values match the term_config() entries (category by name,
		// post_tag by slug).
		$this->assertSame(
			array( 'Seeder Category B', 'Seeder Category C' ),
			$terms['category']
		);
		$this->assertSame(
			array( 'seeder-beta', 'seeder-gamma' ),
			$terms['post_tag']
		);
	}

	/**
	 * Verifies that term_config exposes the canonical seeder taxonomies and
	 * lookup fields.
	 */
	public function test_term_config_shape(): void {
		// ARRANGE + ACT: Read the static config.
		$config = Content_Generator::term_config();

		// ASSERT: Categories look up by name; tags look up by slug.
		$this->assertSame( 'name', $config['category']['field'] );
		$this->assertSame( 'slug', $config['post_tag']['field'] );

		// ASSERT: Published term counts match documented values.
		$this->assertCount( 3, $config['category']['terms'] );
		$this->assertCount( 4, $config['post_tag']['terms'] );
	}

	/**
	 * Verifies that generate() returns a payload covering all required keys
	 * and the featured_media is the first image's id.
	 */
	public function test_generate_returns_complete_payload(): void {
		// ARRANGE: A gutenberg generator and one image.
		$generator = $this->build_generator( editor: 'gutenberg' );
		$refs      = array(
			array(
				'id'  => 42,
				'url' => 'https://source.example.com/a.jpg',
			),
		);

		// ACT: Generate the payload for index 1.
		$payload = $generator->generate( 1, $refs );

		// ASSERT: Keys match the documented payload structure.
		$this->assertSame( 'Post 1 - 1P', $payload['title'] );
		$this->assertSame( 'seeder-post-1', $payload['slug'] );
		$this->assertSame(
			'https://source.example.com/seeder-post-1',
			$payload['link']
		);
		$this->assertSame( 'post', $payload['post_type'] );
		$this->assertSame( 'publish', $payload['status'] );
		$this->assertSame( 42, $payload['featured_media'] );
		$this->assertStringContainsString(
			'<!-- wp:heading',
			$payload['content']
		);
	}

	/**
	 * Verifies that generate() produces classic-editor content when the
	 * resolved editor for that index is classic.
	 */
	public function test_generate_uses_classic_content_for_classic_editor(): void {
		// ARRANGE: Classic editor generator.
		$generator = $this->build_generator( editor: 'classic' );

		// ACT: Generate the payload for index 1 with no images.
		$payload = $generator->generate( 1, array() );

		// ASSERT: Classic content has no block comments.
		$this->assertStringNotContainsString(
			'<!-- wp:',
			$payload['content']
		);

		// ASSERT: Title carries the classic marker.
		$this->assertStringContainsString( ' C - ', $payload['title'] );
	}

	/**
	 * Verifies that generate() sets featured_media to 0 when no image_refs
	 * are provided.
	 */
	public function test_generate_featured_media_zero_with_no_images(): void {
		// ARRANGE: Any generator, empty image_refs.
		$generator = $this->build_generator();

		// ACT: Generate the payload.
		$payload = $generator->generate( 1, array() );

		// ASSERT: featured_media defaults to 0.
		$this->assertSame( 0, $payload['featured_media'] );
	}

	/**
	 * Verifies that a non-zero revision shifts the status rotation so a
	 * post that's publish at rev 0 lands on a different status at rev 1.
	 */
	public function test_resolve_status_shifts_with_revision(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT + ASSERT: Index 4 is publish at rev 0 but draft at rev 1
		// because (4 + 1) is divisible by 5.
		$this->assertSame( 'publish', $generator->resolve_status( 4 ) );
		$this->assertSame( 'draft', $generator->resolve_status( 4, 1 ) );

		// ACT + ASSERT: Index 5 is draft at rev 0 but private at rev 1
		// because (5 + 1) is divisible by 6.
		$this->assertSame( 'draft', $generator->resolve_status( 5 ) );
		$this->assertSame( 'private', $generator->resolve_status( 5, 1 ) );
	}

	/**
	 * Verifies that meta_values records the revision number and rotates
	 * color/priority by (index + revision) when revision is positive.
	 */
	public function test_meta_values_with_revision(): void {
		// ARRANGE: Any generator, plus the no-revision baseline at the
		// matching post-revision index (5 = 3 + 2).
		$generator = $this->build_generator();
		$baseline  = $generator->meta_values( 5 );

		// ACT: Collect meta for index 3 at revision 2.
		$meta = $generator->meta_values( 3, 2 );

		// ASSERT: Revision is recorded as a string.
		$this->assertSame(
			'2',
			$meta[ Content_Generator::REVISION_META_KEY ]
		);

		// ASSERT: Color/priority are rotated relative to (index + revision),
		// matching what meta_values( 5, 0 ) returns.
		$this->assertSame( $baseline['seeder_color'], $meta['seeder_color'] );
		$this->assertSame(
			$baseline['seeder_priority'],
			$meta['seeder_priority']
		);
	}

	/**
	 * Verifies that meta_values at revision 0 produces identical output to
	 * the no-revision call, preserving create-mode behavior.
	 */
	public function test_meta_values_zero_revision_matches_no_revision(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT: Build with explicit revision 0 and without.
		$with_zero = $generator->meta_values( 3, 0 );
		$without   = $generator->meta_values( 3 );

		// ASSERT: Identical, and no revision meta key was added.
		$this->assertSame( $without, $with_zero );
		$this->assertArrayNotHasKey(
			Content_Generator::REVISION_META_KEY,
			$with_zero
		);
	}

	/**
	 * Verifies that term_assignments rotates by (index + revision) so a
	 * non-zero revision picks a different pair of terms per taxonomy.
	 */
	public function test_term_assignments_shifts_with_revision(): void {
		// ARRANGE: Any generator.
		$generator = $this->build_generator();

		// ACT: Collect assignments for index 1 at revision 2.
		$at_rev_2 = $generator->term_assignments( 1, 2 );
		$baseline = $generator->term_assignments( 3 );

		// ASSERT: Matches what index 3 (i.e. 1 + 2) would produce at rev 0.
		$this->assertSame( $baseline, $at_rev_2 );
	}

	/**
	 * Verifies that extract_index_from_slug returns the trailing integer
	 * for well-formed seeder slugs.
	 */
	public function test_extract_index_from_slug_valid(): void {
		// ARRANGE + ACT + ASSERT: Standard seeder slugs round-trip.
		$this->assertSame(
			3,
			Content_Generator::extract_index_from_slug( 'seeder-post-3' )
		);
		$this->assertSame(
			12,
			Content_Generator::extract_index_from_slug( 'seeder-page-12' )
		);
		$this->assertSame(
			7,
			Content_Generator::extract_index_from_slug( 'seeder-my-cpt-7' )
		);
	}

	/**
	 * Verifies that extract_index_from_slug returns null for slugs that
	 * don't look like seeder output.
	 */
	public function test_extract_index_from_slug_invalid(): void {
		// ARRANGE + ACT + ASSERT: Missing prefix or trailing number.
		$this->assertNull(
			Content_Generator::extract_index_from_slug( 'hello-world' )
		);
		$this->assertNull(
			Content_Generator::extract_index_from_slug( 'seeder-post' )
		);
		$this->assertNull(
			Content_Generator::extract_index_from_slug( 'seeder-post-abc' )
		);
	}

	/**
	 * Verifies that apply_revision_suffix appends, replaces, and strips the
	 * revision suffix idempotently for titles and excerpts.
	 */
	public function test_apply_revision_suffix(): void {
		// ARRANGE: A title without an existing suffix.
		$title = 'Run2 Post 1 - 1P';

		// ACT + ASSERT: Positive revision appends the suffix.
		$this->assertSame(
			'Run2 Post 1 - 1P (rev 1)',
			Content_Generator::apply_revision_suffix( $title, 1 )
		);

		// ACT + ASSERT: Re-applying replaces an existing suffix instead of
		// stacking it.
		$existing = 'Run2 Post 1 - 1P (rev 1)';
		$this->assertSame(
			'Run2 Post 1 - 1P (rev 5)',
			Content_Generator::apply_revision_suffix( $existing, 5 )
		);

		// ACT + ASSERT: Revision 0 strips any existing suffix.
		$this->assertSame(
			'Run2 Post 1 - 1P',
			Content_Generator::apply_revision_suffix( $existing, 0 )
		);
	}

	/**
	 * Verifies that apply_revision_to_content adds, replaces, and strips
	 * the revision note block idempotently.
	 */
	public function test_apply_revision_to_content(): void {
		// ARRANGE: A small Gutenberg snippet.
		$base = "<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->";

		// ACT: Apply revision 1.
		$first = Content_Generator::apply_revision_to_content( $base, 1 );

		// ASSERT: The marker block and revision label appear once.
		$this->assertStringContainsString( '<!-- seeder-rev-note -->', $first );
		$this->assertStringContainsString( 'Revision 1 update notice.', $first );
		$this->assertSame(
			1,
			substr_count( $first, '<!-- seeder-rev-note -->' )
		);

		// ACT: Re-apply at revision 4.
		$second = Content_Generator::apply_revision_to_content( $first, 4 );

		// ASSERT: Still one marker block, now labelled revision 4.
		$this->assertStringContainsString( 'Revision 4 update notice.', $second );
		$this->assertStringNotContainsString(
			'Revision 1 update notice.',
			$second
		);
		$this->assertSame(
			1,
			substr_count( $second, '<!-- seeder-rev-note -->' )
		);

		// ACT: Strip with revision 0.
		$stripped = Content_Generator::apply_revision_to_content( $second, 0 );

		// ASSERT: No marker block remains and the original body is intact.
		$this->assertStringNotContainsString(
			'<!-- seeder-rev-note -->',
			$stripped
		);
		$this->assertStringContainsString( '<p>Body.</p>', $stripped );
	}
}
