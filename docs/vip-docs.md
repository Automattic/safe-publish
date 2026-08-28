<!-- Documentation for use on docs.wpvip.com -->
<!-- Proposed URL: https://docs.wpvip.com/wordpress/safe-publish/ -->
<!-- Parent section: WordPress on VIP -->
<!--
  NOTE: Single long-form page by request — covers the whole plugin on one
  page rather than splitting into per-feature articles. Voice and formatting
  follow docs.wpvip.com conventions; only the page length deviates.

  Cross-reference suggestions (pages that should link TO this page):
  - WordPress on VIP overview — add a card linking to "Safe Publish"
  - Environments / going to production — reference Safe Publish as a way to
    move editorial content from a non-production environment to production

  Pages this doc should link TO (inline links — docs team to confirm exact URLs):
  - "Roles and capabilities" → docs.wpvip.com roles/capabilities page
  - "Environments" → docs.wpvip.com environments page
  - "wp-config.php" reference on VIP

  Product labels and behavior were verified against the current admin
  implementation during the 2026-08 documentation review.
-->

<!--
  Documentation update (2026-06-03): Added import-state information, recorded
  that bulk actions are available, documented removal from Needs attention,
  and added the Test Connection button.
  Constant-backed connection settings are intentionally omitted from this
  VIP-facing draft; docs/concepts/authentication.md covers that implementation
  detail for non-VIP and local configuration.
-->

<!--
  Documentation update (2026-08-03): Reworked the Needs attention description
  for the reversible Ignore lifecycle — the Open | Ignored toggle and the
  Ignore / Un-ignore actions — and for the degradation Resolvable now /
  Waiting on import hint, with Retry now supporting bulk selection and a bulk
  run reporting an aggregate outcome.
-->

# Safe Publish

Safe Publish moves editorial content from a source WordPress site to a destination site over an authenticated connection, preserving the content's structure and format as closely as possible. It is built for teams that draft, stage, or review content on one environment and need to publish that content to another without exporting databases or copying files by hand.

Safe Publish provides a controlled path to move content from the source to the destination via the WordPress Admin dashboard, compares changed source and destination content, imports one post or many in a single operation, and rolls back eligible imported posts. Every action is recorded in an audit log. Bulk imports share one history session, but rollback is applied to the selected posts rather than atomically reversing a whole session.

This page covers connecting two sites, browsing and importing content, using Compare, rolling back imports, and how media and audit logging behave. Full developer documentation and the source code are available on GitHub.

## How Safe Publish works

Safe Publish runs on both the source and the destination site, with each site assigned a role through its sync mode:

- The **source site** holds the content to be published. It is usually a non-production environment — staging, a sandbox, or a separate editorial site. The source exposes its content through authenticated REST API endpoints (the catalog).
- The **destination site** pulls content from the source. It is usually the production site. The destination owns the imported posts, media, import history, and audit log.

Content moves in one direction per operation: the destination requests content from the source's catalog, the source returns it, and the destination creates or updates local posts. Requests between the two sites are signed with a shared secret using Hash-based Message Authentication Code (HMAC) so that only the paired sites can exchange content.

Safe Publish imports content snapshots on demand. It does not run on a schedule, does not sync in real time, and does not copy plugins, themes, or site configuration — only posts, pages, custom post types, their media, and their taxonomy terms.

### Key concepts

The plugin uses a small set of terms consistently throughout its interface and this document:

- **Source site** — the site content is published from. Configured on the destination as the connected site URL.
- **Destination site** — the site content is published to. This is the site where the import is performed.
- **Sync mode** — a per-site setting that determines whether a site acts as a source (`export`), a destination (`import`), or both (`bidirectional`).
- **Catalog** — the list of posts available on the source site, served through a REST API endpoint and browsed from the destination.
- **Import** — creating a destination draft or updating an existing imported post. A bulk run records several items in one history session.
- **Compare** — a side-by-side comparison of fresh source content against the current destination post.
- **Roll back** — reversing the latest eligible import for a selected post. Rolling back a created post deletes it. Rolling back an updated post restores the previous content when it was captured, or deletes the post when it was not.

## Requirements

To use Safe Publish, the following must be in place:

- WordPress 6.9 or higher on both the source and destination sites.
- PHP 8.2 or higher on both sites.
- The Safe Publish integration enabled on both sites.

