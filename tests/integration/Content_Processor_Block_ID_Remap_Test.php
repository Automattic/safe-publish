<?php
/**
 * Integration tests for Content_Processor's block-ID remap
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
 * entry point, asserting that nav-link/submenu/navigation and core/block
 * blocks have their post/term references rewritten and that unmapped
 * references surface as warnings without aborting the run.
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

		// ASSERT: id rewritten to the destination post.
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

		// ASSERT: id stayed at the source ID and no warning was raised.
		$this->assertStringContainsString( '"id":' . $source_id . ',', (string) $result );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that a permalink in a non-allowlisted custom block's attrs is
	 * host-swapped but never ID-remapped, even when the source post resolves.
	 */
	public function test_custom_block_permalink_attr_not_remapped(): void {
		// ARRANGE: A resolvable source->dest mapping plus a custom block that
		// stores the source permalink in an arbitrary attr.
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99023;
		update_post_meta( $dest_post, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_post,
			Options::META_SOURCE_SITE_URL,
			self::SOURCE_SITE_URL
		);

		$content = '<!-- wp:my-plugin/group {"postLink":"'
			. self::SOURCE_SITE_URL . '/?p=' . $source_id . '"} -->'
			. '<div class="wp-block-my-plugin-group"></div>'
			. '<!-- /wp:my-plugin/group -->';

		// ACT: Process with the mapping also offered via the session map.
		$result = (string) $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Host swapped to the destination, but the source id is kept
		// (not remapped to the dest post) and no unmapped-ref warning raised.
		$post_link = null;
		foreach ( parse_blocks( $result ) as $block ) {
			if ( 'my-plugin/group' === ( $block['blockName'] ?? '' ) ) {
				$post_link = $block['attrs']['postLink'] ?? null;
				break;
			}
		}

		$this->assertSame( 'http://example.org/?p=' . $source_id, $post_link );
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
	 * Verifies that core/navigation.ref resolves a wp_navigation post via
	 * postmeta when the source ID isn't in the session map. The post type
	 * is excluded from search, so the lookup must not rely on 'any'.
	 */
	public function test_remaps_navigation_ref_via_postmeta_fallback(): void {
		// ARRANGE: A destination nav post carrying the source-tracking meta
		// from a prior-session import.
		$dest_nav  = self::factory()->post->create(
			array( 'post_type' => 'wp_navigation' )
		);
		$source_id = 99007;
		update_post_meta( $dest_nav, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_nav,
			Options::META_SOURCE_SITE_URL,
			self::SOURCE_SITE_URL
		);

		$content = '<!-- wp:navigation {"ref":' . $source_id . '} /-->';

		// ACT: Empty session map — the lookup must find the wp_navigation
		// post via postmeta.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: ref rewritten to the destination nav; no warning raised.
		$this->assertStringContainsString( '"ref":' . $dest_nav, (string) $result );
		$this->assertStringNotContainsString( '"ref":' . $source_id, (string) $result );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that core/block.ref is rewritten via the session map when the
	 * referenced source wp_block resolves to an in-batch import.
	 */
	public function test_remaps_reusable_block_ref_via_session_map(): void {
		// ARRANGE: A destination wp_block standing in for the freshly imported
		// reusable block, and a core/block referencing the source's ID for it.
		$dest_block = self::factory()->post->create(
			array( 'post_type' => 'wp_block' )
		);
		$source_id  = 99030;
		$content    = '<!-- wp:block {"ref":' . $source_id . '} /-->';

		// ACT: Process with the session map carrying the mapping.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_block ) )
		);

		// ASSERT: ref now points at the destination block; no warning raised.
		$this->assertStringContainsString( '"ref":' . $dest_block, (string) $result );
		$this->assertStringNotContainsString( '"ref":' . $source_id, (string) $result );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that core/block.ref resolves a wp_block via postmeta when the
	 * source ID isn't in the session map. wp_block is excluded from search, so
	 * the lookup must not rely on 'any'.
	 */
	public function test_remaps_reusable_block_ref_via_postmeta_fallback(): void {
		// ARRANGE: A destination wp_block carrying the source-tracking meta from
		// a prior-session import.
		$dest_block = self::factory()->post->create(
			array( 'post_type' => 'wp_block' )
		);
		$source_id  = 99031;
		update_post_meta( $dest_block, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_block,
			Options::META_SOURCE_SITE_URL,
			self::SOURCE_SITE_URL
		);

		$content = '<!-- wp:block {"ref":' . $source_id . '} /-->';

		// ACT: Empty session map — the lookup must find the wp_block via postmeta.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: ref rewritten to the destination block; no warning raised.
		$this->assertStringContainsString( '"ref":' . $dest_block, (string) $result );
		$this->assertStringNotContainsString( '"ref":' . $source_id, (string) $result );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that an unresolved core/block.ref is left in place and surfaces
	 * as an unmapped_block_reference warning keyed to the core/block name, so a
	 * missing reusable-block target folds into the retryable degradation.
	 */
	public function test_reusable_block_ref_unmapped_when_target_absent(): void {
		// ARRANGE: A core/block whose source wp_block is not on the destination.
		$source_id = 99032;
		$content   = '<!-- wp:block {"ref":' . $source_id . '} /-->';

		// ACT: Process with no mapping available.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: ref stays at the source value; one warning names core/block.
		$this->assertStringContainsString( '"ref":' . $source_id, (string) $result );
		$this->assertSame(
			array(
				array(
					'type'      => 'unmapped_block_reference',
					'kind'      => 'post',
					'block'     => 'core/block',
					'source_id' => $source_id,
				),
			),
			$this->processor->get_warnings()
		);
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
	 * Verifies that a post-type nav-link url is re-derived to the destination
	 * permalink when a slug collision moves the imported page to a new path.
	 */
	public function test_rederives_post_link_url_on_slug_collision(): void {
		// ARRANGE: A pre-existing page owns /about, so the imported page lands
		// at /about-2; a nav-link references it via the session map.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$source_id = 99010;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Process with the session map resolving the source id.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: url re-derived to the destination permalink (/about-2/).
		$permalink = (string) get_permalink( $dest_post );
		$this->assertStringContainsString( '/about-2/', $permalink );
		$this->assertSame(
			$permalink,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a core/navigation-submenu url is re-derived too, confirming
	 * the submenu registry entries carry url_attr.
	 */
	public function test_rederives_submenu_url_on_slug_collision(): void {
		// ARRANGE: A collision sends the imported page to /about-2; a submenu
		// links to it via the session map.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$source_id = 99018;
		$content   = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-submenu',
					'attrs' => array(
						'id'    => $source_id,
						'kind'  => 'post-type',
						'label' => 'About',
						'url'   => self::SOURCE_SITE_URL . '/about',
					),
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Submenu url re-derived to the destination permalink.
		$permalink = (string) get_permalink( $dest_post );
		$this->assertStringContainsString( '/about-2/', $permalink );
		$this->assertSame(
			$permalink,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a url fragment is preserved when a colliding link's url is
	 * re-derived.
	 */
	public function test_preserves_fragment_when_rederiving_url(): void {
		// ARRANGE: A nav-link to /about#team whose target lands at /about-2.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$source_id = 99011;
		$content   = $this->nav_block_content(
			array(
				$this->post_link(
					$source_id,
					self::SOURCE_SITE_URL . '/about#team'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Destination permalink with the original fragment re-appended.
		$expected = (string) get_permalink( $dest_post ) . '#team';
		$this->assertSame(
			$expected,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a portable query param (e.g. utm) is preserved when the url
	 * is re-derived.
	 */
	public function test_preserves_portable_query_when_rederiving_url(): void {
		// ARRANGE: A colliding nav-link carrying a tracking param.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$source_id = 99012;
		$content   = $this->nav_block_content(
			array(
				$this->post_link(
					$source_id,
					self::SOURCE_SITE_URL . '/about?utm=spring'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Destination permalink with the tracking param retained.
		$expected = (string) get_permalink( $dest_post ) . '?utm=spring';
		$this->assertSame(
			$expected,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a post-identity query var (page_id) is stripped, so a plain
	 * permalink source's stale id cannot override the re-derived path.
	 */
	public function test_drops_identity_query_when_rederiving_url(): void {
		// ARRANGE: A colliding nav-link whose url carries a source page_id.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$source_id = 99019;
		$content   = $this->nav_block_content(
			array(
				$this->post_link(
					$source_id,
					self::SOURCE_SITE_URL . '/?page_id=12345'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Clean destination permalink, identity query dropped.
		$permalink = (string) get_permalink( $dest_post );
		$this->assertStringContainsString( '/about-2/', $permalink );
		$this->assertSame(
			$permalink,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a language query on a bare site-root url (as multilingual
	 * plugins emit for the homepage) is preserved, not treated as an identity.
	 */
	public function test_preserves_language_query_on_bare_root_url(): void {
		// ARRANGE: A colliding nav-link whose url is a bare root plus ?lang.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$source_id = 99020;
		$content   = $this->nav_block_content(
			array(
				$this->post_link(
					$source_id,
					self::SOURCE_SITE_URL . '/?lang=de'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Language param retained on the re-derived permalink.
		$expected = (string) get_permalink( $dest_post ) . '?lang=de';
		$this->assertSame(
			$expected,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a fragment on a non-colliding link is preserved, guarding
	 * the unconditional re-derive against dropping the fragment.
	 */
	public function test_preserves_fragment_on_non_colliding_link(): void {
		// ARRANGE: A single /contact page, so no collision occurs.
		$this->set_permalink_structure( '/%postname%/' );
		$dest_post = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'contact',
			)
		);
		$source_id = 99013;
		$content   = $this->nav_block_content(
			array(
				$this->post_link(
					$source_id,
					self::SOURCE_SITE_URL . '/contact#form'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: Same path, fragment intact.
		$permalink = (string) get_permalink( $dest_post );
		$this->assertStringContainsString( '/contact/', $permalink );
		$this->assertSame(
			$permalink . '#form',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a taxonomy nav-link url is re-derived via get_term_link.
	 */
	public function test_rederives_taxonomy_link_url_via_term_link(): void {
		// ARRANGE: Pretty permalinks with category permastructs registered, so
		// get_term_link yields a path; plus a term with paired source meta.
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		$dest_term = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$source_id = 99014;
		update_term_meta( $dest_term, Options::META_SOURCE_TERM_ID, $source_id );
		update_term_meta(
			$dest_term,
			Options::META_SOURCE_TERM_URL,
			self::SOURCE_SITE_URL
		);
		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/category/news'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: url re-derived to the destination term link.
		$expected = get_term_link( $dest_term );
		$this->assertIsString( $expected );
		$this->assertSame(
			$expected,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a taxonomy link whose resolved term no longer exists (so
	 * get_term_link returns WP_Error) keeps its original url.
	 */
	public function test_leaves_url_when_term_link_errors(): void {
		// ARRANGE: Paired meta pointing at a term id that does not exist, so
		// the id resolves but get_term_link errors.
		$this->set_permalink_structure( '/%postname%/' );
		$phantom_term = 99990001;
		$source_id    = 99015;
		add_term_meta( $phantom_term, Options::META_SOURCE_TERM_ID, $source_id );
		add_term_meta(
			$phantom_term,
			Options::META_SOURCE_TERM_URL,
			self::SOURCE_SITE_URL
		);
		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/category/news'
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: id still resolved — a claim with no term has no taxonomy to
		// mismatch — but the url is only host-swapped, not re-derived.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $phantom_term, $attrs['id'] ?? 0 );
		$this->assertSame(
			'http://example.org/category/news',
			$attrs['url'] ?? ''
		);
	}

	/**
	 * Verifies that a same-id link scoped to a different source site is left
	 * untouched — neither id nor url is re-derived.
	 */
	public function test_leaves_url_for_different_source_scope(): void {
		// ARRANGE: A destination post scoped to a DIFFERENT source site.
		$this->set_permalink_structure( '/%postname%/' );
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99016;
		update_post_meta( $dest_post, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_post,
			Options::META_SOURCE_SITE_URL,
			self::OTHER_SOURCE
		);
		$content = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Process under the SOURCE_SITE_URL scope.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: id stayed at the source value; url only host-swapped.
		$this->assertStringContainsString( '"id":' . $source_id . ',', (string) $result );
		$this->assertSame(
			'http://example.org/about',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that on a plain-permalink destination the url is re-derived to
	 * the canonical ?page_id form, the correct address there.
	 */
	public function test_rederives_url_on_plain_permalink_dest(): void {
		// ARRANGE: Plain permalink structure; a resolvable session mapping.
		$this->set_permalink_structure( '' );
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99017;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: url re-derived to the canonical destination permalink.
		$permalink = (string) get_permalink( $dest_post );
		$this->assertStringContainsString( '?page_id=' . $dest_post, $permalink );
		$this->assertSame(
			$permalink,
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a draft target's url is left untouched (its slug is not yet
	 * final), deferring correction.
	 */
	public function test_defers_draft_post_target(): void {
		// ARRANGE: Pretty permalinks; the target post is a draft.
		$this->set_permalink_structure( '/%postname%/' );
		$dest_post = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$source_id = 99021;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: id remapped, but the url only host-swapped (re-derive deferred).
		$this->assertStringContainsString( '"id":' . $dest_post . ',', (string) $result );
		$this->assertSame(
			'http://example.org/about',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a scheduled target's url is re-derived to the permalink it
	 * will carry on publication, not the temporary plain form.
	 */
	public function test_rederives_scheduled_post_target(): void {
		// ARRANGE: Pretty permalinks; a scheduled target whose slug was uniqued
		// against a page already holding /about.
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$dest_post = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'future',
				'post_name'     => 'about',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$source_id = 99031;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: id remapped and url on the collision-resolved destination slug.
		$this->assertStringContainsString( '"id":' . $dest_post . ',', (string) $result );
		$this->assertSame(
			'http://example.org/about-2/',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a target in a non-public custom status is re-derived to its
	 * settled pretty permalink rather than a plain one.
	 */
	public function test_rederives_non_public_custom_status_target(): void {
		// ARRANGE: Pretty permalinks; the target sits in a non-public status.
		register_post_status( 'sp_hidden', array( 'public' => false ) );
		$this->set_permalink_structure( '/%postname%/' );
		$dest_post = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'sp_hidden',
				'post_name'   => 'about',
			)
		);
		$source_id = 99032;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: id remapped and url re-derived to the destination permalink.
		$this->assertStringContainsString( '"id":' . $dest_post . ',', (string) $result );
		$this->assertSame(
			'http://example.org/about/',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a target in a search-excluded custom status resolves through
	 * post meta and is re-derived to its settled pretty permalink.
	 */
	public function test_rederives_search_excluded_custom_status_target(): void {
		// ARRANGE: A status the identity lookup has to name explicitly, claimed
		// through post meta so the lookup does the resolving.
		register_post_status(
			'sp_hidden_xfs',
			array(
				'public'              => false,
				'exclude_from_search' => true,
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		$dest_post = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'sp_hidden_xfs',
				'post_name'   => 'about',
			)
		);
		$source_id = 99033;
		update_post_meta( $dest_post, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta(
			$dest_post,
			Options::META_SOURCE_SITE_URL,
			self::SOURCE_SITE_URL
		);
		$content = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Run process_content with no session map, forcing the meta lookup.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: id remapped and url re-derived to the destination permalink.
		$this->assertStringContainsString( '"id":' . $dest_post . ',', (string) $result );
		$this->assertSame(
			'http://example.org/about/',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a private target keeps re-deriving, its permalink already
	 * being the address the post is served at.
	 */
	public function test_rederives_private_post_target(): void {
		// ARRANGE: Pretty permalinks; the target is private.
		$this->set_permalink_structure( '/%postname%/' );
		$dest_post = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_name'   => 'about',
			)
		);
		$source_id = 99035;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: url re-derived to the destination permalink.
		$this->assertSame(
			'http://example.org/about/',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a custom taxonomy's registered query var is stripped, found
	 * dynamically from the destination term's taxonomy.
	 */
	public function test_drops_custom_taxonomy_identity_query(): void {
		// ARRANGE: A custom taxonomy with a query var and a destination term in
		// it carrying paired source meta.
		register_taxonomy(
			'sp_topic',
			'post',
			array(
				'query_var' => 'sp_topic',
				'rewrite'   => false,
			)
		);
		$dest_term = self::factory()->term->create(
			array( 'taxonomy' => 'sp_topic' )
		);
		$source_id = 99022;
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
						'type'  => 'sp_topic',
						'label' => 'Topic',
						'url'   => self::SOURCE_SITE_URL . '/?sp_topic=news',
					),
				),
			)
		);

		// ACT: Run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The source's custom query var is gone; url is the term link.
		$url = (string) $this->first_nav_link_url( (string) $result );
		$this->assertStringNotContainsString( 'sp_topic=news', $url );
		$this->assertSame( get_term_link( $dest_term ), $url );
	}

	/**
	 * Verifies that a taxonomy link claimed by terms in two taxonomies
	 * resolves to the one its type attr declares, not the newest claim.
	 */
	public function test_taxonomy_link_picks_declared_taxonomy_over_newer_claim(): void {
		// ARRANGE: A category and a newer tag both claiming one source term.
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		$source_id = 99101;
		$category  = $this->claiming_term( 'category', $source_id );
		$tag       = $this->claiming_term( 'post_tag', $source_id );
		$this->assertGreaterThan( $category, $tag );

		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/category/news'
				),
			)
		);

		// ACT: Process a link declaring type=category.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The category won, and the url is its archive.
		$expected = get_term_link( $category );
		$this->assertIsString( $expected );
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $category, $attrs['id'] ?? 0 );
		$this->assertSame( $expected, $attrs['url'] ?? '' );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that a taxonomy link declaring the editor's tag alias resolves
	 * to the post_tag claim rather than the category one.
	 */
	public function test_taxonomy_link_resolves_tag_alias_to_post_tag(): void {
		// ARRANGE: The same two claims, with the tag created first so it
		// cannot win on recency alone.
		$source_id = 99102;
		$tag       = $this->claiming_term( 'post_tag', $source_id );
		$category  = $this->claiming_term( 'category', $source_id );
		$this->assertGreaterThan( $tag, $category );

		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/tag/news',
					'tag'
				),
			)
		);

		// ACT: Process a link declaring type=tag.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The post_tag term won.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $tag, $attrs['id'] ?? 0 );
	}

	/**
	 * Verifies that a taxonomy link whose only claim sits in another taxonomy
	 * keeps its source id and is reported as unmapped.
	 */
	public function test_taxonomy_link_unmapped_when_only_claim_is_another_taxonomy(): void {
		// ARRANGE: A tag holding the only claim on the source term.
		$source_id = 99103;
		$this->claiming_term( 'post_tag', $source_id );

		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/category/news'
				),
			)
		);

		// ACT: Process a link declaring type=category.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: id untouched, url only host-swapped, one warning raised.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $source_id, $attrs['id'] ?? 0 );
		$this->assertSame(
			'http://example.org/category/news',
			$attrs['url'] ?? ''
		);
		$this->assertSame(
			array(
				array(
					'type'      => 'unmapped_block_reference',
					'kind'      => 'term',
					'block'     => 'core/navigation-link',
					'source_id' => $source_id,
				),
			),
			$this->processor->get_warnings()
		);
	}

	/**
	 * Verifies that a taxonomy link carrying no type attr — core omits it on
	 * some links — still resolves, keeping the newest claim.
	 */
	public function test_taxonomy_link_without_type_keeps_newest_claim(): void {
		// ARRANGE: A category and a newer tag both claiming one source term.
		$source_id = 99104;
		$this->claiming_term( 'category', $source_id );
		$tag = $this->claiming_term( 'post_tag', $source_id );

		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/category/news',
					null
				),
			)
		);

		// ACT: Process a link declaring no type.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The newest claim won, as before the taxonomy check.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $tag, $attrs['id'] ?? 0 );
		$this->assertArrayNotHasKey( 'type', $attrs );
	}

	/**
	 * Verifies that a hyphenated taxonomy matches the underscored form the
	 * editor writes into the type attr.
	 */
	public function test_taxonomy_link_matches_hyphenated_taxonomy_declared_with_underscore(): void {
		// ARRANGE: A hyphen-slugged taxonomy claim plus a newer category one.
		register_taxonomy(
			'sp-topic',
			'post',
			array(
				'query_var' => false,
				'rewrite'   => false,
			)
		);
		$source_id = 99105;
		$topic     = $this->claiming_term( 'sp-topic', $source_id );
		$this->claiming_term( 'category', $source_id );

		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/topic/news',
					'sp_topic'
				),
			)
		);

		// ACT: Process a link declaring the editor's underscored form.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The hyphenated taxonomy's term won over the newer category.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $topic, $attrs['id'] ?? 0 );

		unregister_taxonomy( 'sp-topic' );
	}

	/**
	 * Verifies that two claims inside the declared taxonomy still resolve to
	 * the newest, matching the copy the term import writes to.
	 */
	public function test_taxonomy_link_keeps_newest_claim_within_declared_taxonomy(): void {
		// ARRANGE: Two categories claiming one source term.
		$source_id = 99106;
		$older     = $this->claiming_term( 'category', $source_id );
		$newer     = $this->claiming_term( 'category', $source_id );
		$this->assertGreaterThan( $older, $newer );

		$content = $this->nav_block_content(
			array(
				$this->taxonomy_link(
					$source_id,
					self::SOURCE_SITE_URL . '/category/news'
				),
			)
		);

		// ACT: Process a link declaring type=category.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The newest of the two matching claims won.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $newer, $attrs['id'] ?? 0 );
	}

	/**
	 * Verifies that a taxonomy submenu honors its declared type too, as both
	 * nav blocks share the term attr registry.
	 */
	public function test_taxonomy_submenu_picks_claim_matching_declared_type(): void {
		// ARRANGE: A category and a newer tag both claiming one source term.
		$source_id = 99107;
		$category  = $this->claiming_term( 'category', $source_id );
		$this->claiming_term( 'post_tag', $source_id );

		$content = $this->nav_block_content(
			array(
				array(
					'name'  => 'core/navigation-submenu',
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

		// ACT: Process a submenu declaring type=category.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: The category won over the newer tag.
		$attrs = $this->first_nav_link_attrs( (string) $result );
		$this->assertSame( $category, $attrs['id'] ?? 0 );
	}

	/**
	 * Creates a destination term in the taxonomy claiming the source term id.
	 *
	 * @param string $taxonomy  Destination taxonomy.
	 * @param int    $source_id Source term id to claim.
	 * @return int Created term id.
	 */
	private function claiming_term( string $taxonomy, int $source_id ): int {
		$term_id = self::factory()->term->create(
			array( 'taxonomy' => $taxonomy )
		);
		$this->assertIsInt( $term_id );
		update_term_meta( $term_id, Options::META_SOURCE_TERM_ID, $source_id );
		update_term_meta(
			$term_id,
			Options::META_SOURCE_TERM_URL,
			self::SOURCE_SITE_URL
		);

		return $term_id;
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

	/**
	 * Builds a post-type nav-link inner-block shape.
	 *
	 * @param int    $source_id Source post ID for the id attr.
	 * @param string $url       Link url attr.
	 * @return array{name:string, attrs:array<string,mixed>} Inner-block shape.
	 */
	private function post_link( int $source_id, string $url ): array {
		return array(
			'name'  => 'core/navigation-link',
			'attrs' => array(
				'id'    => $source_id,
				'kind'  => 'post-type',
				'label' => 'About',
				'url'   => $url,
			),
		);
	}

	/**
	 * Builds a taxonomy nav-link inner-block shape.
	 *
	 * @param int         $source_id Source term ID for the id attr.
	 * @param string      $url       Link url attr.
	 * @param string|null $type      Declared taxonomy; null omits the attr.
	 * @return array{name:string, attrs:array<string,mixed>} Inner-block shape.
	 */
	private function taxonomy_link(
		int $source_id,
		string $url,
		?string $type = 'category'
	): array {
		$attrs = array(
			'id'   => $source_id,
			'kind' => 'taxonomy',
		);

		if ( null !== $type ) {
			$attrs['type'] = $type;
		}

		$attrs['label'] = 'News';
		$attrs['url']   = $url;

		return array(
			'name'  => 'core/navigation-link',
			'attrs' => $attrs,
		);
	}

	/**
	 * Returns the attrs of the first nav-link or submenu in the content.
	 *
	 * @param string $content Serialized block content.
	 * @return array<string, mixed> Attrs, or empty when none is found.
	 */
	private function first_nav_link_attrs( string $content ): array {
		foreach ( parse_blocks( $content ) as $block ) {
			$found = $this->find_nav_link_attrs( $block );
			if ( array() !== $found ) {
				return $found;
			}
		}

		return array();
	}

	/**
	 * Recursively finds the first nav-link or submenu attrs in a subtree.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed> Attrs, or empty when not found.
	 */
	private function find_nav_link_attrs( array $block ): array {
		$name = $block['blockName'] ?? '';
		if (
			(
				'core/navigation-link' === $name
				|| 'core/navigation-submenu' === $name
			)
			&& isset( $block['attrs'] )
			&& is_array( $block['attrs'] )
		) {
			return $block['attrs'];
		}

		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			$found = $this->find_nav_link_attrs( $inner );
			if ( array() !== $found ) {
				return $found;
			}
		}

		return array();
	}

	/**
	 * Returns the url attr of the first core/navigation-link in the content.
	 *
	 * @param string $content Serialized block content.
	 * @return string|null The url attr, or null when absent.
	 */
	private function first_nav_link_url( string $content ): ?string {
		foreach ( parse_blocks( $content ) as $block ) {
			$found = $this->find_nav_link_url( $block );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Recursively finds the first nav-link url attr in a block subtree.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null The url attr, or null when not found.
	 */
	private function find_nav_link_url( array $block ): ?string {
		$name = $block['blockName'] ?? '';
		if (
			(
				'core/navigation-link' === $name
				|| 'core/navigation-submenu' === $name
			)
			&& isset( $block['attrs']['url'] )
			&& is_string( $block['attrs']['url'] )
		) {
			return $block['attrs']['url'];
		}

		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			$found = $this->find_nav_link_url( $inner );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}
}
