<?php
/**
 * Seeds import degradations so the admin "Needs attention" and "orphan
 * failures" drawers can be exercised in a local environment.
 *
 * Important: For all typical uses, run the bin/seed-degradations script. This
 * file shouldn't be invoked directly, unless you are already inside a WP-CLI
 * session with access to the target WordPress install. It runs in two steps,
 * each in a different wp-env container:
 *
 *   step=source   On the source site: upsert the demo pages (a parent/child
 *                 pair, a resolvable links page with its two targets, an
 *                 unresolvable links page, a reusable-block page, and
 *                 optionally a volume page when count>0). Prints page IDs for
 *                 the wrapper.
 *   step=import   On the destination site: import the child (orphan fallback
 *                 on) for a parent_orphaned issue, and the links and
 *                 reusable-block pages for unmapped_block_reference issues, run
 *                 a no-id import for an orphan failure, and stage a
 *                 nav_ref_rewrite_failed issue.
 *
 * Pass purge=1 with either step to remove that side's seeded artifacts.
 * Pass count=N (seed only) to add N filler entries per drawer for pagination.
 *
 * Not for production use.
 *
 * @package Safe_Publish
 */

declare( strict_types=1 );

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
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;

// Stable identities shared by both steps.
const SAFE_PUBLISH_DEMO_PARENT_SLUG         = 'sp-demo-orphan-parent';
const SAFE_PUBLISH_DEMO_CHILD_SLUG          = 'sp-demo-orphan-child';
const SAFE_PUBLISH_DEMO_PARENT_TITLE        = 'Orphan Demo Parent';
const SAFE_PUBLISH_DEMO_CHILD_TITLE         = 'Orphan Demo Child';
const SAFE_PUBLISH_DEMO_ORPHAN_TITLE        = 'Import with no source ID';
const SAFE_PUBLISH_DEMO_REFERENCES_SLUG     = 'sp-demo-unmapped-references';
const SAFE_PUBLISH_DEMO_REFERENCES_TITLE    = 'Unmapped References Demo';
const SAFE_PUBLISH_DEMO_TARGET_A_SLUG       = 'sp-demo-target-a';
const SAFE_PUBLISH_DEMO_TARGET_A_TITLE      = 'Unmapped Target A';
const SAFE_PUBLISH_DEMO_TARGET_B_SLUG       = 'sp-demo-target-b';
const SAFE_PUBLISH_DEMO_TARGET_B_TITLE      = 'Unmapped Target B';
const SAFE_PUBLISH_DEMO_UNRESOLVABLE_SLUG   = 'sp-demo-unresolvable-reference';
const SAFE_PUBLISH_DEMO_UNRESOLVABLE_TITLE  = 'Unresolvable Reference Demo';
const SAFE_PUBLISH_DEMO_REUSABLE_SLUG       = 'sp-demo-reusable-block';
const SAFE_PUBLISH_DEMO_REUSABLE_TITLE      = 'Reusable Block Demo';
const SAFE_PUBLISH_DEMO_REUSABLE_REF        = 930001;
const SAFE_PUBLISH_DEMO_VOLUME_SLUG         = 'sp-demo-volume';
const SAFE_PUBLISH_DEMO_VOLUME_TITLE        = 'Volume Demo';
const SAFE_PUBLISH_DEMO_VOLUME_ORPHAN_TITLE = 'Volume orphan failure';
const SAFE_PUBLISH_DEMO_NAV_REFERRER_TITLE  = 'Nav Referrer Demo';
const SAFE_PUBLISH_DEMO_NAV_MENU_TITLE      = 'Nav Menu Demo';
const SAFE_PUBLISH_DEMO_NAV_SOURCE_ID       = 920001;
const SAFE_PUBLISH_DEMO_ROLE_META           = '_safe_publish_demo_role';

/**
 * Parses key=value positional arguments into a map.
 *
 * @param string[] $raw Positional arguments from wp eval-file.
 * @return array<string, string> Parsed key-value pairs.
 */
function safe_publish_demo_parse_args( array $raw ): array {
	$parsed = array();
	foreach ( $raw as $argument ) {
		if ( false === strpos( $argument, '=' ) ) {
			continue;
		}
		list( $key, $value ) = explode( '=', $argument, 2 );
		$parsed[ $key ]      = $value;
	}

	return $parsed;
}

