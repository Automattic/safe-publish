# Import Process

This guide explains how Safe Publish imports content from external WordPress sites. Understanding this process helps you troubleshoot issues and optimize your workflow.

## Overview

The import process consists of six main stages:

1. **Fetch** - Retrieve post data from external site
2. **Validate** - Check content integrity and structure
3. **Transform** - Process content and extract media references
4. **Import Media** - Download and attach images
5. **Create Post** - Generate the draft post
6. **Track** - Log the import action

For error resolution at any stage, see the [Troubleshooting guide](../troubleshooting.md).

## Stage 1: Fetch

### What Happens

- Safe Publish sends an authenticated request to the external site's REST API
- The endpoint `/wp-json/wp/v2/{post_type}/{post_id}` is queried
- Additional data is embedded in the response (featured image, author)
- Response is received and decoded

### Parameters Sent

- `_embed` - Includes embedded data (author, featured media)
- `context=edit` - Retrieves complete post data including drafts (only sent when authentication is configured)

## Stage 2: Validate

### What Happens

- Post data structure is validated
- Required fields checked (`id`, `title`, `content`, `link`)
- Content format validated (HTML/blocks)
- Image URLs verified for accessibility

See the [Content Validation](validation.md) guide for detailed information.

## Stage 3: Transform

### What Happens

- HTML content is parsed using DOMDocument
- `<img>` `src` and `srcset` attributes are processed
- `<picture>/<source>` `srcset` attributes are processed
- Relative and protocol-relative URLs made absolute
- `<a>` href relative URLs resolved to absolute
- `<iframe>` and `<video>` sources resolved
- Content prepared for import

### URL Transformation

All URLs in content are processed:

```php
// Relative URLs converted to absolute
./image.jpg → https://source-site.com/image.jpg

// Protocol-relative URLs made absolute
//cdn.example.com/img.jpg → https://cdn.example.com/img.jpg

// Query parameters preserved
image.jpg?w=800&h=600 → https://source-site.com/image.jpg?w=800&h=600
```

## Stage 4: Import Media

### What Happens

For each image found:

1. **Download**: Image fetched from source URL
2. **Validate**: File type and size checked
3. **Upload**: Image added to media library
4. **Attach**: Image attached to the post
5. **Replace**: Content URLs updated to new location

### Featured Image

- Fetched separately using `_embedded['wp:featuredmedia']`
- Uploaded to media library
- Set as post thumbnail via `set_post_thumbnail()`

### Inline Images

- Extracted from content during transform stage
- Downloaded sequentially
- Original URLs replaced with new attachment URLs

### Performance Considerations

- Images downloaded using WordPress core's `download_url()`
- Image download timeout uses WordPress core's default (300 seconds); the `safe_publish_request_timeout` filter applies only to REST API requests, not image downloads
- Failed images do not stop the import; the original URL is preserved

## Stage 5: Create Post

### What Happens

- New post created with `wp_insert_post()`
- Post data set:
  - **Title**: From source post title
  - **Content**: Transformed content with updated URLs
  - **Status**: Always `draft`
  - **Post type**: Same as source post
- Post meta stored:
  - `safe_publish_external_post_id` — post ID on the source site
  - `safe_publish_external_link` — URL of the source post
  - `safe_publish_imported_from` — plugin identifier (`safe-publish`)
  - `safe_publish_import_date` — timestamp of the import

### Post Status

All imported posts are created as **drafts** to allow review before publishing. This is intentional and cannot be changed (for safety).

### Author Attribution

The post author is set to the user who performed the import, not the original author. This ensures proper attribution in the destination site.

### Custom Fields

Only the plugin's own tracking meta is stored automatically. Additional post meta is not imported; importing extra fields requires custom development.

## Stage 6: Track

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

1. Each post goes through all six stages individually
2. Failures in one post don't stop others
3. Results aggregated and reported
4. Import history updated for each post

### Performance

- Processes one post at a time (no parallel processing)
- Time limit extended to avoid PHP timeouts
- Each import takes 5-30 seconds depending on:
  - Post content size
  - Number of images
  - Network speed
  - External site response time

## Error Handling

### Graceful Degradation

The plugin handles errors gracefully:

- **Media failures**: Post still imported without images
- **Meta failures**: Post imported without custom fields
- **Network timeouts on image downloads**: No automatic retry; the import continues and the original URL is preserved
- **Network timeouts on API requests**: On WordPress VIP, `vip_safe_wp_remote_get` automatically retries up to 3 times before failing. On non-VIP environments, there is no retry.

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
