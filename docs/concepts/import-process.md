# Import Process

This guide explains how Safe Publish imports content from external WordPress sites. Understanding this process helps you troubleshoot issues and optimize your workflow.

## Overview

The import process consists of the following stages:

1. **Fetch** - Retrieve post data from external site
2. **Validate** - Check content integrity and structure
3. **Transform and Import Media** - Process content, download media, replace URLs
4. **Create Post** - Generate the draft post
5. **Track** - Log the import action

For error resolution at any stage, see the [Troubleshooting guide](../troubleshooting.md).

## Stage 1: Fetch

### What Happens

- Safe Publish sends a request to the external site's REST API
- The endpoint `/wp-json/wp/v2/{post_type}/{post_id}` is queried
- Response is received and decoded

### Parameters Sent

- `_embed` - Embeds related data; the plugin extracts term data (categories, tags, custom taxonomies) from the response
- `context=edit` - Retrieves complete post data including drafts (only sent when authentication is configured)

## Stage 2: Validate

### What Happens

- Post data structure is validated
- Required fields checked (`id`, `title`)

See the [Content Validation](validation.md) guide for detailed information.

## Stage 3: Transform and Import Media

### Content Parsing

For Gutenberg content, blocks are parsed individually. Core blocks (image, gallery, video, audio, embed, HTML, paragraph, heading, list, quote) each have dedicated processing. Custom and third-party blocks have their attributes walked recursively for media URLs, and their innerHTML is processed for media elements.

For non-Gutenberg (classic) content, HTML is processed in a single pass using WordPress' HTML API (`WP_HTML_Tag_Processor`).

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

For `<a>` elements, only URLs whose path ends in a file extension allowed by WordPress are processed. Page links are left untouched.

### What Is Not Processed

- URLs inside `<style>` blocks or inline `style` attributes (e.g., CSS `background-image`)
- URLs inside `<script>` blocks
- Content inside HTML comments
- Content inside `<textarea>` elements

### URL Handling

- Relative URLs are resolved against the source site URL before download
- Query parameters are stripped for download and deduplication, then reapplied to the replacement URL
- Third-party URLs (different domain than the source site) are left unchanged

### For Each Media URL Found

1. **Resolve**: Relative URLs resolved to absolute internally for download
2. **Filter**: Third-party domain URLs are left unchanged
3. **Deduplicate**: If the URL was already imported, the existing attachment URL is used and download is skipped
4. **Download**: File fetched using WordPress core's `download_url()`
5. **Import**: File validated and added to the media library via `media_handle_sideload()`
6. **Replace**: Source URL replaced with the new attachment URL in content

### Featured Image

- Fetched separately via the `/wp-json/wp/v2/media/{id}` endpoint using the `featured_media` ID from the post response
- Uploaded to media library
- Set as post thumbnail via `set_post_thumbnail()`

### URL Replacement

After media processing, all remaining source-domain URLs in the content are replaced with the destination site URL. This catches URLs outside media elements, such as page links, block comment attributes, and text references.

### Performance

- Media files downloaded using WordPress core's `download_url()`
- Media downloads are not affected by the [`safe_publish_request_timeout`](../extending/hooks.md#safe_publish_request_timeout) filter and use WordPress core's default timeout

## Stage 4: Create Post

### What Happens

- New post created with `wp_insert_post()`
- Post data set:
  - **Title**: From source post title
  - **Content**: Transformed content with updated URLs
  - **Slug**: From source post slug (WordPress appends `-2`, `-3`, etc. if the slug already exists)
  - **Status**: Always `draft`
  - **Post type**: Same as source post
- Post meta stored:
  - `safe_publish_external_post_id` — post ID on the source site
  - `safe_publish_external_link` — URL of the source post
  - `safe_publish_imported_from` — plugin identifier (`safe-publish`)
  - `safe_publish_import_date` — timestamp of the import

### Post Status

All imported posts are created as **drafts** to allow review before publishing. This is intentional and cannot be changed (for safety).

### Excluded Fields

Some source post fields are not migrated:

- **Author**: Set to the importing user.
- **Date**: Not preserved; the destination site uses its own publish date.
- **Parent**: Parent/child relationships (mainly pages) are not mapped across sites.

### Custom Post Types

The source CPT must be registered with `'show_in_rest' => true` to be available for import. The CPT must also exist on the destination site, and the importing user must have the capability to create posts of that type.

### Custom Fields

Source post meta exposed via the REST API is imported automatically alongside the plugin's own tracking meta. Custom fields must be registered with `'show_in_rest' => true` (or exposed via `register_rest_field()`) on the source site to be included in the API response.

ACF fields are not exposed by default; they require ACF's REST API setting to be enabled. No field registration is needed on the destination — any meta key can be stored.

### Terms

Source terms (categories, tags, and custom taxonomies) are synced. Terms that don't exist on the destination are created automatically.

Custom taxonomies must be registered with `'show_in_rest' => true` on the source site and must exist on the destination site — a missing taxonomy causes the import to fail.

## Stage 5: Track

### What Happens

- Import event logged to database
- Record includes:
  - Timestamp
  - Source URL and post ID
  - Destination post ID
  - User who performed import
  - Import status (success/failure)
  - Error message (if failed)
- Import logged to History (session and per-item log entries)

See [History](history.md) for more details.

## Bulk Import

Bulk imports process multiple posts sequentially:

1. Each post goes through all stages individually
2. Failures in one post don't stop others
3. Results aggregated and reported
4. Import history updated for each post

### Performance

- Processes one post at a time (no parallel processing)
- Bulk imports are capped at 50 posts per request

## Error Handling

### Failure Behavior

- **Inline media download failures**: Import is aborted; any attachments already created during the run are deleted
- **Featured image failures**: Import is aborted
- **Meta/term failures**: Import is aborted; for new posts, the post and its attachments are deleted. For updates, the post is rolled back to its pre-update state
- **Network timeouts on API requests**: No automatic retry; on WordPress VIP, consecutive failures will temporarily block further requests for up to 20 seconds to protect performance

### Error Reporting

Errors are reported in multiple places:

1. **Admin notice**: Immediate feedback in UI
2. **History**: Logged for later review
3. **JavaScript console**: Detailed debugging info
4. **PHP error log**: Server-side errors

## Best Practices

### Before Importing

1. **Test connection** to verify authentication
2. **Preview content** using Post Diff
3. **Check image accessibility** in preview
4. **Verify post type** is correct

### During Import

1. **Monitor progress** for errors
2. **Don't close the browser** during bulk imports
3. **Check History** periodically

### After Import

1. **Review draft posts** before publishing
2. **Verify images** imported correctly
3. **Check formatting** matches source
4. **Test internal links** if present

## Next Steps

- [Content Validation](validation.md) - Understanding validation
- [History](history.md) - Tracking imports
- [Troubleshooting](../troubleshooting.md) - Common issues
- [Hooks and Filters](../extending/hooks.md) - Customization options