/**
 * Finds a previously seeded demo page by its role marker. A meta lookup is
 * used because get_page_by_path() can't resolve a child page from its slug
 * alone, and duplicate slugs get auto-suffixed.
 *
 * @param string $role Demo role marker (e.g. 'parent', 'child', 'volume').
 * @return WP_Post|null The page, or null when none was seeded yet.
 */
function safe_publish_demo_find_page( string $role ): ?WP_Post {
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-off dev lookup by demo marker.
	$pages = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'meta_key'         => SAFE_PUBLISH_DEMO_ROLE_META,
			'meta_value'       => $role,
			'suppress_filters' => false,
		)
	);
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	return count( $pages ) > 0 ? $pages[0] : null;
}

/**
 * Inserts or updates a published demo page, tagged with its role so repeated
 * runs reuse it regardless of slug or hierarchy.
 *
 * @param string $role      Demo role marker (e.g. 'parent', 'child').
 * @param string $slug      Preferred page slug.
 * @param string $title     Page title.
 * @param int    $parent_id Parent page ID (0 for none).
 * @param int    $author    Author user ID.
 * @param string $content   Raw block markup for the page body.
 * @return int The page ID.
 */
function safe_publish_demo_upsert_page(
	string $role,
	string $slug,
	string $title,
	int $parent_id,
	int $author,
	string $content
): int {
	$existing = safe_publish_demo_find_page( $role );
	$postarr  = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_parent'  => $parent_id,
		'post_author'  => $author,
		'post_content' => $content,
	);
	if ( $existing instanceof WP_Post ) {
		$postarr['ID'] = $existing->ID;
	}

	$result = wp_insert_post( $postarr, true );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error(
			'Failed to upsert "' . $slug . '": ' . $result->get_error_message()
		);
	}

	$page_id = (int) $result;
	update_post_meta( $page_id, SAFE_PUBLISH_DEMO_ROLE_META, $role );

	return $page_id;
}

/**
 * Builds a links page whose navigation links point at two real but not-yet-
 * imported source pages, so the import records one unmapped_block_reference per
 * link. Importing the targets and retrying then resolves them.
 *
 * @param int $target_a_id Source ID of the first link target.
 * @param int $target_b_id Source ID of the second link target.
 * @return string Raw block markup.
 */
function safe_publish_demo_references_content(
	int $target_a_id,
	int $target_b_id
): string {
	return implode(
		'',
		array(
			'<!-- wp:navigation -->',
			'<!-- wp:navigation-link {"label":"Unmapped Target A","kind":"post-type","type":"page","id":' . $target_a_id . ',"url":"#"} /-->',
			'<!-- wp:navigation-link {"label":"Unmapped Target B","kind":"post-type","type":"page","id":' . $target_b_id . ',"url":"#"} /-->',
			'<!-- /wp:navigation -->',
		)
	);
}

/**
 * Builds a links page with a single navigation link to a source term that does
 * not exist, so its unmapped_block_reference issue can never resolve.
 *
 * @return string Raw block markup.
 */
function safe_publish_demo_unresolvable_content(): string {
	return implode(
		'',
		array(
			'<!-- wp:navigation -->',
			'<!-- wp:navigation-link {"label":"Unresolvable category","kind":"taxonomy","type":"category","id":900003,"url":"#"} /-->',
			'<!-- /wp:navigation -->',
		)
	);
}

/**
 * Builds a page whose core/block references an unimported wp_block, so the
 * import opens a retryable unmapped_block_reference issue flagged as a reusable
 * block.
 *
 * @return string Raw block markup.
 */
function safe_publish_demo_reusable_content(): string {
	return '<!-- wp:block {"ref":' . SAFE_PUBLISH_DEMO_REUSABLE_REF . '} /-->';
}

/**
 * Builds a links page with the given number of navigation links to
 * deliberately unresolvable source IDs, used to fill a drawer past one page.
 *
 * @param int $count Number of filler links.
 * @return string Raw block markup.
 */
function safe_publish_demo_volume_content( int $count ): string {
	$links = array( '<!-- wp:navigation -->' );
	for ( $i = 1; $i <= $count; $i++ ) {
		$links[] = sprintf(
			'<!-- wp:navigation-link {"label":"Volume %1$d","kind":"post-type","type":"page","id":%2$d,"url":"#"} /-->',
			$i,
			910000 + $i
		);
	}
	$links[] = '<!-- /wp:navigation -->';

	return implode( '', $links );
}

