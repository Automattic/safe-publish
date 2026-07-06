# Seeding Import Degradations

A development helper that populates the **Needs attention** and **orphan failures** drawers on the Safe Publish admin screen with realistic, import-produced degradations, so the drawers and their Retry / Remove actions can be exercised locally.

Unlike [content seeding](content-seeding.md), which fills a site with importable content, this tool produces the post-import degradation rows the drawers surface. It drives the real import path on the destination, so the seeded rows behave exactly like production ones.

It requires a running development environment (`npm run dev`) with both sites configured — the `afterStart` lifecycle script does this. `bin/seed-degradations.php` is the underlying WP-CLI script and should not be called directly; the `bin/seed-degradations` wrapper routes the source-side setup and the destination-side import to the correct wp-env containers.

## npm Script

| Command                                | Description                                       |
| -------------------------------------- | ------------------------------------------------- |
| `npm run seed:degradations`            | Seed both drawers on the destination site         |
| `npm run seed:degradations -- count=N` | Also add N filler entries per drawer for paging   |
| `npm run seed:degradations -- purge=1` | Remove every seeded demo artifact from both sites |

Re-running is idempotent: the counts hold steady, and dropping `count` back to its default removes the filler without a purge.

## What Gets Seeded

Six **Needs attention** issues — covering every issue type and both severities — plus one **orphan failure**:

| Affected page               | Issue type                            | Severity | Resolves via                                |
| --------------------------- | ------------------------------------- | -------- | ------------------------------------------- |
| Orphan Demo Child           | `parent_orphaned`                     | warning  | import **Orphan Demo Parent**, then Retry   |
| Unmapped References Demo    | `unmapped_block_reference` (post, ×2) | warning  | import **Unmapped Target A** / **B**, Retry |
| Unresolvable Reference Demo | `unmapped_block_reference` (term)     | warning  | never — points at a non-existent term       |
| Nav Referrer Demo           | `nav_ref_rewrite_failed`              | error    | Retry alone                                 |
| Reusable Block Demo         | `unmapped_block_reference` (reusable) | warning  | never — target block isn't seeded on source |

The orphan failure — titled "Import with no source ID" — comes from an import request with no source post id.

The resolvable rows demonstrate the self-verifying Retry: import the named target (switch the post-type dropdown to **Pages**), then click **Retry** and the issue clears. The unresolvable term reference and the reusable-block reference both offer Retry but stay open no matter what — the term points at a non-existent term, and the demo's reusable-block target isn't seeded — for contrast.

## Exercising the Drawers

1. Run `npm run seed:degradations`.
2. Open **Safe Publish** in the destination admin; the toolbar shows a **Needs attention** button and an **orphan failures** button.
3. Open a drawer and use **Retry** (attention issues) or **Remove** (orphan failures) on the rows.
4. To watch an issue clear, import its listed target, then click **Retry**.

Pass `count=N` (for example `count=30`) to push each drawer past one page and exercise pagination.

## Cleanup

`npm run seed:degradations -- purge=1` removes every demo artifact from both sites — the source demo pages, the imported and staged destination pages (including the staged navigation menu), and the orphan-failure rows — leaving both drawers empty.

## Not for Production

This is a development-only tool. It runs as the admin, enables the import orphan and author fallback filters, and stages a navigation menu directly because wp-env can't fetch `wp_navigation` over REST. Do not run it against a production site.
