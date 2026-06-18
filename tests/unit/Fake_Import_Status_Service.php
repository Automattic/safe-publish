<?php
/**
 * Post Import Service test double for import-status annotation.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use Safe_Publish\Admin\Post_Import_Service;

/**
 * Post Import Service double flagging imported rows from a fixed id set.
 */
class Fake_Import_Status_Service extends Post_Import_Service {

	/**
	 * Source ids to annotate as imported.
	 *
	 * @var int[]
	 */
	public array $imported_source_ids = array();

	/**
	 * When true, every row is annotated as imported.
	 *
	 * @var bool
	 */
	public bool $mark_all_imported = false;

	/**
	 * Constructs the double without the parent's dependencies.
	 */
	public function __construct() {}

	/**
	 * Flags each row's is_imported from the configured id set.
	 *
	 * @param array $posts Catalog rows to annotate, by reference.
	 */
	#[\Override]
	public function annotate_posts_with_import_status( array &$posts ): void {
		foreach ( $posts as &$post ) {
			$source_id           = (int) ( $post['id'] ?? 0 );
			$post['is_imported'] = $this->mark_all_imported
				|| in_array( $source_id, $this->imported_source_ids, true );
		}
		unset( $post );
	}
}
