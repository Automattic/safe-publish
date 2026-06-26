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
	 */
	public function __construct(
		private string $source_base_url,
		private int $reference_time,
		private int $media_id_base,
		private int $admin_user_id,
		private array $slices
	) {}

	/**
	 * Builds the source batch and imports it. Populates the public state the
	 * parity tests read.
	 */
	public function seed(): void {
		$this->build_source_bodies();
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
					$source_parent_id
				);
			}
		}

		$this->source_media_bodies = $this->build_source_media_bodies();
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

				$this->dest_post_ids[ $source_id ] = (int) $result['post_id'];
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
	 * top-level).
	 *
	 * @param int                  $source_id        Source post ID.
	 * @param array<string, mixed> $payload          Generator payload.
	 * @param int                  $author_user_id   Dest user whose identity stamps safe_publish_author.
	 * @param int                  $source_parent_id Source parent post ID; 0 for top-level.
	 * @return array<string, mixed>
	 */
	private function payload_to_rest_body(
		int $source_id,
		array $payload,
		int $author_user_id,
		int $source_parent_id
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
			'comment_status'      => 'open',
			'ping_status'         => 'open',
			'menu_order'          => 0,
			'password'            => '',
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
