# Import Process

This guide explains how Safe Publish moves content from one WordPress site (source) to another (destination). Understanding this process helps you troubleshoot issues and optimize your workflow.

## Overview

The process consists of the following stages:

1. **Fetch** - Retrieve post data from source site.
2. **Validate** - Check content integrity and structure.
3. **Transform and Import Media** - Process content, download media, replace URLs.
4. **Create Post** - Generate the draft post on the destination.
5. **Track** - Log the import action on the destination.

For error resolution at any stage, see the [Troubleshooting guide](../troubleshooting.md).

## Stage 1: Fetch

**Prerequisite:** Imports require an authenticated connection to the source. The plugin disables import actions and shows a status banner when credentials are missing or don't work. See [Authentication](authentication.md) for setup.

### What Happens

- The source site URL is validated to ensure it is properly formatted.
- Safe Publish sends a request to the source site's REST API.
- The endpoint `/wp-json/wp/v2/{post_type}/{post_id}` is queried.
- Response is received.
- Non-HTML fields (title, slug, statuses, etc.) are sanitized using WordPress' standard helpers (`sanitize_text_field`, `esc_url_raw`, `absint`); content and excerpt are kept raw for Stage 3.

### Parameters Sent

- `_embed` - Embeds related data; the plugin extracts term data (categories, tags, custom taxonomies) from the response.
- `context=edit` - Retrieves complete post data, including drafts.

## Stage 2: Validate

### What Happens

- **Authentication** verifies that credentials are provided and are correct.
- **Post data** ensures the response is valid JSON, includes required fields, and uses a supported post type.

See the [Content Validation](validation.md) guide for detailed information.

## Stage 3: Transform and Import Media

### Media Discovery

Safe Publish scans post content to find media files that must be copied from the source to the destination. Different content types are scanned differently.

- Core blocks (image, gallery, video, audio, embed, HTML, paragraph, heading, list, quote) each have a dedicated parser that knows where media lives in that block's structure.
- Custom and third-party blocks have their attributes scanned for media URLs, and their inner HTML is scanned for media elements (see the table below).
- Classic blocks and non-block content are scanned using WordPress' HTML API (`WP_HTML_Tag_Processor`).

### Media Elements Processed

The following element/attribute combinations are processed:

| Element    | Attributes          |
| ---------- | ------------------- |
| `<img>`    | `src`, `srcset`     |
| `<video>`  | `src`, `poster`     |
| `<audio>`  | `src`               |
| `<source>` | `src`, `srcset`     |
| `<a>`      | `href` (files only) |
| `<embed>`  | `src`               |
| `<object>` | `data`              |

If an `<a>` tag ends in a file extension allowed by WordPress, it is processed in this step. Regular links are processed later.

### What Is Not Processed

- URLs inside `<style>` blocks or inline `style` attributes (e.g., CSS `background-image`)
- URLs inside `<script>` blocks
- Content inside HTML comments
- Content inside `<textarea>` elements

### For Each Media URL Found

1. **Resolve**: Relative URLs (URLs without a domain) are converted into absolute URLs by prepending the source site's base URL (scheme and host).
2. **Normalize**: Query parameters are removed from the working URL; they are reapplied from the original URL in step 7.
3. **Filter**: Third-party domain URLs are left unchanged.
4. **Deduplicate**: If the URL was already imported, the existing attachment URL is used, and download is skipped.
5. **Download**: File fetched using WordPress core's `download_url()`; downloadability is verified at this point.
6. **Import**: File type is validated and added to the media library via `media_handle_sideload()`.
7. **Replace**: Source URL replaced with the new attachment URL in content; previously stripped query parameters are reapplied.

### Featured Image

- Fetched separately via the `/wp-json/wp/v2/media/{id}` endpoint using the `featured_media` ID from the post response.
- Uploaded to media library.
- Set as post thumbnail via `set_post_thumbnail()`.

### URL Replacement

After media processing, all remaining source-domain URLs in the content are replaced with the destination site URL. This catches URLs outside media elements, such as normal links, block comment attributes, and text references.

### Content and Excerpt Sanitization

