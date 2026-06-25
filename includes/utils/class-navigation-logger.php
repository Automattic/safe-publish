<?php
/**
 * Navigation Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

/**
 * Logger for out-of-import navigation reconciliation events (channel
 * "navigation").
 *
 * Producer contract for out-of-import fixups, which have no import session and
 * so cannot record History items: on a degradation, log a warning (an
 * unresolved reference) or error (the fixup write failed) here for admins AND
 * upsert_issue() into Attention_Issues_Repository for users; on resolution,
 * log info here AND resolve_issue(). Scope every issue by the affected post's
 * stored META_SOURCE_SITE_URL, the path-bearing source identity.
 */
class Navigation_Logger extends Logger {

	/**
	 * Constructs the Navigation_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'navigation';
	}

	/**
	 * Logs a navigation link left pointing at an unresolved source target.
	 *
	 * @param int    $affected_post_id Post whose navigation holds the link.
	 * @param int    $target_ref       Source id the link could not repoint to.
	 * @param string $source_url       Source URL still present in the link.
	 */
	public function link_unresolved(
		int $affected_post_id,
		int $target_ref,
		string $source_url
	): void {
		$this->log_warning(
			Log_Events::NAV_LINK_UNRESOLVED,
			array(
				'affected_post_id' => $affected_post_id,
				'target_ref'       => $target_ref,
				'source_url'       => $source_url,
			)
		);
	}

	/**
	 * Logs a navigation link repoint that failed at the write layer.
	 *
	 * @param int    $affected_post_id Post whose navigation holds the link.
	 * @param int    $target_ref       Source id the link targets.
	 * @param string $source_url       Source URL the link still carries.
	 * @param string $error            Failure detail from the write.
	 */
	public function link_rewrite_failed(
		int $affected_post_id,
		int $target_ref,
		string $source_url,
		string $error
	): void {
		$this->log_error(
			Log_Events::NAV_LINK_REWRITE_FAILED,
			array(
				'affected_post_id' => $affected_post_id,
				'target_ref'       => $target_ref,
				'source_url'       => $source_url,
				'error'            => $error,
			)
		);
	}

	/**
	 * Logs a navigation link successfully (re)pointed to its destination.
	 *
	 * @param int    $affected_post_id Post whose navigation holds the link.
	 * @param int    $target_ref       Source id the link now resolves to.
	 * @param string $source_url       Source URL that was repointed.
	 */
	public function link_resolved(
		int $affected_post_id,
		int $target_ref,
		string $source_url
	): void {
		$this->log_event(
			Log_Events::NAV_LINK_RESOLVED,
			array(
				'affected_post_id' => $affected_post_id,
				'target_ref'       => $target_ref,
				'source_url'       => $source_url,
			)
		);
	}
}
