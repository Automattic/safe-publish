<?php
/**
 * Shared seeder-import fixture for parity integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Seeder\Content_Generator;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use RuntimeException;
use WP_Term;

/**
 * Builds a seeded source batch and imports it once through the real import
 * service so parity tests can run read-only assertions against the result.
 *
 * Lives outside the TestCase hierarchy so it can seed a class-wide fixture from
 * wpSetUpBeforeClass(): the WordPress test framework wraps each test method in a
 * rolled-back transaction, so the single import must be committed before the
 * per-method transactions begin. It drives Post_Import_Service directly rather
 * than the AJAX controller — transport and two-pass orchestration are covered
 * by dedicated tests, and this batch carries no in-batch references that the
 * two-pass flow would affect.
 */
final class Seeder_Parity_Fixture {

	use Image_Byte_Mock_Trait;
	use Per_Source_Id_Media_Api_Mock_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;

	/**
	 * Plaintext password seeded on the non-default half of the batch. Chosen to
	 * survive sanitize_text_field() unchanged so the round-trip is verbatim.
	 */
	private const NON_DEFAULT_PASSWORD = 'Seeded-P@ssw0rd_42';

	/**
	 * Anchor UUID linking the footnotes edge body's in-text reference to its
	 * meta entry, as WordPress does. ASCII and slash-free so the meta JSON
	 * round-trips verbatim through update_post_meta().
	 */
	private const FOOTNOTE_ANCHOR_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Source wp_block ID referenced by the reusable-block edge body's core/block.
	 * This batch does not import the target wp_block, so the reference surfaces
	 * as a retryable unmapped_block_reference degradation.
	 */
	public const REUSABLE_BLOCK_SOURCE_REF = 9300001;

	/**
	 * Hierarchical taxonomy the parity suite registers so the batch exercises a
	 * real term tree end to end. Carried only on the post slice.
	 */
	public const SECTION_TAXONOMY = 'seeder_section';

	/**
	 * Source term IDs for the three-level section tree stamped on the post
	 * slice's safe_publish_terms field. Only the leaf is assigned; the root and
	 * child travel as unassigned ancestors.
	 */
	private const SECTION_ROOT_SOURCE_ID  = 9700001;
	private const SECTION_CHILD_SOURCE_ID = 9700002;
	private const SECTION_LEAF_SOURCE_ID  = 9700003;

	/**
	 * Base for the synthetic source term IDs stamped on the flat category and
	 * post_tag records, offset per taxonomy so the two never collide.
	 */
	private const FLAT_TERM_SOURCE_ID_BASE = 9700100;

	/**
	 * Source REST bodies keyed by source post ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $source_rest_bodies = array();

	/**
	 * Source post ID => list of image references generated for that post. Each
	 * reference is `array{ id: int, url: string }`; the first entry is also the
	 * post's featured_media.
	 *
	 * @var array<int, list<array{id: int, url: string}>>
	 */
	public array $image_refs_by_source_id = array();

	/**
	 * Attachment references seeded inside gallery/playlist shortcodes, as
	 * `array{ id: int, url: string }`. Kept apart from image_refs_by_source_id so
	 * the inline-image parity assertions never treat them as post images.
	 *
	 * @var list<array{id: int, url: string}>
	 */
	public array $shortcode_media_refs = array();

	/**
	 * Source post ID => destination post ID after the import.
	 *
	 * @var array<int, int>
	 */
	public array $dest_post_ids = array();

	/**
	 * Source post ID => import warnings returned for that post. An empty list
	 * means the import raised no warnings.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	public array $warnings_by_source_id = array();

	/**
	 * Source media ID => media REST body served for featured-image resolution.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $source_media_bodies = array();

	/**
	 * Source post ID => REST endpoint ('posts', 'pages') the importer fetches
	 * and resolves the destination post type from.
	 *
	 * @var array<int, string>
	 */
	private array $endpoint_by_source_id = array();

