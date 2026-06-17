# Concepts

Shared domain vocabulary for this project — entities, named processes, and status concepts with project-specific meaning. Seeded with core domain vocabulary, then accretes as ce-compound and ce-compound-refresh process learnings; direct edits are fine. Glossary only, not a spec or catch-all.

## Relationships

A migration always has one Source site and one Destination site. The plugin runs on the Destination and pulls from the Source; it never writes to the Source. From the Destination's side, the Source it is paired with is its Connected site.

## Migration

### Source site
The WordPress site content is migrated *from*. It is the read-only origin of a migration — Safe Publish only reads from it (over the REST API) and never writes to it.

### Destination site
The WordPress site content is migrated *to*. The admin UI, import actions, and media library all operate here; it pulls content from the Source site.

### Connected site
The Source site a Destination is paired with, identified by its full configured URL (path included, which matters on subdirectory multisite). Authenticated requests from the Destination are signed and validated against this URL.

### Catalog
The Source site's enumerable listing of importable posts that the Destination browses, searches, and date-filters before importing. Distinct from a full post fetch: the catalog returns lightweight listing items (id, title, link, status, dates), not post content.

### Import
The process of copying a Source post into the Destination — its content, sideloaded media, taxonomy, and author attribution. Runs as either a single import or a bulk import; re-importing an already-imported post is an update rather than a new draft. The single and bulk paths are kept behaviorally in sync.

### Diff preview
The Destination-side comparison between a Source post's incoming content and the current Destination version, rendered before an update so the editor can review changes.
*Avoid:* Difference
