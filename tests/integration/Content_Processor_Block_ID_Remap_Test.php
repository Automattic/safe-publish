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

		// ASSERT: Id stayed at the source ID and no warning was raised.
		$this->assertStringContainsString( '"id":' . $source_id . ',', (string) $result );
		$this->assertSame( array(), $this->processor->get_warnings() );
	}

	/**
	 * Verifies that a permalink in a non-allowlisted custom block's attrs is
	 * host-swapped but never ID-remapped, even when the source post resolves.
	 */
	public function test_custom_block_permalink_attr_not_remapped(): void {
		// ARRANGE: a resolvable source->dest mapping plus a custom block that
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

		// ACT: process with the mapping also offered via the session map.
		$result = (string) $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: host swapped to the destination, but the source id is kept
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
		// ARRANGE: a destination wp_block standing in for the freshly imported
		// reusable block, and a core/block referencing the source's ID for it.
		$dest_block = self::factory()->post->create(
			array( 'post_type' => 'wp_block' )
		);
		$source_id  = 99030;
		$content    = '<!-- wp:block {"ref":' . $source_id . '} /-->';

		// ACT: process with the session map carrying the mapping.
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
		// ARRANGE: a destination wp_block carrying the source-tracking meta from
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

		// ACT: empty session map — the lookup must find the wp_block via postmeta.
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
		// ARRANGE: a core/block whose source wp_block is not on the destination.
		$source_id = 99032;
		$content   = '<!-- wp:block {"ref":' . $source_id . '} /-->';

		// ACT: process with no mapping available.
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
		// ARRANGE: a pre-existing page owns /about, so the imported page lands
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

		// ACT: process with the session map resolving the source id.
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
		// ARRANGE: a collision sends the imported page to /about-2; a submenu
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: submenu url re-derived to the destination permalink.
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
		// ARRANGE: a nav-link to /about#team whose target lands at /about-2.
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: destination permalink with the original fragment re-appended.
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
		// ARRANGE: a colliding nav-link carrying a tracking param.
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: destination permalink with the tracking param retained.
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
		// ARRANGE: a colliding nav-link whose url carries a source page_id.
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: clean destination permalink, identity query dropped.
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
		// ARRANGE: a colliding nav-link whose url is a bare root plus ?lang.
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: language param retained on the re-derived permalink.
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
		// ARRANGE: a single /contact page, so no collision occurs.
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array( 'session_id_map' => array( $source_id => $dest_post ) )
		);

		// ASSERT: same path, fragment intact.
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
		// ARRANGE: pretty permalinks with category permastructs registered, so
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

		// ACT: run process_content.
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
		// ARRANGE: paired meta pointing at a term id that does not exist, so
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: url only host-swapped, not re-derived.
		$this->assertSame(
			'http://example.org/category/news',
			$this->first_nav_link_url( (string) $result )
		);
	}

	/**
	 * Verifies that a same-id link scoped to a different source site is left
	 * untouched — neither id nor url is re-derived.
	 */
	public function test_leaves_url_for_different_source_scope(): void {
		// ARRANGE: a destination post scoped to a DIFFERENT source site.
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

		// ACT: process under the SOURCE_SITE_URL scope.
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
		// ARRANGE: plain permalink structure; a resolvable session mapping.
		$this->set_permalink_structure( '' );
		$dest_post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$source_id = 99017;
		$content   = $this->nav_block_content(
			array( $this->post_link( $source_id, self::SOURCE_SITE_URL . '/about' ) )
		);

		// ACT: run process_content.
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
		// ARRANGE: pretty permalinks; the target post is a draft.
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

		// ACT: run process_content.
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
	 * Verifies that a custom taxonomy's registered query var is stripped, found
	 * dynamically from the destination term's taxonomy.
	 */
	public function test_drops_custom_taxonomy_identity_query(): void {
		// ARRANGE: a custom taxonomy with a query var and a destination term in
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

		// ACT: run process_content.
		$result = $this->processor->process_content(
			$content,
			self::SOURCE_SITE_URL,
			array()
		);

		// ASSERT: the source's custom query var is gone; url is the term link.
		$url = (string) $this->first_nav_link_url( (string) $result );
		$this->assertStringNotContainsString( 'sp_topic=news', $url );
		$this->assertSame( get_term_link( $dest_term ), $url );
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
	 * @param int    $source_id Source term ID for the id attr.
	 * @param string $url       Link url attr.
	 * @return array{name:string, attrs:array<string,mixed>} Inner-block shape.
	 */
	private function taxonomy_link( int $source_id, string $url ): array {
		return array(
			'name'  => 'core/navigation-link',
			'attrs' => array(
				'id'    => $source_id,
				'kind'  => 'taxonomy',
				'type'  => 'category',
				'label' => 'News',
				'url'   => $url,
			),
		);
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