/**
 * Runs the source-side step: upsert the demo pages, or remove them on purge.
 *
 * @param array<string, string> $arguments Parsed CLI arguments.
 * @param bool                  $is_purge  Whether to delete the demo pages.
 */
function safe_publish_demo_run_source(
	array $arguments,
	bool $is_purge
): void {
	// Block comments in the links page survive insertion only with
	// unfiltered_html, so act as the admin to keep kses from stripping them.
	wp_set_current_user( 1 );

	if ( $is_purge ) {
		$roles = array(
			'child',
			'parent',
			'references',
			'references-unresolvable',
			'reusable-block',
			'volume',
			'target-a',
			'target-b',
		);
		foreach ( $roles as $role ) {
			$page = safe_publish_demo_find_page( $role );
			if ( $page instanceof WP_Post ) {
				wp_delete_post( $page->ID, true );
				WP_CLI::log( 'Deleted source page: ' . $page->ID );
			}
		}

		return;
	}

	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$author_id      = count( $administrators ) > 0
		? (int) $administrators[0]
		: 1;

	$parent_id       = safe_publish_demo_upsert_page(
		'parent',
		SAFE_PUBLISH_DEMO_PARENT_SLUG,
		SAFE_PUBLISH_DEMO_PARENT_TITLE,
		0,
		$author_id,
		'<!-- wp:paragraph --><p>Parent page, intentionally left unimported.</p><!-- /wp:paragraph -->'
	);
	$child_id        = safe_publish_demo_upsert_page(
		'child',
		SAFE_PUBLISH_DEMO_CHILD_SLUG,
		SAFE_PUBLISH_DEMO_CHILD_TITLE,
		$parent_id,
		$author_id,
		'<!-- wp:paragraph --><p>Child of an unimported parent.</p><!-- /wp:paragraph -->'
	);
	$target_a_id     = safe_publish_demo_upsert_page(
		'target-a',
		SAFE_PUBLISH_DEMO_TARGET_A_SLUG,
		SAFE_PUBLISH_DEMO_TARGET_A_TITLE,
		0,
		$author_id,
		'<!-- wp:paragraph --><p>Reference target A; import me to resolve a reference.</p><!-- /wp:paragraph -->'
	);
	$target_b_id     = safe_publish_demo_upsert_page(
		'target-b',
		SAFE_PUBLISH_DEMO_TARGET_B_SLUG,
		SAFE_PUBLISH_DEMO_TARGET_B_TITLE,
		0,
		$author_id,
		'<!-- wp:paragraph --><p>Reference target B; import me to resolve a reference.</p><!-- /wp:paragraph -->'
	);
	$references_id   = safe_publish_demo_upsert_page(
		'references',
		SAFE_PUBLISH_DEMO_REFERENCES_SLUG,
		SAFE_PUBLISH_DEMO_REFERENCES_TITLE,
		0,
		$author_id,
		safe_publish_demo_references_content( $target_a_id, $target_b_id )
	);
	$unresolvable_id = safe_publish_demo_upsert_page(
		'references-unresolvable',
		SAFE_PUBLISH_DEMO_UNRESOLVABLE_SLUG,
		SAFE_PUBLISH_DEMO_UNRESOLVABLE_TITLE,
		0,
		$author_id,
		safe_publish_demo_unresolvable_content()
	);
	$reusable_id     = safe_publish_demo_upsert_page(
		'reusable-block',
		SAFE_PUBLISH_DEMO_REUSABLE_SLUG,
		SAFE_PUBLISH_DEMO_REUSABLE_TITLE,
		0,
		$author_id,
		safe_publish_demo_reusable_content()
	);

	// Optional volume: one page with `count` filler links to push the drawer
	// past a page. Removed when count is 0 so the count self-corrects.
	$count     = max( 0, (int) ( $arguments['count'] ?? 0 ) );
	$volume_id = 0;
	if ( $count > 0 ) {
		$volume_id = safe_publish_demo_upsert_page(
			'volume',
			SAFE_PUBLISH_DEMO_VOLUME_SLUG,
			SAFE_PUBLISH_DEMO_VOLUME_TITLE,
			0,
			$author_id,
			safe_publish_demo_volume_content( $count )
		);
	} else {
		$stale_volume = safe_publish_demo_find_page( 'volume' );
		if ( $stale_volume instanceof WP_Post ) {
			wp_delete_post( $stale_volume->ID, true );
		}
	}

	// Read by the bin/seed-degradations wrapper to drive the import step.
	WP_CLI::log( 'SEED_PARENT_ID=' . $parent_id );
	WP_CLI::log( 'SEED_CHILD_ID=' . $child_id );
	WP_CLI::log( 'SEED_REFERENCES_ID=' . $references_id );
	WP_CLI::log( 'SEED_UNRESOLVABLE_ID=' . $unresolvable_id );
	WP_CLI::log( 'SEED_REUSABLE_ID=' . $reusable_id );
	WP_CLI::log( 'SEED_VOLUME_ID=' . $volume_id );
}

