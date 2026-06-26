<?php
/**
 * Reconcile Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

/**
 * Logger for reference-reconciliation events (channel "reconcile").
 *
 * Reconciliation re-runs a repair on a degraded cross-reference and records the
 * outcome. Producers (Retry today, any out-of-import producer later) log it
 * here for admins and update the user-facing issues store. The audit entry
 * records one outcome per reconciliation, not a per-row mirror: a target-scoped
 * reconciliation settles several issue rows but logs once. Scope every issue by
 * the affected post's stored META_SOURCE_SITE_URL, the path-bearing source
 * identity.
 *
 * Outcomes are type-agnostic: issue_type names the reference class reconciled,
 * so a new reference class reuses these helpers without adding events.
 */
class Reconcile_Logger extends Logger {

	/**
	 * Constructs the Reconcile_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'reconcile';
	}

	/**
	 * Logs a reference reconciled to its destination target.
	 *
	 * @param string $issue_type       Tracked issue type that was reconciled.
	 * @param int    $affected_post_id Post holding the reference.
	 * @param int    $target_ref       Source id the reference now resolves to.
	 * @param string $target_kind      'post' or 'term'.
	 */
	public function resolved(
		string $issue_type,
		int $affected_post_id,
		int $target_ref,
		string $target_kind
	): void {
		$this->log_event(
			Log_Events::RECONCILE_RESOLVED,
			$this->payload(
				$issue_type,
				$affected_post_id,
				$target_ref,
				$target_kind
			)
		);
	}

	/**
	 * Logs a reference left unresolved after a reconciliation attempt.
	 *
	 * @param string $issue_type       Tracked issue type still unresolved.
	 * @param int    $affected_post_id Post holding the reference.
	 * @param int    $target_ref       Source id the reference points at.
	 * @param string $target_kind      'post' or 'term'.
	 */
	public function unresolved(
		string $issue_type,
		int $affected_post_id,
		int $target_ref,
		string $target_kind
	): void {
		$this->log_warning(
			Log_Events::RECONCILE_UNRESOLVED,
			$this->payload(
				$issue_type,
				$affected_post_id,
				$target_ref,
				$target_kind
			)
		);
	}

	/**
	 * Logs an error-severity reconciliation that left the issue unresolved,
	 * whether the write failed or the retry could not clear it.
	 *
	 * @param string $issue_type       Tracked issue type being reconciled.
	 * @param int    $affected_post_id Post holding the reference.
	 * @param int    $target_ref       Source id the reference points at.
	 * @param string $target_kind      'post' or 'term'.
	 * @param string $error            Detail on why the issue remains.
	 */
	public function failed(
		string $issue_type,
		int $affected_post_id,
		int $target_ref,
		string $target_kind,
		string $error
	): void {
		$data          = $this->payload(
			$issue_type,
			$affected_post_id,
			$target_ref,
			$target_kind
		);
		$data['error'] = $error;

		$this->log_error( Log_Events::RECONCILE_FAILED, $data );
	}

	/**
	 * Builds the shared outcome payload.
	 *
	 * @param string $issue_type       Tracked issue type.
	 * @param int    $affected_post_id Post holding the reference.
	 * @param int    $target_ref       Source id of the target.
	 * @param string $target_kind      'post' or 'term'.
	 * @return array Outcome payload.
	 */
	private function payload(
		string $issue_type,
		int $affected_post_id,
		int $target_ref,
		string $target_kind
	): array {
		return array(
			'issue_type'       => $issue_type,
			'affected_post_id' => $affected_post_id,
			'target_ref'       => $target_ref,
			'target_kind'      => $target_kind,
		);
	}
}
