# Import Process

This guide explains how Safe Publish moves content from one WordPress site (source) to another (destination). Understanding this process helps you troubleshoot issues and optimize your workflow.

## Overview

The process consists of the following stages:

1. **Fetch** - Retrieve post data from source site
2. **Validate** - Check content integrity and structure
3. **Transform and Import Media** - Process content, download media, replace URLs
4. **Create Post** - Generate the draft post on the destination
5. **Track** - Log the import action on the destination

For error resolution at any stage, see the [Troubleshooting guide](../troubleshooting.md).

## Stage 1: Fetch

### What Happens

- Safe Publish sends a request to the source site's REST API
- The endpoint `/wp-json/wp/v2/{post_type}/{post_id}` is queried
- Response is received

### Parameters Sent

- `_embed` - Embeds related data; the plugin extracts term data (categories, tags, custom taxonomies) from the response
- `context=edit` - Retrieves complete post data, including drafts (only sent when authentication is configured)

## Stage 2: Validate

### What Happens

- **URL** confirms the source site URL is properly formatted
- **Authentication** verifies that credentials are provided and are correct
- **Post data** ensures the response is valid JSON, includes required fields, uses a supported post type, and has non-empty content
- **Content sanitization** to strip dangerous HTML and scripts
- **Media** to check supported file types and downloadability

See the [Content Validation](validation.md) guide for detailed information.

## Stage 3: Transform and Import Media

### Content Parsing

There are different parsing operations for different types of content.

- Core blocks (image, gallery, video, audio, embed, HTML, paragraph, heading, list, quote) each have dedicated processing.
- Custom and third-party blocks have their attributes walked recursively.
- Media URLs, and their innerHTML is processed for media elements.
- For classic blocks or non-block content, HTML is processed using WordPress' HTML API (`WP_HTML_Tag_Processor`).

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

1. **Resolve**: The source site's URL is added to Relative URLs
2. **Normalize**: Query parameters are stripped, but stored
3. **Filter**: Third-party domain URLs are left unchanged
4. **Deduplicate**: If the URL was already imported, the existing attachment URL is used, and download is skipped
5. **Download**: File fetched using WordPress core's `download_url()`
6. **Import**: File validated and added to the media library via `media_handle_sideload()`
7. **Replace**: Source URL replaced with the new attachment URL in content, previously stripped query paramaters are reapplied

### Featured Image

- Fetched separately via the `/wp-json/wp/v2/media/{id}` endpoint using the `featured_media` ID from the post response
- Uploaded to media library
- Set as post thumbnail via `set_post_thumbnail()`

### URL Replacement

After media processing, all remaining source-domain URLs in the content are replaced with the destination site URL. This catches URLs outside media elements, such as normal links, block comment attributes, and text references.

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
  - **Post Meta**: meta available via REST is transferred, see below for more details
  - **Terms**: tags and categories are transferred and created if they don't exist, custom taxonomies have availbe by REST are transfered if they exist, see below for more details
- Additional Post meta stored:
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

The source Custom Post Type (CPT) must be registered with `'show_in_rest' => true` to be available for import. The CPT must also exist on the destination site, and the importing user must have the capability to create posts of that type.

### Post Meta

Source post meta exposed via the REST API is imported automatically. Custom fields must be registered with `'show_in_rest' => true` (or exposed via `register_rest_field()`) on the source site to be included in the API response.

ACF fields are not exposed by default; they require ACF's REST API setting to be enabled. No field registration is needed on the destination — any meta key can be stored.

### Terms

Source terms (categories, tags, and custom taxonomies) are synced. Terms that don't exist on the destination are created automatically.

Custom taxonomies must be registered with `'show_in_rest' => true` on the source site and must exist on the destination site — a missing custom taxonomy causes the import to fail.

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