/**
 * Builds the Post_Import_Service graph, mirroring the plugin's admin wiring.
 *
 * @param Source_Posts_API            $api              Source posts API.
 * @param History_Repository          $repository       Import history repo.
 * @param Attention_Issues_Repository $attention_issues Attention issues repo.
 * @return Post_Import_Service The wired import service.
 */
function safe_publish_demo_build_service(
	Source_Posts_API $api,
	History_Repository $repository,
	Attention_Issues_Repository $attention_issues
): Post_Import_Service {
	$http_client       = new HTTP_Client();
	$media_importer    = new Media_Importer( $http_client );
	$content_processor = new Content_Processor(
		$media_importer,
		new Content_Media_Processor( $media_importer ),
		new Shortcode_ID_Rewriter()
	);

	return new Post_Import_Service(
		$api,
		$media_importer,
		$content_processor,
		$repository,
		new Meta_Terms_Manager(),
		new Telemetry_Service(),
		new Navigation_Ref_Rewriter(),
		$attention_issues
	);
}

/**
 * Deletes prior demo orphan-failure rows with a given title so repeated
 * seeding doesn't accumulate them.
 *
 * @param History_Repository $repository Import history repository.
 * @param string             $title      Demo orphan-failure title to clear.
 */
function safe_publish_demo_reset_orphan_failures(
	History_Repository $repository,
	string $title
): void {
	global $wpdb;

	$items_table = Import_Items_Table::table_name();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dev seeder; the table identifier can't be a placeholder.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM `{$items_table}`"
				. " WHERE status = 'error' AND source_post_id IS NULL"
				. ' AND title = %s',
			$title
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$ids = array_map( 'intval', $ids );
	if ( count( $ids ) > 0 ) {
		$repository->delete_failed_items( $ids );
	}
}

/**
 * Clears every demo orphan-failure row (core and volume).
 *
 * @param History_Repository $repository Import history repository.
 */
function safe_publish_demo_reset_all_orphan_failures(
	History_Repository $repository
): void {
	safe_publish_demo_reset_orphan_failures(
		$repository,
		SAFE_PUBLISH_DEMO_ORPHAN_TITLE
	);
	safe_publish_demo_reset_orphan_failures(
		$repository,
		SAFE_PUBLISH_DEMO_VOLUME_ORPHAN_TITLE
	);
}

/**
 * Deletes destination posts by title and type. Removing one clears its
 * attention issues through the plugin's post-delete hook.
 *
 * @param string $title     Title to delete.
 * @param string $post_type Post type (default 'page').
 */
function safe_publish_demo_delete_imported_pages(
	string $title,
	string $post_type = 'page'
): void {
	$pages = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'title'            => $title,
			'numberposts'      => -1,
			'suppress_filters' => false,
		)
	);
	foreach ( $pages as $page ) {
		wp_delete_post( $page->ID, true );
		WP_CLI::log( 'Deleted imported page: ' . $page->ID );
	}
}

/**
 * Counts the unmapped_block_reference warnings in an import result.
 *
 * @param array $result import_post() result.
 * @return int Number of unmapped reference warnings.
 */
function safe_publish_demo_count_unmapped( array $result ): int {
	return count(
		array_filter(
			$result['warnings'] ?? array(),
			static function ( array $warning ) {
				return 'unmapped_block_reference' === ( $warning['type'] ?? '' );
			}
		)
	);
}