	/**
	 * Constructor.
	 *
	 * @param string                                                                                                                                               $source_base_url Source site URL.
	 * @param int                                                                                                                                                  $reference_time  Unix timestamp used as "now" for date math.
	 * @param int                                                                                                                                                  $media_id_base   Source media IDs start one past this value.
	 * @param int                                                                                                                                                  $admin_user_id   User the import runs as; owns sideloaded media.
	 * @param list<array{type: string, endpoint: string, count: int, source_id_base: int, assign_terms: bool, author_user_id: int, parent_links: array<int, int>}> $slices One descriptor per post-type slice in the batch. parent_links maps a child's 1-based slice index to its parent's.
	 * @param list<array{kind: string, endpoint: string, source_id: int, author_user_id: int, media_ids?: list<int>}>                                              $edge_cases One descriptor per bespoke edge-case body ('non_ascii', 'empty', 'embed', 'footnotes', 'reusable_block', 'gallery_shortcode', 'playlist_shortcode'); each seeds a single top-level post. The shortcode kinds sideload the media_ids they reference; the rest are image-free.
	 */
	public function __construct(
		private string $source_base_url,
		private int $reference_time,
		private int $media_id_base,
		private int $admin_user_id,
		private array $slices,
		private array $edge_cases = array()
	) {}

	/**
	 * Builds the source batch and imports it. Populates the public state the
	 * parity tests read.
	 */
	public function seed(): void {
		$this->build_source_bodies();
		$this->build_edge_case_bodies();
		$this->import_batch();
	}

