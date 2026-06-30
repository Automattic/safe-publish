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
	 * The plugin never imports wp_block, so this reference dangles by design and
	 * the import surfaces it as a degradation.
	 */
	public const REUSABLE_BLOCK_SOURCE_REF = 9300001;

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
	 * @param list<array{kind: string, endpoint: string, source_id: int, author_user_id: int}>                                                                     $edge_cases One descriptor per bespoke edge-case body ('non_ascii', 'empty', 'embed', 'footnotes', 'reusable_block'); each seeds a single top-level, image-free post.
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
					$this->scalars_for_index( $i )
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
	 * generator-driven batch. Each is a single top-level, image-free post on
	 * default scalars, exercising parity the deterministic generator never
	 * emits: multibyte/entity encoding, empty content, an external embed url's
	 * verbatim preservation, footnotes meta round-tripping, and an unmigratable
	 * reusable block surfacing as a degradation.
	 */
	private function build_edge_case_bodies(): void {
		foreach ( $this->edge_cases as $edge ) {
			$source_id = $edge['source_id'];

			$this->endpoint_by_source_id[ $source_id ] = $edge['endpoint'];
			$this->source_rest_bodies[ $source_id ]    = $this->payload_to_rest_body(
				$source_id,
				$this->edge_case_payload( $edge['kind'], $edge['endpoint'] ),
				$edge['author_user_id'],
				0,
				$this->default_scalars()
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
	 * body carries a core/block whose ref names a source wp_block the plugin
	 * never imports, exercising the unmigratable-reusable-block degradation.
	 *
	 * @param string $kind     Edge-case kind: 'non_ascii', 'empty', 'embed',
	 *                         'footnotes', or 'reusable_block'.
	 * @param string $endpoint REST endpoint the body is served from.
	 * @return array<string, mixed> Generator-shaped payload.
	 */
	private function edge_case_payload( string $kind, string $endpoint ): array {
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
	 * Builds the wp/v2/media/{id} mock bodies for every image referenced in the
	 * batch. The plugin only reads source_url today; alt_text, title, and
	 * caption are included so future propagation work surfaces against a
	 * non-empty source without having to reseed.
	 *
	 * @return array<int, array<string, mixed>> Media ID => REST body.
	 */
	private function build_source_media_bodies(): array {
		$bodies = array();

		foreach ( $this->image_refs_by_source_id as $refs ) {
			foreach ( $refs as $ref ) {
				$bodies[ $ref['id'] ] = array(
					'id'         => $ref['id'],
					'source_url' => $ref['url'],
					'media_type' => 'image',
					'mime_type'  => 'image/jpeg',
					'alt_text'   => "Mock alt text for media {$ref['id']}",
					'title'      => array(
						'raw' => "Mock title for media {$ref['id']}",
					),
					'caption'    => array(
						'raw' => "Mock caption for media {$ref['id']}",
					),
				);
			}
		}

		return $bodies;
	}

	/**
	 * Wraps a generator payload into a full REST response body.
	 *
	 * Mirrors the shape of a real wp/v2 post response: title/content/excerpt are
	 * wrapped in [ 'raw' => ... ], taxonomy assignments land under
	 * _embedded['wp:term'], the plugin's safe_publish_author block is stamped
	 * with the slice's author, and parent carries the source parent ID (0 for
	 * top-level). The status-family scalars come from the caller so the batch
	 * exercises non-default comment/ping/password/menu_order values.
	 *
	 * @param int                                                                                   $source_id        Source post ID.
	 * @param array<string, mixed>                                                                  $payload          Generator payload.
	 * @param int                                                                                   $author_user_id   Dest user whose identity stamps safe_publish_author.
	 * @param int                                                                                   $source_parent_id Source parent post ID; 0 for top-level.
	 * @param array{comment_status: string, ping_status: string, menu_order: int, password: string} $scalars Status-family column values.
	 * @return array<string, mixed>
	 */
	private function payload_to_rest_body(
		int $source_id,
		array $payload,
		int $author_user_id,
		int $source_parent_id,
		array $scalars
	): array {
		$author = get_userdata( $author_user_id );

		return array(
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
			'_embedded'           => array(
				'wp:term' => $this->embedded_terms( $payload['terms'] ),
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
