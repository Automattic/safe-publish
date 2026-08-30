# Managing Imports

The **Safe Publish → Manage** screen is the operator surface for browsing source content, importing it, and reviewing previous imports. It has two tabs:

- **Posts** — a unified source and destination listing.
- **Needs attention** — import failures and post-import degradations.

## Posts tab

The Posts tab combines the source catalog with Safe Publish's local import history. Use the **Local State** control to choose which records are listed:

- **All** — all source posts, including posts already imported.
- **Not imported** — source posts without an active destination import.
- **Up to date** — imported posts whose stored source modification time is not newer than their import time.
- **Outdated** — imported posts whose stored source modification time is newer than their import time.

> **Developer note:** The **Not imported** label maps to the internal `available` value used in query parameters, AJAX payloads, and code. It covers posts with no active successful import — never imported, error-only, or every import rolled back — and imported posts whose destination post is missing or trashed. Local State is derived from import history rather than stored in a dedicated database field. Retaining `available` preserves backward compatibility.

Safe Publish also checks imported rows against the source while the listing is open. A newly changed source post can therefore show an additional **Outdated** badge before it moves into the Outdated view on the next listing refresh. A failed live comparison shows **Sync check failed**; there is no Unknown local state.

The tab is scoped to the connected source site, so import state is resolved against that site alone: a source post ID imported under a previous connection reads as not imported. Changing the connection hides the previous site's rows without deleting them. Reconnecting with a URL that has the same scheme, host, port, and path brings them back. A trailing slash, query, or fragment does not affect the stored source identity.

### Columns

The visible columns depend on the selected Local State:

| Column | Description |
| --- | --- |
| Title | Source post title, shown as plain text rather than linked text. |
| Local State | Not imported, Up to date, or Outdated, plus live sync details. |
| Local Status | Destination `post_status`, or a dash when not yet imported. |
| Source Status | Source `post_status`; shown in All and Not imported. |
| Published Date | Source publication date; shown in All and Not imported. |
| Imported Date | Most recent active import date; shown in Up to date and Outdated. |

### Actions

Actions are shown only when they apply to the selected row:

| Action | Description |
| --- | --- |
| Import | Creates a destination draft or re-imports changed source content. |
| Compare | Compares the current destination post with fresh source content. |
| Edit | Opens the destination post in the WordPress editor. |
| Trash | Moves the destination post to trash. |
| Roll back | Reverses the latest eligible import. |

View source opens the source post in a new browser tab. It is available when the source provides a post permalink other than its homepage.

Import, Trash, and Roll back support bulk selection. Compare is available for one outdated post at a time. An Up to date row does not offer Import or Compare unless the live source check finds a newer version.

Rollback eligibility comes from the latest active import-history row. Only `success` and `updated` rows that have not already been rolled back are eligible. The server re-checks that rule on each request, so a listing loaded before an earlier rollback cannot re-apply it. Rolling back a successful new import deletes the destination post. Rolling back an update restores the captured post fields, author, parent, post type, featured image, editor and tracking metadata, and previous assignments for taxonomies carried in the import payload. It does not restore or remove imported custom metadata, delete created term objects, or restore changes to shared term fields. When an update changes the post type, WordPress may add a default category or another taxonomy's default term outside the import payload; those assignments are not currently removed by rollback. An older update with no captured previous content is deleted instead.

### Filtering and search

The controls above the table provide:

- **Type** selection.
- Title or URL search. Source URLs apply to All and Not imported; destination URLs apply to Up to date and Outdated.
- Published-date filtering for All and Not imported.
- Imported-date filtering for Up to date and Outdated.
- Source-status filtering for All and Not imported.
- Local State selection.

Title and date sorting are server-side, so they apply to the full result set, not just the current page.

The selected session does not appear as a filter. Sessions are an internal grouping concept, not a UI noun.

## Needs attention tab

This tab collects two kinds of post-import problem:

- **Failures** — the import errored. A first import has no destination post; a failed re-import can still link to the existing destination post.
- **Degradations** — the post imported, but a reference, relationship, or taxonomy could not be carried over completely, or an existing term's fields could not be reconciled with the source.

Failures are listed before degradations. The tab label shows the number of open items. Use **Open | Ignored** to switch between active items and items set aside with Ignore.

The tab is scoped to the connected source site. Changing the connection hides the previous site's failures and degradations without deleting them; reconnecting with a URL that has the same scheme, host, port, and path brings them back. A trailing slash, query, or fragment does not affect the stored source identity.

### Columns

| Column   | Description                                                     |
| -------- | --------------------------------------------------------------- |
| Content  | Affected title, linked to the destination editor when possible. |
| Type     | Failed or Degraded.                                             |
| Detail   | Error or degradation details and any retry readiness hint.      |
| Severity | Error or Warning.                                               |
| When     | When the failure occurred or degradation was first detected.    |

### Actions

| Action    | Description                                          |
| --------- | ---------------------------------------------------- |
| Remove    | Permanently deletes a failure record.                |
| Retry     | Re-runs reconciliation for a supported degradation.  |
| Ignore    | Hides an item from Open without deleting its record. |
| Un-ignore | Restores an ignored item to Open.                    |

These actions support bulk selection where applicable. Degradations show **Resolvable now** when the referenced target has been imported and **Waiting on import** while it is still missing. Importing the target does not automatically retry existing degradations. A bulk Retry reports how many issues resolved, are still waiting on an import, or failed. Remove remains available for failures in the Ignored view.

Removing a failure affects only its history record. To recover, fix the cause and import the source post again from the Posts tab. Any later import attempt for the same source supersedes its previous failure. If the later attempt also fails, the new failure appears instead.

Ignore is reversible with Un-ignore. A fresh failed attempt creates a new open failure, but re-detecting the same degradation keeps the existing issue ignored. Remove permanently deletes a failure record. Degradations do not offer Remove; they clear after a successful Retry or re-import.

## Post-import notice

After a bulk import completes, a per-user admin notice summarizes the batch on the next Safe Publish page load:

> Last import: 47 of 50 posts imported. 3 failed. **View imports**

Severity tracks the outcome: error when nothing succeeded, warning when successes and failures were mixed, and informational when every post imported.

When nothing succeeded, the link reads **View failures** and opens Needs attention, since the Posts tab would have nothing to show. Otherwise, **View imports** opens the Posts tab filtered to Up to date. That view is not filtered to only the completed session.

The notice persists for one hour, or until the operator follows its link or dismisses it. Following the link clears the batch, so the notice does not reappear on the page it just sent the operator to.

## Database storage

Import history is stored in two custom tables used for listing, failure review, and rollback bookkeeping:

| Table | Purpose |
| --- | --- |
| `{$wpdb->prefix}safe_publish_imports` | One row per import operation (session). |
| `{$wpdb->prefix}safe_publish_import_items` | One row per imported item (linked via session). |

## Next Steps

- [Audit Log](audit-log.md) — Reviewing logged events, including exports.
- [Import Process](import-process.md) — How imports run end-to-end.
- [Authentication](authentication.md) — Setting up source and destination credentials.
- [Troubleshooting](../troubleshooting.md) — Solving common issues.
