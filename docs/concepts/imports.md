# Imports

The Imports admin page is the operator surface for everything that came in
from a source site. It has two tabs sharing the same admin route:

- **Posts** — the local posts that resulted from successful imports.
- **Failures** — items whose import errored before a local post was created.

## Posts tab

Lists imported posts. Each row joins the local post (title, status, edit URL)
with the most recent items-table row for the same post (import date,
rollback eligibility).

### Columns

| Column        | Description                                               |
| ------------- | --------------------------------------------------------- |
| Title         | Local post title; links to the local editor when present. |
| Local Status  | Local `post_status` (draft, publish, etc.).               |
| Type          | Local `post_type`.                                        |
| Last Imported | Most recent `import_date_gmt` for this post.              |
| Sync Status   | How this post compares to the current source content.     |
| Permalink     | Source permalink (opens the source post in a new tab).    |

The **Sync Status** column compares each imported post against the current
source content and reports one of: _Up to date_, _Outdated_, _Missing on
source_, _Cannot check_, or _Invalid timestamp_. _Cannot check_ means the
source request failed; _Invalid timestamp_ means a source or import timestamp
could not be parsed. The column refills after every listing refresh.

### Actions

| Action   | Description                                                                |
| -------- | -------------------------------------------------------------------------- |
| Edit     | Opens the local post in the WordPress editor.                              |
| Update   | Re-imports the post from the source, overwriting local content.            |
| Diff     | Shows the difference between the pre-import snapshot and the current post. |
| Delete   | Moves the local post to trash.                                             |
| Rollback | Reverts the most recent import — restores updates, deletes new creations.  |

Update, Delete, and Rollback support bulk selection; Diff is single-row only.
Rollback eligibility tracks the items-table status: only `success` and
`updated` rows that have not already been rolled back can be reverted.

### Filtering

The page exposes the DataViews built-in search and filter chips: title
search, Local Status (multi-select), Type (multi-select). Filtering is
server-side over the full dataset, so a search applies to every imported
post, not just the current page.

The selected session does not appear as a filter — sessions are an internal
grouping concept, not a UI noun. The post-import notice deep-links into a
session-filtered view via `?batch=N`, surfaced as a contextual pill the
operator can clear.

## Failures tab

Lists items whose import errored. Failed items have no local WordPress post
(the import did not complete), so the row only carries what the items table
recorded plus the source URL from the parent session.

### Columns

| Column    | Description                                        |
| --------- | -------------------------------------------------- |
| Title     | Title of the post that was attempted.              |
| Source    | URL of the source site the attempt came from.      |
| Error     | Error message recorded at import time.             |
| Attempted | When the import was attempted (`import_date_gmt`). |

### Actions

| Action | Description                                                   |
| ------ | ------------------------------------------------------------- |
| Remove | Clears the failed item from the tab. Supports bulk selection. |

Removing a failed item only deletes its record; it has no effect on the
source. Recovery is fixing the underlying issue (for example, creating a
missing author on the destination) and re-importing the post from the
Source Posts page.

## Post-import notice

After a bulk import completes, an admin notice surfaces a deep-link to the
just-finished batch:

> Last import: 47 of 50 posts imported. 3 failed. **View imports**

The link opens the Imports → Posts tab with `?batch=N` applied as a
contextual filter. The pill above the listing identifies the active batch
and offers a Clear action that drops the filter and the URL parameter.

The notice persists for one hour or until the operator dismisses it.

## Database storage

Imports remain backed by two custom tables. They are bookkeeping for the
rollback service and the audit fields surfaced on the Imports page; nothing
in the UI treats a session as a navigable entity.

| Table                                      | Purpose                                         |
| ------------------------------------------ | ----------------------------------------------- |
| `{$wpdb->prefix}safe_publish_imports`      | One row per import operation (session).         |
| `{$wpdb->prefix}safe_publish_import_items` | One row per imported item (linked via session). |

A future audit log will absorb per-item event capture; the Imports page will
keep its current shape.

## Next Steps

- [Exports](exports.md) — Reviewing outbound export events.
- [Import Process](import-process.md) — How imports run end-to-end.
- [Authentication](authentication.md) — Setting up source/destination credentials.
- [Troubleshooting](../troubleshooting.md) — Solving common issues.
