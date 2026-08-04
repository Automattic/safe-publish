<?php
/**
 * Integration tests for gallery/playlist singular id post-reference rewriting
 * through Content_Processor::process_content().
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
use Safe_Publish\Utils\Options;

/**
 * Drives the singular gallery/playlist `id` post-reference rewrite via the
 * public process_content() entry point: a cross-post id resolves against the
 * in-batch session map and a prior DB import, an unresolved id is left in place
 * and warned, and a self id is stripped to the bare shortcode.
 */
class Content_Processor_Gallery_Post_Reference_Test extends Integration_Test_Case {

	private const SOURCE = 'https://source.example.com';

	/**
	 * Builds a Content_Processor wired against real WP dependencies.
	 *
	 * @return Content_Processor
	 */
	private function build_processor(): Content_Processor {
		$media_importer = new Media_Importer( new HTTP_Client() );

		return new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);
	}

	/**
	 * Creates a destination post tagged with a source post id and the source.
	 *
	 * @param int $source_id Source post id meta value.
	 * @return int Created destination post id.
	 */
	private function seed_imported_post( int $source_id ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, self::SOURCE );

		return $post_id;
	}

	/**
	 * Verifies that a cross-post gallery id referencing a post imported in the
	 * same batch is remapped to its destination id from the session map.
	 */
	public function test_same_batch_reference_remapped_from_session_map(): void {
		// ARRANGE: The referenced source post B maps to a dest id in this batch.
		$processor = $this->build_processor();
		$content   = '[gallery id="500"]';

		// ACT: Process with B => dest in the session map.
		$result = $processor->process_content(
			$content,
			self::SOURCE,
			array(
				'session_id_map' => array( 500 => 8080 ),
				'source_post_id' => 700,
			)
		);

		// ASSERT: The id is remapped, no warning raised.
		$this->assertStringContainsString( '[gallery id="8080"]', $result );
		$this->assertSame( array(), $processor->get_warnings() );
	}

	/**
	 * Verifies that a cross-post playlist id referencing a post imported in an
	 * earlier session is remapped via the source-scoped DB lookup.
	 */
	public function test_prior_import_reference_remapped_from_db(): void {
		// ARRANGE: Post B was imported earlier, tagged with its source identity.
		$processor = $this->build_processor();
		$dest_b    = $this->seed_imported_post( 501 );
		$content   = '[playlist id="501"]';

		// ACT: Process with no session map, forcing the DB lookup.
		$result = $processor->process_content(
			$content,
			self::SOURCE,
			array( 'source_post_id' => 700 )
		);

		// ASSERT: The id is remapped to the prior import, no warning raised.
		$this->assertStringContainsString(
			'[playlist id="' . $dest_b . '"]',
			$result
		);
		$this->assertSame( array(), $processor->get_warnings() );
	}

	/**
	 * Verifies that a cross-post id with no destination is left in place and
	 * surfaced as an unmapped-gallery-reference warning.
	 */
	public function test_unmapped_reference_left_and_warned(): void {
		// ARRANGE: The referenced post is not imported anywhere.
		$processor = $this->build_processor();
		$content   = '[gallery id="909"]';

		// ACT: Process the content.
		$result = $processor->process_content(
			$content,
			self::SOURCE,
			array( 'source_post_id' => 700 )
		);

		// ASSERT: The id is untouched and one warning carries the source id.
		$this->assertStringContainsString( '[gallery id="909"]', $result );
		$this->assertSame(
			array(
				array(
					'type'      => 'unmapped_gallery_reference',
					'source_id' => 909,
				),
			),
			$processor->get_warnings()
		);
	}

	/**
	 * Verifies that a gallery id naming the importing post's own set is
	 * stripped to the bare shortcode, raising no warning.
	 */
	public function test_self_reference_stripped_to_bare(): void {
		// ARRANGE: The shortcode id equals the importing post's own source id.
		$processor = $this->build_processor();
		$content   = '[gallery id="700" columns="2"]';

		// ACT: Process with source_post_id matching the shortcode id.
		$result = $processor->process_content(
			$content,
			self::SOURCE,
			array( 'source_post_id' => 700 )
		);

		// ASSERT: The redundant id is stripped, other attrs kept, no warning.
		$this->assertStringContainsString( '[gallery columns="2"]', $result );
		$this->assertStringNotContainsString( 'id="700"', $result );
		$this->assertSame( array(), $processor->get_warnings() );
	}
}
