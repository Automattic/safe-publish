<?php
/**
 * Navigation Ref Rewriter class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Options;
use WP_Post;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repoints stale core/navigation block references once a menu is imported.
 *
 * When a post is imported before the navigation menu it embeds via
 * <!-- wp:navigation {"ref":N} -->, the import-time remap cannot resolve N
 * (the menu is not on the destination yet), so it leaves the source ID in
 * place. Once the menu lands, this rewriter finds those posts and repoints
 * their ref to the menu's destination ID. The same trigger resolves in-batch
 * inter-nav references when one menu is imported before another it embeds.
 *
 * Writes post_content directly via $wpdb instead of wp_update_post so the
 * reconciliation neither bumps post_modified nor creates a revision: It is a
 * system-touched correction, not a user edit. The per-post audit meta is the
 * only trace of the change precisely because no revision is created — do not
 * switch to wp_update_post without revisiting that trade-off.
 *
 * Candidate discovery runs a post_content LIKE scan synchronously during the
 * menu import. Acceptable for the typical case, where few posts embed a menu
 * by hard-coded ref; move to a queued job if profiling shows pressure on
 * sites with very large post tables.
 */
class Navigation_Ref_Rewriter {

	/**
	 * Post meta key recording the unix timestamp at which a post's stale
	 * navigation ref was rewritten.
	 *
	 * @var string
	 */
	public const META_REWRITTEN_AT = '_safe_publish_nav_ref_rewritten_at';

	/**
	 * Repoints destination posts that reference a freshly imported menu by its
	 * source ID to the menu's destination ID.
	 *
	 * Refuses to act when the source site URL is empty: Without it the
	 * candidate query cannot scope to the originating site, and an unscoped
	 * rewrite could corrupt refs on posts imported from a different source
	 * that happen to share the numeric ID.
	 *
	 * @param int    $source_nav_id   Menu's source post ID.
	 * @param int    $dest_nav_id     Menu's destination post ID.
	 * @param string $source_site_url Source site URL the posts were imported
	 *                                from; '' opts out and rewrites nothing.
	 * @return array{rewritten:int, failed:list<int>, changes:list<array{post_id:int, blocks:list<array{after_block_hash:string, paths:list<list<int>>}>}>, undo:list<array{post_id:int, previous_content:string, after_content:string}>} Reconcile result and compact persisted effects.
	 */
	public function rewrite_cross_refs(
		int $source_nav_id,
		int $dest_nav_id,
		string $source_site_url
	): array {
		if ( '' === $source_site_url ) {
			return array(
				'rewritten' => 0,
				'failed'    => array(),
				'changes'   => array(),
				'undo'      => array(),
			);
		}

		$rewritten = 0;
		$failed    = array();
		$changes   = array();
		$undo      = array();

		$candidate_ids = $this->find_candidate_post_ids(
			$source_nav_id,
			$source_site_url
		);

		foreach ( $candidate_ids as $post_id ) {
			$result = $this->rewrite_post_content(
				$post_id,
				$source_nav_id,
				$dest_nav_id
			);

			if ( 'rewritten' === $result['status'] ) {
				++$rewritten;

				if ( $post_id !== $dest_nav_id ) {
					$changes[] = array(
						'post_id' => $post_id,
						'blocks'  => $result['blocks'],
					);
					$undo[]    = array(
						'post_id'          => $post_id,
						'previous_content' => $result['previous_content'],
						'after_content'    => $result['after_content'],
					);
				}
			} elseif ( 'failed' === $result['status'] ) {
				$failed[] = $post_id;
			}
		}

		return array(
			'rewritten' => $rewritten,
			'failed'    => $failed,
			'changes'   => $changes,
			'undo'      => $undo,
		);
	}