The integration handles creating and setting the shared secret that secures the connection between the sites. The secret is generated and synchronized automatically when you set the connected site URL (see [Connecting two sites](#connecting-two-sites)); it is never entered by hand and is not exposed in the VIP Dashboard.

## Roles and permissions

Access to every Safe Publish admin screen — browsing source content, importing, reviewing imports and exports, and rolling back — requires the `manage_options` capability. In a default WordPress installation, only administrators have this capability.

Two further checks apply during an import:

- Updating an existing post requires the `edit_post` capability for that specific post.
- Comparing an existing post with its source requires the `edit_post` capability for that post. A direct API caller without `manage_options` must also have the post type's `edit_posts` capability.

Requests from the destination to the source site are not authorized by user capabilities. Instead, each cross-site request is authenticated with the shared secret.

## Connecting two sites

With the integration enabled, pair the source and destination by configuring the connection and sync mode on each site. The shared secret is handled for you: setting the connected site URL on one site provisions the shared secret and copies it to the connected site automatically, so both ends always hold the same value. You never enter, copy, or paste the secret yourself; it is not a configurable input.

Open the Safe Publish settings screen in WP Admin and set:

- **Connected site URL** — the URL of the other site. On the destination, this is the source's URL.
- **Sync mode** — the role this site plays:

| Sync mode | Behavior |
| --- | --- |
| `export` | The site acts as a source only. It exposes its catalog endpoints but provides no import interface. |
| `import` | The site acts as a destination only. It provides the full import interface but does not expose its catalog. |
| `bidirectional` | The site acts as both source and destination. |

- **Basic authentication username and password** (optional) — credentials for HTTP Basic authentication, if the source site is protected by it. These are sent with requests to the source in addition to the signed shared secret.

A typical setup pairs a staging site in `export` mode with a production site in `import` mode. Use `bidirectional` only when content genuinely needs to move both ways.

After saving, use the **Test Connection** button on the settings screen to confirm the destination can reach and authenticate with the source before importing. The button is available in `import` and `bidirectional` modes and reports the result inline.

## Browsing and managing posts

With the connection configured, **Safe Publish → Manage** opens a unified Posts listing for source content and previous destination imports. Use the **Type**, **Title or URL**, date, status, and **Local State** controls to filter the table.

The Local State control provides four views:

- **All** — every source post, including imported posts.
- **Not imported** — the post does not currently have an active destination import.
- **Up to date** — the post has been imported and the source has not changed since.
- **Outdated** — the post has been imported, but the source has changed since the last import. Re-import to bring the destination copy up to date.

A failed live source comparison is labeled **Sync check failed** on the row; it is not a separate Local State.

## Importing content

Safe Publish supports importing a single post or many posts at once. Both paths produce the same result for each post: media is downloaded to the destination, source URLs in the content are rewritten to point at the destination, taxonomy terms and metadata are applied, and the operation is recorded and can be rolled back.

### Import a single post

1. On **Manage → Posts**, use the **Import** row action.
2. The import starts right away. If the destination post is already published, confirm the overwrite first, because it changes the live site immediately.

If a post with the same source post ID already exists on the destination, Safe Publish updates that post rather than creating a duplicate. If no matching post exists, it creates a new one.

By default, importing a child post fails if its parent is not present on the destination, to avoid creating orphaned content. Developers can change this with the `safe_publish_import_allow_orphans` filter (see [Filters](#filters)).

### Import multiple posts (bulk import)

1. On **Manage → Posts**, select the posts to import.
2. Start the bulk import.

Safe Publish records the selected posts in one history session and reports each item's result independently. A failure does not roll back successful siblings. When the selection includes posts with parent–child relationships, the plugin orders the import so that parents are created before their children.

## Reviewing and updating imported content

The **Manage** page has two tabs:

- **Posts** — the unified listing, scoped to the connected source site. Changing the connection hides the previous site's imported rows without deleting them. Reconnecting with a URL that has the same scheme, host, port, and path brings them back. A trailing slash, query, or fragment does not affect the stored source identity. Depending on a row's Local State, it offers View source, Import, Compare, Edit, Trash, and Roll back. View source opens the source post in a new tab when its permalink is not the source homepage. Import, Trash, and Roll back support bulk selection; Compare is available for one outdated post at a time.
- **Needs attention** — post-import problems in one place: failures (the import errored, so no local post was created, or a re-import of an existing post failed) and degradations (the post imported but something could not be carried over — an unresolved block, term, parent, or navigation reference, an unregistered taxonomy, or term fields that could not be reconciled with the source). This tab is also scoped to the connected source site. An **Open | Ignored** toggle switches between the active list and items set aside with Ignore; the tab count always reflects the Open set. Each degradation whose target an import can supply carries a **Resolvable now** or **Waiting on import** hint, indicating whether its target is imported yet. A degradation only a site change can fix, such as an unregistered taxonomy, offers no Retry. Remove permanently deletes a failure record and remains available for failures in the Ignored view. Retry re-runs supported reconciliation; a bulk Retry reports how many issues resolved, are still waiting on an import, or failed. Ignore and Un-ignore provide a reversible way to set items aside. These actions support bulk selection where applicable.

### Previewing changes with Compare

The Compare action on **Manage → Posts** fetches fresh source content and compares it with the current destination post, covering the title, content, excerpt, featured image, metadata, and taxonomy terms, including each term's parent and description. It is shown side by side, and block-editor content is compared block by block so editors can see which blocks were added, removed, or changed. Terms the source sends to preserve the hierarchy appear in a separate **Related hierarchy terms** block. A taxonomy this site registers and the source does not send is not reported as a removal, since the import does not touch it. Any difference shown that the import would not apply is noted under the comparison: a term difference names the term and the field it affects, and a taxonomy this site does not register names the taxonomy. The modal also offers an **Update** button that re-imports the post from the source.

### Rolling back imports

Rollback reverses a single import:

- If the post was newly created by the import, the post is deleted.
- If the post was an update of an existing post, the previous content is restored when it was captured; otherwise, the post is deleted.

It's important to note that the roll-back rolls back the specific changes from that single import. If a post has gone through a series of changes, each change can be rolled back sequentially.

Multiple rows can be selected on the Posts tab and rolled back in a single action.

Safe Publish normally stores a pre-update snapshot in the import record, so it can restore an updated post without contacting the source site. If an eligible updated row has no captured snapshot, rollback deletes the post instead.

Note: Rolling back a newly created post deletes that post on the destination. Confirm the affected posts before rolling back, since the action is irreversible for newly created posts.

## How media and content are processed

When a post is imported, Safe Publish processes its content so that it renders correctly on the destination without depending on the source site:

- **Featured images and inline media** referenced from the source site are downloaded and added to the destination's media library. This includes media in `img`, `video`, `audio`, and `source` elements, and file links in classic HTML and in block markup.
- **Source URLs are rewritten** to point at the destination's copies, so the imported content no longer links back to the source.
- **Media is deduplicated** by its original URL. If the same source file has already been imported, Safe Publish reuses the existing attachment on the destination instead of downloading it again.
- **Third-party media is left untouched.** Files hosted on domains other than the source site are not downloaded; their original URLs are preserved as written.

Safe Publish records where imported content came from in post metadata — including the source post ID and source permalink — so that subsequent imports of the same post update the existing post rather than duplicating it.

## Author attribution

Safe Publish attributes each imported post to a user on the destination site, matched by the source author's email address. The matched user becomes the imported post's author.

If the source author's email does not match any user on the destination, Safe Publish does not guess. By default, the import stops with an error identifying the unmatched author, so an administrator can create a matching user and re-import. Developers can relax this with the `safe_publish_import_allow_author_fallback` filter: when enabled, a new post whose author cannot be matched is attributed to the user running the import, an updated post keeps its existing author, and a warning is recorded in the import history.

The source author's email and login are also stored in post metadata for reference, regardless of how attribution is resolved.

## Audit events

Safe Publish writes an audit log of security- and content-relevant actions to the destination site. Logged events include authenticated cross-site requests, content exports, the result of each imported item, rollbacks, and changes to the connection settings.

Each event records a channel (such as authentication, content, export, import, or media), a severity level (informational, warning, or error), the event type, a timestamp, and structured details. The export history is surfaced in the admin interface; the full log is available to developers through the plugin's query interface and through the `safe_publish_event_logged` action (see [Filters](#filters)).

## Custom post types

Safe Publish imports any registered post type, not only posts and pages. The source catalog can be filtered by post type, and custom post types are imported the same way as standard ones.

Because a custom post type's REST API base can differ from its registered slug, Safe Publish resolves the correct REST base for each post type on the source site before fetching its content.

A custom post type must be registered with the same slug on the destination for its content to import cleanly. Safe Publish does not remap a source post type slug to a different slug on the destination; if the slugs differ, the import will not place the content under the destination's post type.

## Filters

Safe Publish exposes the following filters for developers. Add them in a theme or a small companion plugin.

| Filter | Default | Purpose |
| --- | --- | --- |
| `safe_publish_import_kses` | `false` | Enable `wp_kses` sanitization of imported content. |
| `safe_publish_import_kses_allowed_html` | `wp_kses_allowed_html( 'post' )` | Customize the allowed HTML used when `safe_publish_import_kses` sanitization is enabled. Receives the allowed-tags array and the name of the field being sanitized. |
| `safe_publish_import_allow_orphans` | `false` | Allow importing a child post when its parent is not present on the destination. |
| `safe_publish_import_allow_author_fallback` | `false` | When the source author cannot be matched on the destination, attribute new posts to the importing user and keep the existing author on updates, instead of aborting the import. |
| `safe_publish_auth_max_time_diff` | `300` | Maximum allowed difference, in seconds, between a signed request's timestamp and the current time. |
| `safe_publish_request_timeout` | `10` | Timeout, in seconds, for HTTP requests to the source site. |
| `safe_publish_request_args` | — | Customize the arguments passed to the HTTP request made to the source site. |
| `safe_publish_dev_ssl_verify` | `false` | Development only: skip SSL verification for requests to non-localhost hosts. Leave disabled in production. |

The `safe_publish_event_logged` action fires each time an audit event is recorded, receiving the channel, event type, and event data. Use it to forward audit events to external monitoring.

```php
add_action(
	'safe_publish_event_logged',
	function ( string $channel, string $event, array $data ): void {
		// Forward the event to an external log or monitoring service.
	},
	10,
	3
);
```

## Limitations

Safe Publish is scoped to publishing content between two paired sites. The following are not supported:

- **Scheduled or automatic sync.** Imports are performed manually; there is no cron-based synchronization.
- **Real-time, two-way sync.** Content moves as on-demand snapshots in one direction per operation, even when both sites are in `bidirectional` mode.
- **Plugin, theme, or configuration transfer.** Only content — posts, pages, custom post types, their media, and their taxonomy terms — is imported.
- **Third-party media import.** Media referenced in post content and hosted off the source domain is left as-is; its URLs are not rewritten or localized. Featured images are an exception — they are downloaded regardless of serving host.
- **Importing children of missing parents.** By default, importing a child post whose parent is absent on the destination fails, unless `safe_publish_import_allow_orphans` is enabled.

## Troubleshooting

### Check the authentication status

Safe Publish registers a "Safe Publish Authentication Configuration" test under **Tools → Site Health**. The test reports whether the shared secret is configured, too short (it recommends at least 32 characters), or set up correctly. Check it first when cross-site requests fail, before investigating other causes. On the destination, the **Test Connection** button on the Safe Publish settings screen performs a live check against the configured source and reports the result inline.

### Requests fail with a timestamp error

Cross-site requests are rejected when the source and destination clocks differ by more than the allowed window (300 seconds by default). Correct the system time on both servers. If a larger tolerance is genuinely required, raise it with the `safe_publish_auth_max_time_diff` filter.

### An import failed with a missing-parent error

The post's parent was not present on the destination. Import the parent first, or enable `safe_publish_import_allow_orphans` to allow importing the child without its parent.

### An import failed with an author error

Safe Publish attributes each imported post to a destination user matched by the source author's email. Two cases stop an import:

- **The source author was not found on the destination.** No destination user has the source author's email. Create a user with that email on the destination and re-import, or enable the `safe_publish_import_allow_author_fallback` filter to attribute unmatched new posts to the importing user.
- **The source author could not be determined.** The source post has no author, or its author was deleted on the source site. This is a source-side data issue and is not covered by the author-fallback filter. Restore or reassign the author on the source, then re-import.

### Imported media did not transfer

For media referenced in content, confirm it is hosted on the source site's domain — such third-party media is intentionally left in place and not downloaded. Featured images are downloaded regardless of host, so this does not apply to them. Media download and sideload failures are recorded in the audit log's media channel, which can be reviewed to identify the specific files that failed.