/**
 * Seeds the error-severity nav_ref_rewrite_failed issue. The wp_navigation
 * fetch can't run in wp-env, so this stages a referrer page and a destination
 * menu, then drives the real cross-ref rewriter (forced to fail) and the real
 * reconcile path. A normal Retry re-runs the rewrite and resolves it.
 *
 * @param Attention_Issues_Repository $attention_issues Attention issues repo.
 * @param string                      $source_site_url  Connected source identity.
 */
function safe_publish_demo_seed_nav_failure(
	Attention_Issues_Repository $attention_issues,
	string $source_site_url
): void {
	$nav_id = SAFE_PUBLISH_DEMO_NAV_SOURCE_ID;

	// Referrer page: content the rewriter matches, plus the source-url meta its
	// candidate query requires.
	$referrer = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => SAFE_PUBLISH_DEMO_NAV_REFERRER_TITLE,
			'post_content' => '<!-- wp:navigation {"ref":' . $nav_id . '} /-->',
		),
		true
	);
	if ( is_wp_error( $referrer ) ) {
		WP_CLI::error(
			'Nav referrer insert failed: ' . $referrer->get_error_message()
		);
	}
	update_post_meta( $referrer, Options::META_SOURCE_SITE_URL, $source_site_url );

	// Destination menu mapped to the source id so Retry can locate it.
	$menu = wp_insert_post(
		array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => SAFE_PUBLISH_DEMO_NAV_MENU_TITLE,
			'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"#"} /-->',
		),
		true
	);
	if ( is_wp_error( $menu ) ) {
		WP_CLI::error(
			'Nav menu insert failed: ' . $menu->get_error_message()
		);
	}
	update_post_meta( $menu, Options::META_SOURCE_POST_ID, $nav_id );
	update_post_meta( $menu, Options::META_SOURCE_SITE_URL, $source_site_url );

	// Drive the real cross-ref rewrite with a forced write failure, then record
	// the issue through the real reconcile path.
	$failing = new class() extends Navigation_Ref_Rewriter {
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Override signature must match the parent.
		/**
		 * Forces the rewrite write to fail so the referrer surfaces as failed.
		 *
		 * @param int    $post_id     Destination post ID.
		 * @param string $new_content Serialized block content.
		 * @return bool Always false.
		 */
		protected function persist_rewritten_content(
			int $post_id,
			string $new_content
		): bool {
			return false;
		}
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
	};
	$result  = $failing->rewrite_cross_refs(
		$nav_id,
		(int) $menu,
		$source_site_url
	);
	if ( count( $result['failed'] ) < 1 ) {
		WP_CLI::error( 'Nav rewrite matched no referrer; issue not seeded.' );
	}

	$attention_issues->reconcile_target_issues(
		'nav_ref_rewrite_failed',
		$nav_id,
		'post',
		'error',
		$source_site_url,
		$result['failed'],
		array( 'source_nav_id' => $nav_id )
	);
}

/**
 * Removes the destination demo artifacts: orphan-failure rows plus the imported
 * and staged demo pages (deleting a page clears its attention issues through
 * the plugin's post-delete hook).
 */
function safe_publish_demo_purge_destination(): void {
	safe_publish_demo_reset_all_orphan_failures( new History_Repository() );

	$titles = array(
		SAFE_PUBLISH_DEMO_CHILD_TITLE,
		SAFE_PUBLISH_DEMO_REFERENCES_TITLE,
		SAFE_PUBLISH_DEMO_UNRESOLVABLE_TITLE,
		SAFE_PUBLISH_DEMO_REUSABLE_TITLE,
		SAFE_PUBLISH_DEMO_VOLUME_TITLE,
		SAFE_PUBLISH_DEMO_NAV_REFERRER_TITLE,
		SAFE_PUBLISH_DEMO_TARGET_A_TITLE,
		SAFE_PUBLISH_DEMO_TARGET_B_TITLE,
	);
	foreach ( $titles as $title ) {
		safe_publish_demo_delete_imported_pages( $title );
	}
	safe_publish_demo_delete_imported_pages(
		SAFE_PUBLISH_DEMO_NAV_MENU_TITLE,
		'wp_navigation'
	);

	WP_CLI::success( 'Destination demo artifacts removed.' );
}

/**
 * Runs the destination-side step: imports and stages the demo content that
 * populates the attention and orphan-failure drawers, or purges it.
 *
 * @param array<string, string> $arguments Parsed CLI arguments.
 * @param bool                  $is_purge  Whether to remove demo artifacts.
 */