	/**
	 * Finds imported posts whose content references the given source menu ID.
	 *
	 * The LIKE prefilter is a coarse net: It also matches longer IDs sharing
	 * the prefix (42 matches 420) and ignores externally produced spacing
	 * variants such as {"ref": 42} or {"ref":"42"}. The exact match in the
	 * block walk discards over-matches, and spacing variants never reach it.
	 * The META_SOURCE_SITE_URL join both scopes to the right source and
	 * excludes revisions and autosaves, which never carry the meta.
	 *
	 * @param int    $source_nav_id   Menu's source post ID.
	 * @param string $source_site_url Source site URL the posts were imported from.
	 * @return list<int> Candidate destination post IDs.
	 */
	private function find_candidate_post_ids(
		int $source_nav_id,
		string $source_site_url
	): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_content LIKE %s
					 AND pm.meta_key = %s
					 AND pm.meta_value = %s
					 AND p.post_status NOT IN ( 'auto-draft', 'trash' )",
				'%wp:navigation {%"ref":' . absint( $source_nav_id ) . '%',
				Options::META_SOURCE_SITE_URL,
				$source_site_url
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * Rewrites a single post's stale menu refs and persists the result.
	 *
	 * @param int $post_id       Destination post ID.
	 * @param int $source_nav_id Menu's source post ID to match.
	 * @param int $dest_nav_id   Menu's destination post ID to write.
	 * @return array{status:string, blocks:list<array{after_block_hash:string, paths:list<list<int>>>}, previous_content:string, after_content:string} Rewrite result.
	 */
	private function rewrite_post_content(
		int $post_id,
		int $source_nav_id,
		int $dest_nav_id
	): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || '' === $post->post_content ) {
			return $this->rewrite_result( 'unchanged' );
		}

		$blocks         = parse_blocks( $post->post_content );
		$changed        = false;
		$changed_blocks = array();
		$blocks         = $this->walk_blocks(
			$blocks,
			$source_nav_id,
			$dest_nav_id,
			$changed,
			array(),
			$changed_blocks
		);

		if ( ! $changed ) {
			return $this->rewrite_result( 'unchanged' );
		}

		$after_content = serialize_blocks( $blocks );
		$persisted     = $this->persist_rewritten_content(
			$post_id,
			$after_content
		);

		if ( ! $persisted ) {
			return $this->rewrite_result( 'failed' );
		}

		clean_post_cache( $post_id );
		update_post_meta( $post_id, self::META_REWRITTEN_AT, time() );

		return array(
			'status'           => 'rewritten',
			'blocks'           => $this->group_changed_blocks( $changed_blocks ),
			'previous_content' => $post->post_content,
			'after_content'    => $after_content,
		);
	}

	/**
	 * Builds a no-change or failure result.
	 *
	 * @param string $status Rewrite status.
	 * @return array{status:string, blocks:list<array{after_block_hash:string, paths:list<list<int>>>}, previous_content:string, after_content:string}
	 */
	private function rewrite_result( string $status ): array {
		return array(
			'status'           => $status,
			'blocks'           => array(),
			'previous_content' => '',
			'after_content'    => '',
		);
	}

	/**
	 * Recursively repoints core/navigation refs matching the source menu ID.
	 *
	 * @param array<array<string, mixed>>              $blocks        Parsed block tree.
	 * @param int                                      $source_nav_id Source menu ID to match.
	 * @param int                                      $dest_nav_id   Destination menu ID to write.
	 * @param bool                                     $changed       Set to true, by reference,
	 *                                                                when a ref is repointed.
	 * @param array                                    $path          Current block path.
	 * @param list<array{path:list<int>, hash:string}> $changed_blocks Changed blocks.
	 * @return array<array<string, mixed>> Mutated block tree.
	 */
	private function walk_blocks(
		array $blocks,
		int $source_nav_id,
		int $dest_nav_id,
		bool &$changed,
		array $path,
		array &$changed_blocks
	): array {
		foreach ( $blocks as $i => $block ) {
			$current_path = array_merge( $path, array( $i ) );
			$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] )
				? $block['attrs']
				: array();
			$ref          = $attrs['ref'] ?? null;
			$repointed    = false;

			if (
				'core/navigation' === ( $block['blockName'] ?? '' )
				&& is_numeric( $ref )
				&& (int) $ref === $source_nav_id
			) {
				$attrs['ref']          = $dest_nav_id;
				$blocks[ $i ]['attrs'] = $attrs;
				$changed               = true;
				$repointed             = true;
			}

			if (
				isset( $block['innerBlocks'] )
				&& is_array( $block['innerBlocks'] )
				&& array() !== $block['innerBlocks']
			) {
				$blocks[ $i ]['innerBlocks'] = $this->walk_blocks(
					$block['innerBlocks'],
					$source_nav_id,
					$dest_nav_id,
					$changed,
					$current_path,
					$changed_blocks
				);
			}

			if ( $repointed ) {
				$changed_blocks[] = array(
					'path' => $current_path,
					'hash' => self::compact_hash( serialize_block( $blocks[ $i ] ) ),
				);
			}
		}

		return $blocks;
	}

	/**
	 * Groups paths whose rewritten blocks have identical persisted content.
	 *
	 * @param list<array{path:list<int>, hash:string}> $changed_blocks Changed blocks.
	 * @return list<array{after_block_hash:string, paths:list<list<int>>}>
	 */
	private function group_changed_blocks( array $changed_blocks ): array {
		$groups = array();

		foreach ( $changed_blocks as $block ) {
			$groups[ $block['hash'] ][] = $block['path'];
		}

		$result = array();
		foreach ( $groups as $hash => $paths ) {
			$result[] = array(
				'after_block_hash' => $hash,
				'paths'            => $paths,
			);
		}

		return $result;
	}

	/**
	 * Returns an unpadded Base64URL SHA-256 digest.
	 *
	 * @param string $value Value to hash.
	 * @return string Compact digest.
	 */
	private static function compact_hash( string $value ): string {
		return rtrim(
			strtr( base64_encode( hash( 'sha256', $value, true ) ), '+/', '-_' ),
			'='
		);
	}

	/**
	 * Restores content held in an in-memory compensation list.
	 *
	 * Used only when import history cannot be persisted in the same request.
	 *
	 * @param list<array{post_id:int, previous_content:string, after_content:string}> $undo Previous and written contents.
	 * @return bool True when every surviving post was restored.
	 */
	public function restore_rewrites( array $undo ): bool {
		$restored = true;

		foreach ( $undo as $item ) {
			$persisted = $this->restore_rewritten_content( $item );

			if ( ! $persisted ) {
				$restored = false;
				continue;
			}

			clean_post_cache( $item['post_id'] );
		}

		return $restored;
	}

	/**
	 * Restores a rewrite only when its imported content is still current.
	 *
	 * @param array{post_id:int, previous_content:string, after_content:string} $item Rewrite undo data.
	 */
	private function restore_rewritten_content( array $item ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d AND BINARY post_content = %s",
				$item['previous_content'],
				$item['post_id'],
				$item['after_content']
			)
		);

		if ( 1 === $result ) {
			return true;
		}

		clean_post_cache( $item['post_id'] );
		$post = get_post( $item['post_id'] );

		return $post instanceof WP_Post
			&& $item['previous_content'] === $post->post_content;
	}

	/**
	 * Persists the rewritten content via a direct write, bypassing
	 * wp_update_post. Isolated so tests can force a write failure.
	 *
	 * @param int    $post_id     Destination post ID.
	 * @param string $new_content Serialized block content to write.
	 * @return bool True when the row was updated.
	 */
	protected function persist_rewritten_content(
		int $post_id,
		string $new_content
	): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $new_content ),
			array( 'ID' => $post_id )
		);

		return false !== $result;
	}
}