By default, no sanitization is applied to the post content or excerpt; both fields are preserved verbatim, matching WordPress core importer behavior. The optional `safe_publish_import_kses` filter enables a safety check that aborts the import if `wp_kses` would modify either field, rather than silently stripping HTML.

### Performance

- Media files downloaded using WordPress core's `download_url()`.
- Media downloads are not affected by the [`safe_publish_request_timeout`](../extending/hooks.md#safe_publish_request_timeout) filter and use WordPress core's default timeout.

## Stage 4: Create Post

### What Happens

- New post created with `wp_insert_post()`.
- Post data set:
  - **Title**: From source post title
  - **Content**: Transformed content with updated URLs
  - **Slug**: From source post slug (WordPress appends `-2`, `-3`, etc. if the slug already exists)
  - **Status**: Always `draft`
  - **Post type**: Same as source post
  - **Post Meta**: meta available via REST is transferred, see below for more details.
  - **Terms**: tags and categories are transferred. If they don't exist, they are created. Custom taxonomies that appear in a REST request are transferred if they exist. See below for more details.
- Additional Post meta stored:
  - `safe_publish_source_post_id` — post ID on the source site
  - `safe_publish_source_link` — URL of the source post
  - `safe_publish_imported_from` — plugin identifier (`safe-publish`)
  - `safe_publish_import_date_gmt` — GMT timestamp of the import (`Y-m-d H:i:s`)

### Post Status

New posts are always created as **drafts** to allow review before publishing. This is intentional. When updating an existing post via bulk import, the current status is preserved to avoid silently unpublishing live content.

### Excluded Fields

Source post publish date is not preserved; the destination site uses its own publish date.

### Author Resolution

By default, Safe Publish requires the source post's author to exist on the destination site; authors are matched by email (`get_user_by( 'email', ... )`).

In the event of no match, the import is aborted with an error message, so the operator can create the user and re-import.

For diagnostics, every imported post stores two private meta values:

- `_safe_publish_source_author_email` — the source author's email at import time.
- `_safe_publish_source_author_login` — the source author's login at import time.

On updates, the destination's `post_author` and the two private meta values above are refreshed to reflect the source's current author. The audit trail of historical values lives in the per-item history.

No capability check is applied to the matched user: WordPress does not enforce capabilities on `post_author`, and a Subscriber on the destination who matches by email is legitimately attributed as the author of an imported post.

#### Author Fallback

Author resolution can be relaxed via the [`safe_publish_import_allow_author_fallback`](../extending/hooks.md#safe_publish_import_allow_author_fallback) filter. With the fallback enabled, new posts with an unmatched author are attributed to the importing user.

For updates with an unmatched author, the destination's existing `post_author` is preserved unchanged.

Whenever the fallback is applied, a warning is recorded on the items table row and surfaced in the import results UI.

The import still aborts when the source post has no resolvable author (e.g., the author was deleted on the source).

### Parent Resolution

For hierarchical post types (pages and any custom post type registered with `'hierarchical' => true`), the source post's parent is matched against destination posts using the `safe_publish_source_post_id` meta lookup — the same lookup that determines whether a source post is already imported.

- **Top-level source posts** (source `parent = 0`) are imported as top-level on the destination. No resolution is performed.
- **Non-hierarchical post types** ignore the source `parent` entirely.
- **Match found**: `post_parent` is set to the destination post ID.
- **No match (strict default)**: the import aborts with an error that identifies the unresolved parent. The error distinguishes "has not been imported on this site" (the parent was never imported and is not part of the current batch) from "failed to import earlier in this batch" (the parent was part of the bulk batch but did not succeed).

Bulk imports run in two passes. Pass 1 fetches each post's REST payload without writing to the database; pass 2 then processes the batch in topological order so the destination parent exists by the time its children look it up. Posts in a cycle (or whose parent is outside the batch) are processed at the end of pass 2 and route through the same unresolvable-parent path.

For diagnostics, every hierarchical post imported with a non-zero source parent stores one private meta value:

- `_safe_publish_source_post_parent_id` — the source post's parent ID at import time.

On updates, the meta is refreshed to reflect the current source state. The audit trail of historical values lives in the per-item history.

#### Orphan Fallback

Parent resolution can be relaxed via the [`safe_publish_import_allow_orphans`](../extending/hooks.md#safe_publish_import_allow_orphans) filter. With the fallback enabled, posts whose parent cannot be resolved are imported with `post_parent = 0` (top-level), and a warning is recorded on the items table row and surfaced in the import results UI.

### Custom Post Types

The source Custom Post Type (CPT) must be registered with `'show_in_rest' => true` to be available for import. The CPT must also exist on the destination site, and the importing user must have the capability to create posts of that type.

### Post Meta

Source post meta exposed via the REST API is imported automatically. Custom fields must be registered with `'show_in_rest' => true` (or exposed via `register_rest_field()`) on the source site to be included in the API response.

ACF fields are not exposed by default; they require ACF's REST API setting to be enabled. No field registration is needed on the destination — any meta key can be stored.

### Terms

Source terms (categories, tags, and custom taxonomies) are synced. Terms that don't exist on the destination are created automatically.

Custom taxonomies must be registered with `'show_in_rest' => true` on the source site and must exist on the destination site — a missing custom taxonomy causes the import to fail.

## Stage 5: Track

### What Happens

- Import event logged to database.
- Record includes:
  - Timestamp
  - Source URL and post ID
  - Destination post ID
  - User who performed import
  - Import status (success, updated, or error)
  - Error message (if failed)
- Import recorded in the imports and import items tables (one session row,
  one item per processed post). The Imports page surfaces this data; the
  imported posts list on its Posts tab and the failed items on its Failures
  tab.

See [Imports](imports.md) for more details.

## Bulk Import

Bulk imports process multiple posts sequentially:

1. Each post goes through all stages individually.
2. Failures in one post don't stop others.
3. Results aggregated and reported.
4. Imports table updated for each post; failed items appear on the Imports
   page Failures tab.

### Performance

- Processes one post at a time (no parallel processing).
- Bulk imports are capped at 50 posts per request.

## Error Handling

### Failure Behavior

- **Inline media download failures**: Import is aborted; any attachments already created during the run are deleted.
- **Featured image failures**: Import is aborted.
- **Meta/term failures**: Import is aborted; for new posts, the post and its attachments are deleted. For updates, the post is rolled back to its pre-update state.
- **Network timeouts on API requests**: No automatic retry; on WordPress VIP, consecutive failures will temporarily block further requests for up to 20 seconds to protect performance.

### Error Reporting

Errors are reported in multiple places:

1. **Admin notice**: Immediate feedback in UI
2. **Imports → Failures tab**: Logged for later review
3. **JavaScript console**: Detailed debugging info
4. **PHP error log**: Server-side errors

## Best Practices

### Before Importing

1. **Test connection** to verify authentication.
2. **Verify post type** is correct.

### During Import

1. **Monitor progress** for errors.
2. **Don't close the browser** during bulk imports.
3. **Check the Imports page** periodically.

### After Import

1. **Review draft posts** before publishing.
2. **Verify images** imported correctly.
3. **Check formatting** matches source.
4. **Test internal links** if present.

## Known Limitations

### Embedded posts may render as plain links

If an imported post embeds another imported post via a core Embed block, the embed can display as a fallback link instead of the rendered preview. This happens when WordPress caches the embed lookup while the embedded post is still a draft on the destination site. WordPress' Block Editor does not refresh this cache on subsequent saves, so the failure persists until the meta is cleared.

**To avoid**: publish embedded posts before the posts that reference them.

**To recover**: clear the affected post's `_oembed_*` post meta and re-visit the post on the front end:

```bash
wp post meta list <post-id> --fields=meta_key --format=csv \
  | grep '^_oembed' \
  | xargs -I{} wp post meta delete <post-id> {}
```

## Next Steps

- [Content Validation](validation.md) - Understanding validation
- [Imports](imports.md) - Managing imported content and failed imports
- [Troubleshooting](../troubleshooting.md) - Common issues
- [Hooks and Filters](../extending/hooks.md) - Customization options