function safe_publish_demo_run_import(
	array $arguments,
	bool $is_purge
): void {
	// Page creation and import run capability checks; act as the admin.
	wp_set_current_user( 1 );

	if ( $is_purge ) {
		safe_publish_demo_purge_destination();

		return;
	}

	$child_id        = (int) ( $arguments['child_id'] ?? 0 );
	$parent_id       = (int) ( $arguments['parent_id'] ?? 0 );
	$references_id   = (int) ( $arguments['references_id'] ?? 0 );
	$unresolvable_id = (int) ( $arguments['unresolvable_id'] ?? 0 );
	$reusable_id     = (int) ( $arguments['reusable_id'] ?? 0 );
	$volume_id       = (int) ( $arguments['volume_id'] ?? 0 );
	$count           = max( 0, (int) ( $arguments['count'] ?? 0 ) );
	if (
		$child_id <= 0 || $parent_id <= 0
		|| $references_id <= 0 || $unresolvable_id <= 0
		|| $reusable_id <= 0
	) {
		WP_CLI::error(
			'step=import requires child_id, parent_id, references_id, unresolvable_id, reusable_id.'
		);
	}
	if ( $count > 0 && $volume_id <= 0 ) {
		WP_CLI::error( 'count>0 requires a volume_id from the source step.' );
	}

	$source_site_url = Options::get_connected_site_url_with_path();
	if ( '' === $source_site_url ) {
		WP_CLI::error( 'No connected source site URL is configured.' );
	}

	// Import an orphaned parent rather than aborting, and fall back to a
	// default author so a mismatched author can't block the demo import.
	add_filter( 'safe_publish_import_allow_orphans', '__return_true' );
	add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

	$api              = new Source_Posts_API( new HTTP_Client() );
	$repository       = new History_Repository();
	$attention_issues = new Attention_Issues_Repository();
	$service          = safe_publish_demo_build_service(
		$api,
		$repository,
		$attention_issues
	);

	// Clear prior demo orphan rows + staged pages (volume + nav) so repeated
	// runs don't inflate the counts.
	safe_publish_demo_reset_all_orphan_failures( $repository );
	safe_publish_demo_delete_imported_pages( SAFE_PUBLISH_DEMO_VOLUME_TITLE );
	safe_publish_demo_delete_imported_pages( SAFE_PUBLISH_DEMO_NAV_REFERRER_TITLE );
	safe_publish_demo_delete_imported_pages(
		SAFE_PUBLISH_DEMO_NAV_MENU_TITLE,
		'wp_navigation'
	);

	$session_id = $repository->create_session( $source_site_url, 'bulk' );
	if ( is_wp_error( $session_id ) ) {
		WP_CLI::error(
			'Session create failed: ' . $session_id->get_error_message()
		);
	}

	// Prefetch the child's payload, mirroring bulk import pass 1.
	$prefetch = $api->fetch_fresh_post( $child_id, 'page' );
	$options  = is_wp_error( $prefetch )
		? array()
		: array( 'prefetched_fresh_result' => $prefetch );

	$child_result = $service->import_post(
		array(
			'id'        => $child_id,
			'post_type' => 'page',
			'title'     => SAFE_PUBLISH_DEMO_CHILD_TITLE,
			'parent'    => $parent_id,
			'link'      => rtrim( $source_site_url, '/' ) . '/?p=' . $child_id,
		),
		$session_id,
		$options
	);

	// Import the links pages; each unresolved nav ref records an
	// unmapped_block_reference issue. The references page's refs resolve once
	// their targets are imported; the unresolvable page's term ref never does.
	// The volume page (when requested) adds filler links to span a page.
	$reference_pages = array(
		array(
			'id'    => $references_id,
			'title' => SAFE_PUBLISH_DEMO_REFERENCES_TITLE,
		),
		array(
			'id'    => $unresolvable_id,
			'title' => SAFE_PUBLISH_DEMO_UNRESOLVABLE_TITLE,
		),
	);
	if ( $count > 0 ) {
		$reference_pages[] = array(
			'id'    => $volume_id,
			'title' => SAFE_PUBLISH_DEMO_VOLUME_TITLE,
		);
	}

	$unmapped_count = 0;
	foreach ( $reference_pages as $reference_page ) {
		$reference_prefetch = $api->fetch_fresh_post( $reference_page['id'], 'page' );
		$reference_options  = is_wp_error( $reference_prefetch )
			? array()
			: array( 'prefetched_fresh_result' => $reference_prefetch );

		$reference_result = $service->import_post(
			array(
				'id'        => $reference_page['id'],
				'post_type' => 'page',
				'title'     => $reference_page['title'],
				'link'      => rtrim( $source_site_url, '/' ) . '/?p=' . $reference_page['id'],
			),
			$session_id,
			$reference_options
		);

		if ( ! $reference_result['success'] ) {
			WP_CLI::error(
				'Import of "' . $reference_page['title'] . '" failed: '
					. $reference_result['error']
			);
		}

		$unmapped_count += safe_publish_demo_count_unmapped( $reference_result );
	}

	// Reusable-block page: its core/block references an unimported wp_block, so
	// the import opens a retryable unmapped_block_reference issue flagged as a
	// reusable block.
	$reusable_prefetch = $api->fetch_fresh_post( $reusable_id, 'page' );
	$reusable_options  = is_wp_error( $reusable_prefetch )
		? array()
		: array( 'prefetched_fresh_result' => $reusable_prefetch );
	$reusable_result   = $service->import_post(
		array(
			'id'        => $reusable_id,
			'post_type' => 'page',
			'title'     => SAFE_PUBLISH_DEMO_REUSABLE_TITLE,
			'link'      => rtrim( $source_site_url, '/' ) . '/?p=' . $reusable_id,
		),
		$session_id,
		$reusable_options
	);
	if ( ! $reusable_result['success'] ) {
		WP_CLI::error(
			'Import of "' . SAFE_PUBLISH_DEMO_REUSABLE_TITLE . '" failed: '
				. $reusable_result['error']
		);
	}

	$service->import_post(
		array(
			'title'     => SAFE_PUBLISH_DEMO_ORPHAN_TITLE,
			'post_type' => 'post',
		),
		$session_id
	);

	// Error-severity nav_ref_rewrite_failed (resolves on Retry).
	safe_publish_demo_seed_nav_failure( $attention_issues, $source_site_url );

	// Volume filler: add `count` orphan failures to span the orphan drawer
	// (the volume page above already added the attention-side filler).
	if ( $count > 0 ) {
		for ( $i = 1; $i <= $count; $i++ ) {
			$service->import_post(
				array(
					'title'     => SAFE_PUBLISH_DEMO_VOLUME_ORPHAN_TITLE,
					'post_type' => 'post',
				),
				$session_id
			);
		}
	}

	$repository->complete_session( $session_id );

	$attention_count = $attention_issues->count_open_issues( $source_site_url );
	$failed_count    = $repository->count_failures();

	if ( ! $child_result['success'] ) {
		WP_CLI::error( 'Child import failed: ' . $child_result['error'] );
	}
	if ( $unmapped_count < 1 ) {
		WP_CLI::error( 'Expected unmapped_block_reference warnings; got none.' );
	}
	if ( $attention_count < 1 || $failed_count < 1 ) {
		WP_CLI::error(
			sprintf(
				'Expected both counts above zero (attention=%d, failed=%d).',
				$attention_count,
				$failed_count
			)
		);
	}

	WP_CLI::success(
		sprintf(
			'Seeded attentionCount=%d (incl. %d unmapped refs), failedCount=%d. Open %s',
			$attention_count,
			$unmapped_count,
			$failed_count,
			admin_url( 'admin.php?page=safe-publish' )
		)
	);
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

$safe_publish_demo_args  = safe_publish_demo_parse_args( $args );
$safe_publish_demo_step  = $safe_publish_demo_args['step'] ?? '';
$safe_publish_demo_purge = isset( $safe_publish_demo_args['purge'] )
	&& '1' === $safe_publish_demo_args['purge'];

switch ( $safe_publish_demo_step ) {
	case 'source':
		safe_publish_demo_run_source(
			$safe_publish_demo_args,
			$safe_publish_demo_purge
		);
		break;
	case 'import':
		safe_publish_demo_run_import( $safe_publish_demo_args, $safe_publish_demo_purge );
		break;
	default:
		WP_CLI::error(
			'Unknown step "' . $safe_publish_demo_step . '". Use step=source or step=import.'
		);
}