	/**
	 * Deletes the imported posts, attachments (with their files), and seeder
	 * terms committed by seed(). Called from wpTearDownAfterClass().
	 */
	public function cleanup(): void {
		foreach ( $this->dest_post_ids as $dest_id ) {
			wp_delete_post( $dest_id, true );
		}

		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					array(
						'key'   => Options::META_IMPORTED_FROM,
						'value' => $this->source_base_url,
					),
				),
				'posts_per_page'   => -1,
				'suppress_filters' => false,
			)
		);

		foreach ( $attachments as $attachment ) {
			wp_delete_attachment( $attachment->ID, true );
		}

		foreach ( Content_Generator::term_config() as $taxonomy => $config ) {
			foreach ( $config['terms'] as $value ) {
				$term = get_term_by( $config['field'], $value, $taxonomy );
				if ( $term instanceof WP_Term ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}
		}

		$section_terms = get_terms(
			array(
				'taxonomy'   => self::SECTION_TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_array( $section_terms ) ) {
			foreach ( $section_terms as $term ) {
				wp_delete_term( $term->term_id, self::SECTION_TAXONOMY );
			}
		}
	}

	/**
	 * Returns the source library metadata (alt, title, caption, description)
	 * mocked for a media ID, so parity tests can value-check what propagates.
	 *
	 * @param int $source_media_id Source media ID.
	 * @return array{alt: string, title: string, caption: string, description: string}
	 */
	public function source_media_metadata( int $source_media_id ): array {
		return $this->media_metadata_for_id( $source_media_id );
	}

	/**
	 * Returns the pre-built source REST body for the post mock. Falls back to
	 * null so unregistered IDs surface as a WP_Error.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		return $this->source_rest_bodies[ $source_id ] ?? null;
	}

	/**
	 * Returns the pre-built media REST body for the media mock. Falls back to
	 * null so unregistered IDs surface as a WP_Error.
	 *
	 * @param int $source_media_id Source media ID from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_media_id( int $source_media_id ): ?array {
		return $this->source_media_bodies[ $source_media_id ] ?? null;
	}

	/**
	 * Builds the source REST and media bodies for every slice via
	 * Content_Generator. Media IDs run continuously across slices so they never
	 * collide; the per-slice source_id_base keeps post IDs distinct.
	 */
	private function build_source_bodies(): void {
		$next_img_id = $this->media_id_base + 1;

		foreach ( $this->slices as $slice ) {
			$generator = new Content_Generator(
				$slice['type'],
				'mixed',
				'auto',
				$slice['count'],
				1,
				0,
				'',
				$this->reference_time,
				$this->source_base_url
			);

			for ( $i = 1; $i <= $slice['count']; $i++ ) {
				$image_count = '1' === $generator->resolve_image_mode( $i ) ? 1 : 2;
				$image_refs  = array();
				for ( $j = 0; $j < $image_count; $j++ ) {
					$image_refs[] = array(
						'id'  => $next_img_id,
						'url' => $this->source_image_url( $next_img_id ),
					);
					++$next_img_id;
				}

				$source_id        = $slice['source_id_base'] + $i;
				$source_parent_id = isset( $slice['parent_links'][ $i ] )
					? $slice['source_id_base'] + $slice['parent_links'][ $i ]
					: 0;
				$payload          = $generator->generate( $i, $image_refs );

				if ( ! $slice['assign_terms'] ) {
					$payload['terms'] = array();
				}

				$this->image_refs_by_source_id[ $source_id ] = $image_refs;
				$this->endpoint_by_source_id[ $source_id ]   = $slice['endpoint'];
				$this->source_rest_bodies[ $source_id ]      = $this->payload_to_rest_body(
					$source_id,
					$payload,
					$slice['author_user_id'],
					$source_parent_id,
					$this->scalars_for_index( $i ),
					$image_refs,
					'post' === $slice['type']
				);
			}
		}

		$this->source_media_bodies = $this->build_source_media_bodies();
	}

	/**
	 * Returns the status-family scalars for a 1-based slice index. Even indices
	 * get non-default values so the batch exercises both the WordPress defaults
	 * and propagated non-defaults under one import.
	 *
	 * @param int $index Post index within its slice (1-based).
	 * @return array{comment_status: string, ping_status: string, menu_order: int, password: string}
	 */
	private function scalars_for_index( int $index ): array {
		if ( 0 !== $index % 2 ) {
			return $this->default_scalars();
		}

		return array(
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'menu_order'     => $index,
			'password'       => self::NON_DEFAULT_PASSWORD,
		);
	}

	/**
	 * Returns the WordPress-default status-family scalars.
	 *
	 * @return array{comment_status: string, ping_status: string, menu_order: int, password: string}
	 */
	private function default_scalars(): array {
		return array(
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'menu_order'     => 0,
			'password'       => '',
		);
	}

	/**
	 * Builds the bespoke edge-case bodies and registers them alongside the
	 * generator-driven batch. Each is a single top-level post on default
	 * scalars, exercising parity the deterministic generator never emits:
	 * multibyte/entity encoding, empty content, an external embed url's verbatim
	 * preservation, footnotes meta round-tripping, a reusable block whose target
	 * is not imported (a retryable unmapped reference), and gallery/playlist
	 * shortcodes whose bare source attachment IDs are sideloaded and rewritten.
	 */
	private function build_edge_case_bodies(): void {
		foreach ( $this->edge_cases as $edge ) {
			$source_id = $edge['source_id'];
			$media_ids = $edge['media_ids'] ?? array();

			$this->register_shortcode_media_bodies( $media_ids );

			$this->endpoint_by_source_id[ $source_id ] = $edge['endpoint'];
			$this->source_rest_bodies[ $source_id ]    = $this->payload_to_rest_body(
				$source_id,
				$this->edge_case_payload( $edge['kind'], $edge['endpoint'], $media_ids ),
				$edge['author_user_id'],
				0,
				$this->default_scalars()
			);
		}
	}

	/**
	 * Registers a wp/v2/media/{id} mock body and records a source ref for each
	 * attachment ID a gallery/playlist shortcode references, so
	 * import_source_media_by_id() resolves it to a downloadable source_url the
	 * byte mock serves.
	 *
	 * @param int[] $media_ids Source attachment IDs referenced by a shortcode.
	 */
	private function register_shortcode_media_bodies( array $media_ids ): void {
		foreach ( $media_ids as $media_id ) {
			$url = $this->source_image_url( $media_id );

			$this->source_media_bodies[ $media_id ] = array(
				'id'         => $media_id,
				'source_url' => $url,
				'media_type' => 'image',
				'mime_type'  => 'image/jpeg',
				'alt_text'   => '',
			);
			$this->shortcode_media_refs[]           = array(
				'id'  => $media_id,
				'url' => $url,
			);
		}
	}

	/**
	 * Builds the generator-shaped payload for an edge-case kind.
	 *
	 * The 'non_ascii' body carries accented Latin and CJK in the title plus the
	 * full multibyte set (including an emoji) and unescaped entities in the
	 * content; its slug is non-ASCII so the asserter checks sanitize_title()
	 * parity, while its link stays ASCII so META_SOURCE_LINK round-trips. The
	 * 'empty' body has empty content to exercise the empty-body path. The
	 * 'embed' body carries a core/embed block whose url is on an external
	 * provider host, exercising the importer's no-rewrite contract for embeds.
	 * The 'footnotes' body pairs a core/footnotes block with a matching
	 * footnotes meta JSON, exercising verbatim propagation of WordPress'
	 * footnotes meta (a JSON-encoded string, not an array). The 'reusable_block'
	 * body carries a core/block whose ref names a source wp_block this batch does
	 * not import, exercising the retryable unmapped-reference degradation. The
	 * 'gallery_shortcode' body spreads media_ids across a classic [gallery]'s
	 * ids, include, and exclude attributes and 'playlist_shortcode' carries them
	 * in a [playlist]'s ids, exercising the rewriter end-to-end for every
	 * id-bearing attribute.
	 *
	 * @param string $kind      Edge-case kind: 'non_ascii', 'empty', 'embed',
	 *                          'footnotes', 'reusable_block', 'gallery_shortcode',
	 *                          or 'playlist_shortcode'.
	 * @param string $endpoint  REST endpoint the body is served from.
	 * @param int[]  $media_ids Source attachment IDs for the shortcode kinds.
	 * @return array<string, mixed> Generator-shaped payload.
	 */
	private function edge_case_payload(
		string $kind,
		string $endpoint,
		array $media_ids = array()
	): array {
		$post_type = 'pages' === $endpoint ? 'page' : 'post';
		$base      = array(
			'post_type'      => $post_type,
			'status'         => 'publish',
			'date'           => gmdate( 'Y-m-d H:i:s', $this->reference_time ),
			'meta'           => array(),
			'terms'          => array(),
			'featured_media' => 0,
		);

		if ( 'empty' === $kind ) {
			return $base + array(
				'title'   => 'Edge case empty content',
				'slug'    => 'edge-empty-content',
				'link'    => $this->source_base_url . '/edge-empty-content',
				'content' => '',
				'excerpt' => 'Excerpt for the empty-content edge case.',
			);
		}

		if ( 'embed' === $kind ) {
			$provider_url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

			return $base + array(
				'title'   => 'Edge case embed block',
				'slug'    => 'edge-embed-block',
				'link'    => $this->source_base_url . '/edge-embed-block',
				'content' => '<!-- wp:embed {"url":"' . $provider_url
					. '","type":"video","providerNameSlug":"youtube",'
					. '"responsive":true,"className":'
					. '"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->' . "\n"
					. '<figure class="wp-block-embed is-type-video '
					. 'is-provider-youtube wp-block-embed-youtube '
					. 'wp-embed-aspect-16-9 wp-has-aspect-ratio">'
					. '<div class="wp-block-embed__wrapper">' . "\n"
					. $provider_url . "\n"
					. '</div></figure>' . "\n"
					. '<!-- /wp:embed -->',
				'excerpt' => 'Excerpt for the embed edge case.',
			);
		}

		if ( 'reusable_block' === $kind ) {
			return $base + array(
				'title'   => 'Edge case reusable block',
				'slug'    => 'edge-reusable-block',
				'link'    => $this->source_base_url . '/edge-reusable-block',
				'content' => '<!-- wp:block {"ref":'
					. self::REUSABLE_BLOCK_SOURCE_REF . '} /-->',
				'excerpt' => 'Excerpt for the reusable-block edge case.',
			);
		}

		if ( 'footnotes' === $kind ) {
			$anchor    = self::FOOTNOTE_ANCHOR_ID;
			$footnotes = (string) wp_json_encode(
				array(
					array(
						'content' => 'Footnote one explains the cited claim.',
						'id'      => $anchor,
					),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);

			// array_merge (not +) so the footnotes meta replaces $base's empty
			// meta; + keeps the left-hand value on a key collision.
			return array_merge(
				$base,
				array(
					'title'   => 'Edge case footnotes',
					'slug'    => 'edge-footnotes',
					'link'    => $this->source_base_url . '/edge-footnotes',
					'content' => "<!-- wp:paragraph -->\n"
						. '<p>Body text with a footnote.<sup data-fn="'
						. $anchor . '" class="fn"><a href="#' . $anchor
						. '" id="' . $anchor . '-link">1</a></sup></p>' . "\n"
						. "<!-- /wp:paragraph -->\n\n"
						. '<!-- wp:footnotes /-->',
					'excerpt' => 'Excerpt for the footnotes edge case.',
					'meta'    => array( 'footnotes' => $footnotes ),
				)
			);
		}

		if ( 'gallery_shortcode' === $kind ) {
			// Spread media_ids across ids, include, and exclude so all three
			// id-bearing attributes are rewritten; array_slice tolerates a short
			// list, leaving an attribute's CSV empty.
			return $base + array(
				'title'   => 'Edge case gallery shortcode',
				'slug'    => 'edge-gallery-shortcode',
				'link'    => $this->source_base_url . '/edge-gallery-shortcode',
				'content' => sprintf(
					'[gallery ids="%s" include="%s" exclude="%s" columns="3" link="file"]',
					implode( ',', array_slice( $media_ids, 0, 2 ) ),
					implode( ',', array_slice( $media_ids, 2, 1 ) ),
					implode( ',', array_slice( $media_ids, 3, 1 ) )
				),
				'excerpt' => 'Excerpt for the gallery-shortcode edge case.',
			);
		}

		if ( 'playlist_shortcode' === $kind ) {
			return $base + array(
				'title'   => 'Edge case playlist shortcode',
				'slug'    => 'edge-playlist-shortcode',
				'link'    => $this->source_base_url . '/edge-playlist-shortcode',
				'content' => sprintf(
					'[playlist type="audio" ids="%s"]',
					implode( ',', $media_ids )
				),
				'excerpt' => 'Excerpt for the playlist-shortcode edge case.',
			);
		}

		return $base + array(
			'title'   => "Café \u{65e5}\u{672c}\u{8a9e} Tîtle &amp; Sübtitle &mdash; Fin",
			'slug'    => "café-\u{65e5}\u{672c}\u{8a9e}-slug",
			'link'    => $this->source_base_url . '/edge-non-ascii',
			'content' => "<!-- wp:paragraph -->\n"
				. "<p>Café \u{65e5}\u{672c}\u{8a9e} \u{1f389} \u{2014} A &amp; B"
				. " &mdash; C</p>\n"
				. '<!-- /wp:paragraph -->',
			'excerpt' => 'Résumé café — éxcerpt.',
		);
	}

	/**
	 * Registers the HTTP mocks and imports the batch one post at a time through
	 * Post_Import_Service. Passes no session id, so the import skips history
	 * logging — irrelevant to parity and tested elsewhere. Throws on the first
	 * failure so a broken fixture fails the whole class loudly instead of
	 * leaving later assertions to pass on an empty batch.
	 *
	 * @throws RuntimeException When any post fails to import.
	 */
	private function import_batch(): void {
		wp_set_current_user( $this->admin_user_id );

		$this->add_per_source_id_post_api_mock();
		$this->add_per_source_id_media_api_mock();
		$this->add_image_byte_response_mock();

		try {
			$service = $this->build_import_service();

			foreach ( $this->source_rest_bodies as $source_id => $body ) {
				$result = $service->import_post(
					array(
						'id'        => $source_id,
						'title'     => $body['title']['raw'],
						'link'      => $body['link'],
						'post_type' => $this->endpoint_by_source_id[ $source_id ],
					)
				);

				if ( true !== ( $result['success'] ?? false ) ) {
					throw new RuntimeException(
						"Import failed for source ID {$source_id}: "
						. (string) ( $result['error'] ?? 'unknown error' )
					);
				}

				$this->dest_post_ids[ $source_id ]         = (int) $result['post_id'];
				$this->warnings_by_source_id[ $source_id ] = is_array( $result['warnings'] ?? null )
					? $result['warnings']
					: array();
			}
		} finally {
			$this->remove_image_byte_response_mock();
			$this->remove_per_source_id_media_api_mock();
			$this->remove_per_source_id_post_api_mock();
		}
	}

	/**
	 * Wires a Post_Import_Service with the same object graph the plugin builds
	 * for import mode.
	 */
	private function build_import_service(): Post_Import_Service {
		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		return new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			new History_Repository(),
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Builds the deterministic source-side URL for an image attachment.
	 *
	 * @param int $source_media_id Source media ID.
	 * @return string Absolute URL under the source uploads path.
	 */
	private function source_image_url( int $source_media_id ): string {
		return $this->source_base_url
			. '/wp-content/uploads/2025/01/seeded-image-'
			. $source_media_id . '.jpg';
	}

	/**
	 * Builds the wp/v2/media/{id} mock bodies (edit-context shape) for every
	 * image referenced in the batch, so the featured-image fetch resolves a
	 * source_url, the raw library fields, and the source parent post.
	 *
	 * @return array<int, array<string, mixed>> Media ID => REST body.
	 */
	private function build_source_media_bodies(): array {
		$bodies = array();

		foreach ( $this->image_refs_by_source_id as $owner_source_id => $refs ) {
			foreach ( $refs as $ref ) {
				$meta                 = $this->media_metadata_for_id( $ref['id'] );
				$bodies[ $ref['id'] ] = array(
					'id'          => $ref['id'],
					'source_url'  => $ref['url'],
					'media_type'  => 'image',
					'mime_type'   => 'image/jpeg',
					'alt_text'    => $meta['alt'],
					'title'       => array( 'raw' => $meta['title'] ),
					'caption'     => array( 'raw' => $meta['caption'] ),
					'description' => array( 'raw' => $meta['description'] ),
					// Source parent post; the by-id path reads it as `post`.
					'post'        => $owner_source_id,
				);
			}
		}

		return $bodies;
	}

	/**
	 * Returns the deterministic source library metadata for a media ID, the
	 * single source of truth behind both the media REST bodies and the
	 * safe_publish_media map. Each field carries a distinct tag so the importer's
	 * per-field sanitizers stay load-bearing (title/alt strip, caption and
	 * description keep the safe HTML wp_kses_post allows).
	 *
	 * @param int $source_media_id Source media ID.
	 * @return array{alt: string, title: string, caption: string, description: string}
	 */
	private function media_metadata_for_id( int $source_media_id ): array {
		return array(
			'alt'         => "Mock alt <b>text</b> for media {$source_media_id}",
			'title'       => "Mock <i>title</i> for media {$source_media_id}",
			'caption'     => "Mock <em>caption</em> for media {$source_media_id}",
			'description' => "Mock <strong>desc</strong> for media {$source_media_id}",
		);
	}

	/**
	 * Wraps a generator payload into a full REST response body.
	 *
	 * Mirrors the shape of a real wp/v2 post response: title/content/excerpt are
	 * wrapped in [ 'raw' => ... ], taxonomy assignments land under both
	 * _embedded['wp:term'] and the richer safe_publish_terms field, the
	 * plugin's safe_publish_author block is stamped with the slice's author,
	 * and parent carries the source parent ID (0 for top-level). The
	 * status-family scalars come from the caller so the batch exercises
	 * non-default comment/ping/password/menu_order values.
	 *
	 * @param int                                                                                   $source_id        Source post ID.
	 * @param array<string, mixed>                                                                  $payload          Generator payload.
	 * @param int                                                                                   $author_user_id   Dest user whose identity stamps safe_publish_author.
	 * @param int                                                                                   $source_parent_id Source parent post ID; 0 for top-level.
	 * @param array{comment_status: string, ping_status: string, menu_order: int, password: string} $scalars Status-family column values.
	 * @param list<array{id: int, url: string}>                                                     $image_refs Image refs seeding safe_publish_media; empty for edge cases.
	 * @param bool                                                                                  $include_hierarchy Whether to append the hierarchical section tree.
	 * @return array<string, mixed>
	 */
	private function payload_to_rest_body(
		int $source_id,
		array $payload,
		int $author_user_id,
		int $source_parent_id,
		array $scalars,
		array $image_refs = array(),
		bool $include_hierarchy = false
	): array {
		$author = get_userdata( $author_user_id );

		$safe_publish_media = array();
		foreach ( $image_refs as $ref ) {
			// The owning post is this body's own source id, so the destination
			// re-parents each inline image to it.
			$safe_publish_media[ $ref['url'] ] = $this->media_metadata_for_id(
				$ref['id']
			) + array( 'parent' => (string) $source_id );
		}

		$body = array(
			'id'                  => $source_id,
			'title'               => array( 'raw' => $payload['title'] ),
			'featured_media'      => $payload['featured_media'],
			'content'             => array( 'raw' => $payload['content'] ),
			'excerpt'             => array( 'raw' => $payload['excerpt'] ),
			'link'                => $payload['link'],
			// Synthetic source guid the importer ignores; lets the parity suite
			// assert the dest regenerates its own (DIVERGENCE_REGISTRY 'guid').
			'guid'                => $this->source_base_url . '/?p=' . $source_id,
			'slug'                => $payload['slug'],
			'type'                => $payload['post_type'],
			'status'              => $payload['status'],
			'date'                => $payload['date'],
			'date_gmt'            => $payload['date'],
			'comment_status'      => $scalars['comment_status'],
			'ping_status'         => $scalars['ping_status'],
			'menu_order'          => $scalars['menu_order'],
			'password'            => $scalars['password'],
			'parent'              => $source_parent_id,
			'meta'                => $payload['meta'],
			'safe_publish_author' => array(
				'email'        => false !== $author ? (string) $author->user_email : '',
				'login'        => false !== $author ? (string) $author->user_login : '',
				'display_name' => false !== $author ? (string) $author->display_name : '',
			),
			'safe_publish_media'  => $safe_publish_media,
			'_embedded'           => array(
				'wp:term' => $this->embedded_terms( $payload['terms'] ),
			),
		);

		// Omit the field when there is nothing to carry so those posts exercise
		// the embedded-terms fallback, as a source on the older plugin would.
		$safe_publish_terms = $this->build_safe_publish_terms(
			$payload['terms'],
			$include_hierarchy
		);
		if ( array() !== $safe_publish_terms ) {
			$body['safe_publish_terms'] = $safe_publish_terms;
		}

		return $body;
	}

	/**
	 * Builds the safe_publish_terms field for a body: each flat
	 * category/post_tag assignment as an assigned, described record, plus the
	 * hierarchical section tree when requested.
	 *
	 * @param array<string, list<string>> $term_assignments  Taxonomy => term values.
	 * @param bool                        $include_hierarchy Whether to append the section tree.
	 * @return array<string, list<array{id: int, name: string, slug: string, parent: int, description: string, assigned: bool}>>
	 */
	private function build_safe_publish_terms(
		array $term_assignments,
		bool $include_hierarchy
	): array {
		$field = array();

		foreach ( $term_assignments as $taxonomy => $values ) {
			$records = array();
			foreach ( $values as $value ) {
				$records[] = array(
					'id'          => $this->flat_term_source_id( $taxonomy, $value ),
					'name'        => $value,
					'slug'        => sanitize_title( $value ),
					'parent'      => 0,
					'description' => "Term description for {$value}",
					'assigned'    => true,
				);
			}
			if ( array() !== $records ) {
				$field[ $taxonomy ] = $records;
			}
		}

		if ( $include_hierarchy ) {
			$field[ self::SECTION_TAXONOMY ] = $this->section_tree_records();
		}

		return $field;
	}

	/**
	 * Returns a stable source term ID for a flat term value, keyed on its
	 * position in term_config() so the same term reuses one destination term
	 * across the batch.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $value    Term value (name or slug per term_config()).
	 * @return int Synthetic source term ID.
	 */
	private function flat_term_source_id( string $taxonomy, string $value ): int {
		$terms  = Content_Generator::term_config()[ $taxonomy ]['terms'] ?? array();
		$index  = array_search( $value, $terms, true );
		$offset = 'category' === $taxonomy ? 0 : 100;

		return self::FLAT_TERM_SOURCE_ID_BASE
			+ $offset
			+ ( false === $index ? 0 : (int) $index )
			+ 1;
	}

	/**
	 * Returns the three-level section tree: a root, a child under it, and an
	 * assigned leaf under the child, each carrying a description.
	 *
	 * @return list<array{id: int, name: string, slug: string, parent: int, description: string, assigned: bool}>
	 */
	private function section_tree_records(): array {
		return array(
			array(
				'id'          => self::SECTION_ROOT_SOURCE_ID,
				'name'        => 'Root Section',
				'slug'        => 'root-section',
				'parent'      => 0,
				'description' => 'The top-level section.',
				'assigned'    => false,
			),
			array(
				'id'          => self::SECTION_CHILD_SOURCE_ID,
				'name'        => 'Child Section',
				'slug'        => 'child-section',
				'parent'      => self::SECTION_ROOT_SOURCE_ID,
				'description' => 'A mid-level section under the root.',
				'assigned'    => false,
			),
			array(
				'id'          => self::SECTION_LEAF_SOURCE_ID,
				'name'        => 'Leaf Section',
				'slug'        => 'leaf-section',
				'parent'      => self::SECTION_CHILD_SOURCE_ID,
				'description' => 'The assigned leaf section.',
				'assigned'    => true,
			),
		);
	}

	/**
	 * Converts taxonomy => term-name lists into the _embedded['wp:term'] shape
	 * the import code expects.
	 *
	 * @param array<string, list<string>> $terms Taxonomy => term names.
	 * @return list<list<array{taxonomy: string, name: string}>>
	 */
	private function embedded_terms( array $terms ): array {
		$groups = array();

		foreach ( $terms as $taxonomy => $term_names ) {
			$group = array();
			foreach ( $term_names as $name ) {
				$group[] = array(
					'taxonomy' => $taxonomy,
					'name'     => $name,
				);
			}
			$groups[] = $group;
		}

		return $groups;
	}
}
