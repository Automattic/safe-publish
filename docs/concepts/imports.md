# Imports

The Imports admin page is the operator surface for everything that came in from a source site. It has two tabs sharing the same admin route:

- **Posts** — the local posts that resulted from successful imports.
- **Needs attention** — post-import problems: import failures and degradations.

## Posts tab

Lists imported posts. Each row joins the local post (title, status, edit URL) with the most recent items-table row for the same post (import date, rollback eligibility).

The tab is scoped to the connected source site, so only posts imported from that site are listed and matched against the source catalog. Changing the connection hides the previous site's rows without deleting them; reconnecting brings them back.

### Columns

| Column        | Description                                               |
| ------------- | --------------------------------------------------------- |
| Title         | Local post title; links to the local editor when present. |
| Local Status  | Local `post_status` (draft, publish, etc.).               |
| Type          | Local `post_type`.                                        |
| Last Imported | Most recent `import_date_gmt` for this post.              |
| Sync Status   | How this post compares to the current source content.     |
| Permalink     | Source permalink (opens the source post in a new tab).    |

The **Sync Status** column compares each imported post against the current source content and reports one of: _Up to date_, _Outdated_, _Missing on source_, _Cannot check_, or _Invalid timestamp_. _Cannot check_ means the source request failed; _Invalid timestamp_ means a source or import timestamp could not be parsed. The column refills after every listing refresh.

### Actions

| Action   | Description                                                                |
| -------- | -------------------------------------------------------------------------- |
| Edit     | Opens the local post in the WordPress editor.                              |
| Update   | Re-imports the post from the source, overwriting local content.            |
| Diff     | Shows the difference between the pre-import snapshot and the current post. |
| Delete   | Moves the local post to trash.                                             |
| Rollback | Reverts the most recent import — restores updates, deletes new creations.  |

Update, Delete, and Rollback support bulk selection; Diff is single-row only. Rollback eligibility tracks the items-table status: only `success` and `updated` rows that have not already been rolled back can be reverted.

### Filtering

The page exposes the DataViews built-in search and filter chips: title search, Local Status (multi-select), Type (multi-select). Filtering is server-side over the full dataset, so a search applies to every imported post, not just the current page.

The selected session does not appear as a filter — sessions are an internal grouping concept, not a UI noun. The post-import notice deep-links into a session-filtered view via `?batch=N`, surfaced as a contextual pill the operator can clear.

## Needs attention tab

Collects every post-import problem in one place: import **failures** (the import errored, so no local post exists — or a re-import of an already-imported post failed) and **degradations** (the post imported but something could not be carried over — an unresolved reference to a block, term, parent, or navigation link, or a taxonomy this site does not register). Failures are listed first. The tab label shows the current count of open failures plus degradations.

The tab is scoped to the connected source site, so only that site's failures and degradations are listed and counted. Changing the connection hides the previous site's rows without deleting them; reconnecting brings them back.

An **Open | Ignored** toggle switches between the active list and items set aside with Ignore. Open (the default) excludes ignored items, and the tab count always reflects the Open set. The Ignored view offers Un-ignore to restore an item; Remove still applies there for failures.

### Columns

| Column   | Description                                                                                                                                              |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Content  | Title of the affected post, linked to its editor when a live destination post exists (degradations and failed updates; a first-import failure has none). |
| Type     | **Failed** or **Degraded**.                                                                                                                              |
| Detail   | The error message (failures), or the issue description with a **Resolvable now** / **Waiting on import** hint (degradations).                            |
| Severity | **Error** or **Warning**.                                                                                                                                |
| When     | When the failure was attempted or the degradation was first detected.                                                                                    |

### Actions

| Action    | Description                                                                                                                                    |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Remove    | Clears a failure's record. Supports bulk selection.                                                                                            |
| Retry     | Re-runs a degradation's reconciliation and reports whether it cleared. Available when the issue type is reconcilable; supports bulk selection. |
| Ignore    | Sets a failure or degradation aside without deleting it, dropping it from the Open view and the count. Reversible; supports bulk selection.    |
| Un-ignore | Restores an ignored item to the Open view. Shown in the Ignored view; supports bulk selection.                                                 |

**Resolvable now vs Waiting on import.** Each degradation whose target an import can supply carries a hint: **Resolvable now** means its target (parent, block, term, or menu) is already imported, so a Retry should reconcile it; **Waiting on import** means the target is still missing and a Retry would report it as absent. Importing a target does not auto-resolve the degradations that reference it, so the hint is how you find which ones to Retry — it is advisory, so Retry stays authoritative. Retrying several at once reports an aggregate: how many resolved, are still waiting, or failed.

Removing a failure only deletes its record; it has no effect on the source. Recovery is fixing the underlying issue (for example, creating a missing author on the destination) and re-importing the post from the Source Posts page. Any later import attempt for the same post on the same source site drops the earlier failure from the list automatically.

**Ignore vs Remove.** Ignore is reversible: it hides a failure or degradation from the Open view and the tab count but keeps its record, so Un-ignore brings it back — and a fresh failed attempt, or re-detecting the same degradation, re-surfaces it in Open. Remove is permanent: it deletes a failure's record outright. Degradations have no Remove; they clear only by a successful Retry or re-import.

## Post-import notice

After a bulk import completes, an admin notice surfaces a deep-link to the just-finished batch:

> Last import: 47 of 50 posts imported. 3 failed. **View imports**

The link opens the Imports → Posts tab with `?batch=N` applied as a contextual filter. The pill above the listing identifies the active batch and offers a Clear action that drops the filter and the URL parameter.

The notice persists for one hour or until the operator dismisses it.

## Database storage

Imports remain backed by two custom tables. They are bookkeeping for the rollback service and the audit fields surfaced on the Imports page; nothing in the UI treats a session as a navigable entity.

| Table                                      | Purpose                                         |
| ------------------------------------------ | ----------------------------------------------- |
| `{$wpdb->prefix}safe_publish_imports`      | One row per import operation (session).         |
| `{$wpdb->prefix}safe_publish_import_items` | One row per imported item (linked via session). |

## Next Steps

- [Audit Log](audit-log.md) — Reviewing logged events, including exports.
- [Import Process](import-process.md) — How imports run end-to-end.
- [Authentication](authentication.md) — Setting up source/destination credentials.
- [Troubleshooting](../troubleshooting.md) — Solving common issues.
