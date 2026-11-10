<?php
/**
 * Diff renderer block alignment integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Covers how the block diff pairs blocks: Equal blocks align first, and an
 * edited block pairs with its own previous version.
 */
class Diff_Renderer_Block_Alignment_Test extends Integration_Test_Case {

	use Block_Diffs_Trait;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->set_up_diff_post();
	}

	/**
	 * Supplies content pairs and the entries their diff must list.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}> Contents and entries.
	 */
	public static function alignment_provider(): array {
		return array(
			'insertion'                     => array(
				self::content( 'One', 'Two', 'Three' ),
				self::content( 'New', 'One', 'Two', 'Three' ),
				array(
					'added: paragraph(New)',
					'unchanged: paragraph(One)',
					'unchanged: paragraph(Two)',
					'unchanged: paragraph(Three)',
				),
			),
			'removal'                       => array(
				self::content( 'One', 'Two', 'Three' ),
				self::content( 'Two', 'Three' ),
				array(
					'removed: paragraph(One)',
					'unchanged: paragraph(Two)',
					'unchanged: paragraph(Three)',
				),
			),
			'insertion above an edit'       => array(
				self::content( 'One', 'Two' ),
				self::content( 'New', 'One edited', 'Two' ),
				array(
					'added: paragraph(New)',
					'modified: paragraph(One) → paragraph(One edited)',
					'unchanged: paragraph(Two)',
				),
			),
			'removal above an edit'         => array(
				self::content( 'One', 'Two', 'Three' ),
				self::content( 'Two edited', 'Three' ),
				array(
					'removed: paragraph(One)',
					'modified: paragraph(Two) → paragraph(Two edited)',
					'unchanged: paragraph(Three)',
				),
			),
			'insertion below an edit'       => array(
				self::content( 'One', 'Two' ),
				self::content( 'One edited', 'New', 'Two' ),
				array(
					'modified: paragraph(One) → paragraph(One edited)',
					'added: paragraph(New)',
					'unchanged: paragraph(Two)',
				),
			),
			'adjacent edits'                => array(
				self::content( 'One', 'Two', 'Three' ),
				self::content( 'One edited', 'Two edited', 'Three' ),
				array(
					'modified: paragraph(One) → paragraph(One edited)',
					'modified: paragraph(Two) → paragraph(Two edited)',
					'unchanged: paragraph(Three)',
				),
			),
			'insertion between edits'       => array(
				self::content( 'Alpha beta', 'One two three' ),
				self::content(
					'Alpha gamma delta',
					'New',
					'One two three four'
				),
				array(
					'modified: paragraph(Alpha beta)'
						. ' → paragraph(Alpha gamma delta)',
					'added: paragraph(New)',
					'modified: paragraph(One two three)'
						. ' → paragraph(One two three four)',
				),
			),
			'no shared words'               => array(
				self::content( 'One' ),
				self::content( 'Alpha', 'Beta' ),
				array(
					'modified: paragraph(One) → paragraph(Alpha)',
					'added: paragraph(Beta)',
				),
			),
			'move'                          => array(
				self::content( 'One', 'Two' ),
				self::content( 'Two', 'One' ),
				array(
					'added: paragraph(Two)',
					'unchanged: paragraph(One)',
					'removed: paragraph(Two)',
				),
			),
			'conversion above an insertion' => array(
				self::content( 'Hello world' ),
				self::blocks(
					self::heading( 'Hello world' ),
					self::paragraph( 'New words' )
				),
				array(
					'modified: paragraph(Hello world) → heading(Hello world)',
					'added: paragraph(New words)',
				),
			),
			'conversion below an insertion' => array(
				self::content( 'Hello world' ),
				self::blocks(
					self::paragraph( 'New words' ),
					self::heading( 'Hello world' )
				),
				array(
					'added: paragraph(New words)',
					'modified: paragraph(Hello world) → heading(Hello world)',
				),
			),
			'image above an edited image'   => array(
				self::image( 'a.jpg' ),
				self::blocks(
					self::image( 'b.jpg' ),
					self::image( 'a.jpg', 'Alt' )
				),
				array(
					'added: image(b.jpg)',
					'modified: image(a.jpg) → image(a.jpg)',
				),
			),
			'identical text'                => array(
				self::image( 'a.jpg', '', 'Photo' ),
				self::blocks(
					self::image( 'b.jpg', '', 'Photo' ),
					self::image( 'a.jpg', 'Alt', 'Photo' )
				),
				array(
					'added: image(b.jpg Photo)',
					'modified: image(a.jpg Photo) → image(a.jpg Photo)',
				),
			),
			'text written without spaces'   => array(
				self::content( '日本語の文章です。' ),
				self::content(
					'新しい段落を追加しました。',
					'日本語の文章を少し編集しました。'
				),
				array(
					'added: paragraph(新しい段落を追加しました。)',
					'modified: paragraph(日本語の文章です。) → '
						. 'paragraph(日本語の文章を少し編集しました。)',
				),
			),
			'classic markup between blocks' => array(
				self::content( 'One', 'Two' ),
				self::blocks(
					self::paragraph( 'One' ),
					'<p>Classic</p>',
					self::paragraph( 'Two' )
				),
				array(
					'unchanged: paragraph(One)',
					'added: freeform(Classic)',
					'unchanged: paragraph(Two)',
				),
			),
		);
	}

	/**
	 * Verifies that equal blocks align, so only the blocks that changed are
	 * reported, each against its own previous version.
	 *
	 * @dataProvider alignment_provider
	 *
	 * @param string   $local    Local post content.
	 * @param string   $source   Source post content.
	 * @param string[] $expected Entries the diff must list, in order.
	 */
	public function test_blocks_pair_with_their_previous_versions(
		string $local,
		string $source,
		array $expected
	): void {
		// ACT: Diff the two sides.
		$diffs = $this->render_block_diffs( $local, $source );

		// ASSERT: The diff lists exactly the expected entries, in order.
		$this->assertSame( $expected, $this->describe( $diffs ) );
	}

	/**
	 * Verifies that an entry's index is its block's incoming position, or
	 * its current position when removed, so index and status stay a unique
	 * key.
	 */
	public function test_index_follows_the_side_that_holds_the_block(): void {
		// ACT: Diff content that loses its first block.
		$diffs = $this->render_block_diffs(
			self::content( 'One', 'Two', 'Three' ),
			self::content( 'Two', 'Three' )
		);

		// ASSERT: The removed block keeps its current position, and the kept
		// blocks take their incoming ones.
		$this->assertSame(
			array( '0-removed', '0-unchanged', '2-unchanged' ),
			array_map(
				static fn ( array $diff ): string =>
					$diff['index'] . '-' . $diff['status'],
				$diffs
			)
		);
	}

	/**
	 * Verifies that a block holding one very long word still pairs by its
	 * words.
	 */
	public function test_long_word_still_pairs_by_its_words(): void {
		// ARRANGE: A word long enough to exhaust a backtracking JIT stack.
		$word = str_repeat( 'a', 50000 );

		// ACT: Diff the block, edited below an inserted one.
		$diffs = $this->render_block_diffs(
			self::content( $word ),
			self::content( 'New', $word . ' edited' )
		);

		// ASSERT: The insertion is added, and the edit pairs with its block.
		$this->assertSame(
			array( 'added', 'modified' ),
			array_column( $diffs, 'status' )
		);
		$this->assertStringEndsWith(
			'edited</p>',
			trim( (string) $diffs[1]['incoming']['rendered'] )
		);
	}

	/**
	 * Verifies that runs too long to compare pair by position.
	 */
	public function test_long_runs_pair_by_position(): void {
		// ARRANGE: 50 blocks, each edited below 201 inserted ones, which takes
		// more comparisons than the limit allows.
		$texts    = array_map(
			static fn ( int $i ): string => 'Item ' . $i,
			range( 1, 50 )
		);
		$inserted = array_map(
			static fn ( int $i ): string => 'New ' . $i,
			range( 1, 201 )
		);
		$edited   = array_map(
			static fn ( string $text ): string => $text . ' edited',
			$texts
		);

		// ACT: Diff the two sides.
		$diffs = $this->render_block_diffs(
			self::content( ...$texts ),
			self::content( ...$inserted, ...$edited )
		);

		// ASSERT: The first block pairs with the first insertion.
		$this->assertSame(
			'modified: paragraph(Item 1) → paragraph(New 1)',
			$this->describe( $diffs )[0]
		);
	}

	/**
	 * Verifies that blocks holding too many words to compare pair by
	 * position.
	 */
	public function test_wordy_runs_pair_by_position(): void {
		// ARRANGE: One block of 10,000 distinct words, edited below 100
		// inserted blocks, which takes more word lookups than the limit
		// allows.
		$long = implode(
			' ',
			array_map(
				static fn ( int $i ): string => 'w' . $i,
				range( 1, 10000 )
			)
		);

		$source   = array_map(
			static fn ( int $i ): string => 'New ' . $i,
			range( 1, 100 )
		);
		$source[] = $long . ' edited';

		// ACT: Diff the two sides.
		$diffs = $this->render_block_diffs(
			self::content( $long ),
			self::content( ...$source )
		);

		// ASSERT: The block pairs with the first insertion.
		$this->assertStringEndsWith(
			' → paragraph(New 1)',
			$this->describe( $diffs )[0]
		);
	}

	/**
	 * Describes each entry as its status and the blocks it pairs.
	 *
	 * @param array<int, array<string, mixed>> $diffs Block diff entries.
	 * @return list<string> Entry descriptions.
	 */
	private function describe( array $diffs ): array {
		$label = static function ( ?array $side ): string {
			$rendered = (string) ( $side['rendered'] ?? '' );
			$text     = wp_strip_all_tags( $rendered );
			$parts    = array();
			if ( 1 === preg_match( '/src="([^"]+)"/', $rendered, $match ) ) {
				$parts[] = basename( $match[1] );
			}
			$parts[] = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
			$name    = (string) ( $side['name'] ?? 'freeform' );

			return str_replace( 'core/', '', $name )
				. '(' . trim( implode( ' ', $parts ) ) . ')';
		};

		return array_map(
			static function ( array $diff ) use ( $label ): string {
				$current  = $label( $diff['current'] );
				$incoming = $label( $diff['incoming'] );

				return $diff['status'] . ': ' . match ( $diff['status'] ) {
					'added'                => $incoming,
					'removed', 'unchanged' => $current,
					default                => $current . ' → ' . $incoming,
				};
			},
			$diffs
		);
	}

	/**
	 * Builds content of paragraph blocks.
	 *
	 * @param string ...$texts Paragraph texts.
	 * @return string Serialized blocks.
	 */
	private static function content( string ...$texts ): string {
		return self::blocks( ...array_map( self::paragraph( ... ), $texts ) );
	}

	/**
	 * Joins serialized blocks as the editor saves them.
	 *
	 * @param string ...$blocks Serialized blocks.
	 * @return string Post content.
	 */
	private static function blocks( string ...$blocks ): string {
		return implode( "\n\n", $blocks );
	}

	/**
	 * Builds a paragraph block.
	 *
	 * @param string $text Paragraph text.
	 * @return string Serialized block.
	 */
	private static function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . $text
			. '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Builds a heading block.
	 *
	 * @param string $text Heading text.
	 * @return string Serialized block.
	 */
	private static function heading( string $text ): string {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . $text
			. '</h2><!-- /wp:heading -->';
	}

	/**
	 * Builds an image block.
	 *
	 * @param string $file    Image file name.
	 * @param string $alt     Alternative text.
	 * @param string $caption Caption, or none when empty.
	 * @return string Serialized block.
	 */
	private static function image(
		string $file,
		string $alt = '',
		string $caption = ''
	): string {
		$figcaption = '' === $caption
			? ''
			: '<figcaption class="wp-element-caption">' . $caption
				. '</figcaption>';

		return '<!-- wp:image --><figure class="wp-block-image"><img src="'
			. 'https://example.com/' . $file . '" alt="' . $alt . '"/>'
			. $figcaption . '</figure><!-- /wp:image -->';
	}
}
