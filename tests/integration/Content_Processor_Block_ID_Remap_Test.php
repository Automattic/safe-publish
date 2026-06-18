<?php
/**
 * Integration tests for Content_Processor's block-ID remap.
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
 * Exercises process_block_id_references via the public process_content()
 * entry point, asserting that nav-link/submenu/navigation blocks have
 * their post/term references rewritten and that unmapped references
 * surface as warnings without aborting the run.
 */
class Content_Processor_Block_ID_Remap_Test extends Integration_Test_Case {

	private const SOURCE_SITE_URL = 'https://source.example.com';
	private const OTHER_SOURCE    = 'https://other-source.example.com';

	/**
	 * System under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * Builds a fresh Content_Processor wired against real WP dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$media_importer  = new Media_Importer( new HTTP_Client() );
		$this->processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);
	}

	/**
	 * Verifies that core/navigation-link.id is rewritten via the session map
	 * when kind=post-type and the source ID resolves to an in-batch import.
	 */
	public function test_remaps_post_link_id_via_session_map(): void {
		// ARRANGE: A destination post that stands in for the freshly imported
		// page, and a nav-link block referencing the source's ID for it.
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99001; // High constant; avoids collision with auto-incremented post IDs.
		$content   = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-link',
					'attrs' => array(
						'id'    => $source_id,
						'kind'  => 'post-type',
						'label' => 'About',
						'url'   => self::SOURCE_SITE_URL . '/about',
					),
				),
			)
		);

		// ACT: Run process_content with the session map carrying the mapping.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: id rewritten; URL host swap left to replace_source_urls.
		$this->assertStringContainsString( '"id":' . $dest_post . ',', (string) $result );
		$this->assertStringNotContainsString( '"id":' . $source_id . ',', (string) $result );
	}

	/**
	 * Verifies that core/navigation-link.id is rewritten via postmeta when
	 * the source ID isn't in the session map but a destination post carries
	 * the matching META_SOURCE_POST_ID and META_SOURCE_SITE_URL.
	 */
	public function test_remaps_post_link_id_via_postmeta_fallback(): void {
		// ARRANGE: Destination post with the source-tracking meta already in
		// place from a prior import.
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99002;
		update_post_meta( $dest_post, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_post,
			Options::META_SOURCE_SITE_URL,
			self::SOURCE_SITE_URL
		);

		$content = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-link',
					'attrs' => array(
						'id'    => $source_id,
						'kind'  => 'post-type',
						'label' => 'Contact',
						'url'   => self::SOURCE_SITE_URL . '/contact',
					),
				),
			)
		);

		// ACT: Empty session map — the lookup has to find it via postmeta.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: Rewritten to the destination ID.
		$this->assertStringContainsString( '"id":' . $dest_post . ',', (string) $result );
	}

	/**
	 * Verifies that a destination post imported from a different source site
	 * is not picked up by the postmeta lookup even if its source post ID
	 * happens to collide.
	 */
	public function test_postmeta_lookup_is_scoped_to_source_site(): void {
		// ARRANGE: A destination post whose META_SOURCE_SITE_URL points at a
		// DIFFERENT source site than the one we're remapping for.
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99003;
		update_post_meta( $dest_post, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_post,
			Options::META_SOURCE_SITE_URL,
			self::OTHER_SOURCE
		);

		$content = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-link',
					'attrs' => array(
						'id'    => $source_id,
						'kind'  => 'post-type',
						'label' => 'About',
						'url'   => self::SOURCE_SITE_URL . '/about',
					),
				),
			)
		);

		// ACT: Process under the SOURCE_SITE_URL scope.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: Source ID stayed in place; a warning was recorded.
		$this->assertStringContainsString( '"id":' . $source_id . ',', (string) $result );
		$warnings = $this->processor->get_warnings();
		$this->assertSame( 1, count( $warnings ) );
		$this->assertSame( 'unmapped_block_reference', $warnings[0]['type'] );
		$this->assertSame( $source_id, $warnings[0]['source_id'] );
	}

	/**
	 * Verifies that nav-link blocks gated by a kind value other than
	 * post-type/taxonomy (e.g. custom external URL) are left untouched.
	 */
	public function test_skips_custom_kind_nav_link(): void {
		// ARRANGE: A nav-link with kind=custom and a numeric id that should
		// NOT be treated as a post reference.
		$source_id = 99004;
		$content   = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-link',
					'attrs' => array(
						'id'    => $source_id,
						'kind'  => 'custom',
						'label' => 'External',
						'url'   => 'https://other.example.com/somewhere',
					),
				),
			)
		);

		// ACT: Process the custom-kind nav-link.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => 99999 ) )
		);

		// ASSERT: Id stayed at the source ID and no warning was raised.
		$this->assertStringContainsString( '"id":' . $source_id . ',', (string) $result );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that core/navigation.ref is rewritten via the session map.
	 */
	public function test_remaps_navigation_ref_via_session_map(): void {
		// ARRANGE: Destination nav post stand-in.
		$dest_nav  = self::factory()->post->create(
			array( 'post_type' => 'wp_navigation' )
		);
		$source_id = 99005;

		$content = '<!-- wp:navigation {"ref":' . $source_id . '} /-->';

		// ACT: Process the navigation block via the session map.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_nav ) )
		);

		// ASSERT: ref now points at the destination nav post.
		$this->assertStringContainsString( '"ref":' . $dest_nav, (string) $result );
		$this->assertStringNotContainsString( '"ref":' . $source_id, (string) $result );
	}

	/**
	 * Verifies that nav-link blocks with kind=taxonomy resolve their id via
	 * paired META_SOURCE_TERM_ID/META_SOURCE_TERM_URL term meta.
	 */
	public function test_remaps_taxonomy_link_id_via_term_meta(): void {
		// ARRANGE: A destination term with paired source-term meta.
		$dest_term = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$source_id = 99006;
		update_term_meta( $dest_term, Options::META_SOURCE_TERM_ID, $source_id );
		update_term_meta(
			$dest_term,
			Options::META_SOURCE_TERM_URL,
			self::SOURCE_SITE_URL
		);

		$content = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-link',
					'attrs' => array(
						'id'    => $source_id,
						'kind'  => 'taxonomy',
						'type'  => 'category',
						'label' => 'News',
						'url'   => self::SOURCE_SITE_URL . '/category/news',
					),
				),
			)
		);

		// ACT: Process the taxonomy nav-link.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: id resolved to the destination term.
		$this->assertStringContainsString( '"id":' . $dest_term . ',', (string) $result );
	}

	/**
	 * Wraps a list of nav-link/submenu shapes in a core/navigation block,
	 * matching the serializer output the editor would produce.
	 *
	 * @param list<array{name:string, attrs:array<string,mixed>}> $inner_blocks Inner-block shapes.
	 * @return string Block markup.
	 */
	private function nav_block_content( array $inner_blocks ): string {
		$parts = array( '<!-- wp:navigation -->' );
		foreach ( $inner_blocks as $inner ) {
			$parts[] = '<!-- wp:' . $inner['name'] . ' '
				. wp_json_encode( $inner['attrs'] ) . ' /-->';
		}
		$parts[] = '<!-- /wp:navigation -->';

		return implode( "\n", $parts );
	}
}
