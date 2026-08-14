# Managing Imports

The **Safe Publish → Manage** screen is the operator surface for browsing source
content, importing it, and reviewing previous imports. It has two tabs:

- **Posts** — a unified source and destination listing.
- **Needs attention** — import failures and post-import degradations.

## Posts tab

The Posts tab combines the source catalog with Safe Publish's local import
history. Use the **Local State** control to choose which records are listed:

- **All** — all source posts, including posts already imported.
- **Available** — source posts without an active destination import.
- **Up to date** — imported posts whose stored source modification time is not
  newer than their import time.
- **Outdated** — imported posts whose stored source modification time is newer
  than their import time.

Safe Publish also checks imported rows against the source while the listing is
open. A newly changed source post can therefore show an additional **Outdated**
badge before it moves into the Outdated view on the next listing refresh. A
failed live comparison shows **Sync check failed**; there is no Unknown local
state.

The tab is scoped to the connected source site, so import state is resolved against that site alone: a source post ID imported under a previous connection reads as not imported. Changing the connection hides the previous site's rows without deleting them; reconnecting brings them back.

### Columns

The visible columns depend on the selected Local State:

| Column         | Description                                                     |
| -------------- | --------------------------------------------------------------- |
| Title          | Source post title, linked to its source permalink when present. |
| Local State    | Available, Up to date, or Outdated, plus live sync information. |
| Local Status   | Destination `post_status`, or a dash when not yet imported.     |
| Source Status  | Source `post_status`; shown in All and Available.               |
| Published Date | Source publication date; shown in All and Available.            |
| Imported Date  | Most recent import date; shown in Up to date and Outdated.      |

### Actions

Actions are shown only when they apply to the selected row:

| Action    | Description                                                       |
| --------- | ----------------------------------------------------------------- |
| Import    | Creates a destination draft or re-imports changed source content. |
| Compare   | Compares the current destination post with fresh source content.  |
| Edit      | Opens the destination post in the WordPress editor.               |
| Trash     | Moves the destination post to trash.                              |
| Roll back | Reverses the latest eligible import.                              |

Import, Trash, and Roll back support bulk selection. Compare is available for
one outdated post at a time. An Up to date row does not offer Import or Compare
unless the live source check finds a newer version.

Rollback eligibility comes from the latest active import-history row. A
successful new import can be rolled back by deleting the destination post; a
successful update can be rolled back when Safe Publish captured the previous
content.

### Filtering and search

The controls above the table provide:

- **Type** selection.
- Title or URL search. Source URLs apply to All and Available; destination URLs
  apply to Up to date and Outdated.
- Published-date filtering for All and Available.
- Imported-date filtering for Up to date and Outdated.
- Source-status filtering for All and Available.
- Local State selection.

Title and date sorting are server-side, so they apply to the full result set,
not just the current page.

## Needs attention tab

This tab collects two kinds of post-import problem:

- **Failures** — the import errored. A first import has no destination post; a
  failed re-import can still link to the existing destination post.
- **Degradations** — the post imported, but a reference, relationship, or
  taxonomy could not be carried over completely, or an existing term's fields
  could not be reconciled with the source.

Failures are listed before degradations. The tab label shows the number of open
items. Use **Open | Ignored** to switch between active items and items set aside
with Ignore.

The tab is scoped to the connected source site. Changing the connection hides
the previous site's failures and degradations without deleting them;
reconnecting brings them back.

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

These actions support bulk selection where applicable. Degradations show
**Resolvable now** when the referenced target has been imported and **Waiting
on import** while it is still missing. Importing the target does not
automatically retry existing degradations.

Removing a failure affects only its history record. To recover, fix the cause
and import the source post again from the Posts tab. A later successful import
for the same source removes its previous failure from the list automatically.

Ignore is reversible: a fresh failed attempt or re-detecting the same
degradation returns the item to Open. Remove permanently deletes a failure
record. Degradations do not offer Remove; they clear after a successful Retry
or re-import.

## Post-import notice

After a bulk import, Safe Publish records a per-user admin notice for up to one
hour. The notice summarizes successful and failed items. **View imports** opens
the Manage page in the Up to date view when at least one item succeeded;
**View failures** opens Needs attention when every item failed. The destination
view is not filtered to only the completed session.

## Database storage

Import history is stored in two custom tables used for listing, failure review,
and rollback bookkeeping:

| Table                                      | Purpose                                         |
| ------------------------------------------ | ----------------------------------------------- |
| `{$wpdb->prefix}safe_publish_imports`      | One row per import operation (session).         |
| `{$wpdb->prefix}safe_publish_import_items` | One row per imported item (linked via session). |

## Next Steps

- [Audit Log](audit-log.md) — Reviewing logged events, including exports.
- [Import Process](import-process.md) — How imports run end-to-end.
- [Authentication](authentication.md) — Setting up source and destination credentials.
- [Troubleshooting](../troubleshooting.md) — Solving common issues.
