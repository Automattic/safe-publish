<?php
/**
 * Term Reconcile Report collector.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What one reconcile pass left unwritten and what it brought back in line with
 * the source.
 *
 * Appended to as terms are reconciled, so the caller reads one report per pass.
 * A pass over a single term collects only conflicts; whether that term counts
 * as resolved is the caller's call.
 */
final class Term_Reconcile_Report {

	/**
	 * Fields the reconcile could not write.
	 *
	 * @var list<Term_Conflict>
	 */
	private array $conflicts = array();

	/**
	 * Source term IDs of the terms brought current.
	 *
	 * @var list<int>
	 */
	private array $resolved = array();

	/**
	 * Destination term IDs created during this reconcile, by taxonomy.
	 *
	 * @var array<string, list<int>>
	 */
	private array $created = array();

	/**
	 * Visible field changes applied to existing terms.
	 *
	 * @var list<array{taxonomy:string, term_id:int, fields:array<string, array{before:mixed, after?:mixed, after_hash?:string}>}>
	 */
	private array $updated = array();

	/**
	 * Records one conflict.
	 *
	 * @param Term_Conflict $conflict Field the reconcile could not write.
	 */
	public function add_conflict( Term_Conflict $conflict ): void {
		$this->conflicts[] = $conflict;
	}

	/**
	 * Records several conflicts.
	 *
	 * @param Term_Conflict[] $conflicts Fields the reconcile could not write.
	 */
	public function add_conflicts( array $conflicts ): void {
		foreach ( $conflicts as $conflict ) {
			$this->conflicts[] = $conflict;
		}
	}

	/**
	 * Records a term now matching the source.
	 *
	 * @param int $source_term_id Source term ID of the reconciled term.
	 */
	public function mark_resolved( int $source_term_id ): void {
		$this->resolved[] = $source_term_id;
	}

	/**
	 * Records a destination term created by this reconcile pass.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Destination term ID.
	 */
	public function record_created( string $taxonomy, int $term_id ): void {
		$this->created[ $taxonomy ][] = $term_id;
	}

	/**
	 * Records visible fields changed on an existing destination term.
	 *
	 * @param string                                                               $taxonomy Taxonomy slug.
	 * @param int                                                                  $term_id  Destination term ID.
	 * @param array<string, array{before:mixed, after?:mixed, after_hash?:string}> $fields   Changed fields.
	 */
	public function record_updated(
		string $taxonomy,
		int $term_id,
		array $fields
	): void {
		if ( array() === $fields ) {
			return;
		}

		$this->updated[] = array(
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
			'fields'   => $fields,
		);
	}

	/**
	 * Lists the fields the reconcile could not write.
	 *
	 * @return list<Term_Conflict>
	 */
	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Lists the source term IDs of the terms brought current.
	 *
	 * @return list<int>
	 */
	public function resolved(): array {
		return $this->resolved;
	}

	/**
	 * Lists destination terms created during this reconcile pass.
	 *
	 * @return array<string, list<int>> Taxonomy => destination term IDs.
	 */
	public function created(): array {
		return $this->created;
	}

	/**
	 * Lists visible field changes applied to existing terms.
	 *
	 * @return list<array{taxonomy:string, term_id:int, fields:array<string, array{before:mixed, after?:mixed, after_hash?:string}>}>
	 */
	public function updated(): array {
		return $this->updated;
	}
}
