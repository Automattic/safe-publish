# Import Process

This guide explains how Compliant Content Publisher imports content from external WordPress sites. Understanding this process helps you troubleshoot issues and optimize your workflow.

## Overview

The import process consists of six main stages:

1. **Fetch** - Retrieve post data from external site
2. **Validate** - Check content integrity and structure
3. **Transform** - Process content and extract media references
4. **Import Media** - Download and attach images
5. **Create Post** - Generate the draft post
6. **Track** - Log the import action

## Stage 1: Fetch

### What Happens

- CCP sends an authenticated request to the external site's REST API
- The endpoint `/wp-json/wp/v2/{post_type}/{post_id}` is queried
- Additional data is fetched (featured image, author, meta data)
- Response is received and decoded

### Parameters Sent

- `_embed` - Includes embedded data (author, featured media)
- `context=edit` - Retrieves complete post data including drafts

### What Can Go Wrong

- **Authentication failure** - Credentials invalid or missing
- **Network timeout** - External site not responding
- **404 error** - Post not found or ID incorrect
- **Permission denied** - User lacks access to post

## Stage 2: Validate

### What Happens

- Post data structure is validated
- Required fields checked (`id`, `title`, `content`, `link`)
- Content format validated (HTML/blocks)
- Image URLs verified for accessibility

See the [Content Validation](validation.md) guide for detailed information.

### What Can Go Wrong

- **Missing required fields** - Incomplete post data
- **Invalid JSON** - Malformed response data
- **Broken images** - Image URLs not accessible
- **Invalid blocks** - Corrupted Gutenberg block syntax

## Stage 3: Transform

### What Happens

- HTML content is parsed using DOMDocument
- Gutenberg blocks are analyzed
- Image URLs extracted from:
  - `<img>` tags
  - `<figure>` elements
  - Block attributes (e.g., `wp:image` blocks)
- URLs converted to absolute paths
- Content prepared for import

### Block Processing

The plugin handles various block types:

- **Core blocks**: Paragraph, heading, image, gallery, etc.
- **Reusable blocks**: Maintained as references
- **Custom blocks**: Preserved with attributes
- **Nested blocks**: Hierarchies maintained

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

### What Can Go Wrong

- **Invalid HTML** - Parsing failures
- **Nested blocks corruption** - Complex block structures fail
- **URL parsing errors** - Malformed URLs
- **Character encoding issues** - Special characters corrupted

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
- Downloaded in batch (up to 10 concurrent)
- Original URLs replaced with new attachment URLs
- Alt text and titles preserved

### What Can Go Wrong

- **Download failures** - Source images not accessible
- **File size limits** - Images too large for PHP/WordPress limits
- **Upload errors** - Disk space or permissions issues
- **Unsupported formats** - File type not allowed
- **Timeout** - Too many images or slow downloads

### Performance Considerations

- Images downloaded using `wp_safe_remote_get()`
- Timeout set to 30 seconds per image
- Failed images logged but don't stop import
- Uses WordPress media functions (VIP-safe)

## Stage 5: Create Post

### What Happens

- New post created with `wp_insert_post()`
- Post data set:
  - **Title**: From source post title
  - **Content**: Transformed content with updated URLs
  - **Status**: Always `draft`
  - **Author**: Current user performing import
  - **Date**: Current date/time
  - **Post type**: Same as source post
- Metadata copied:
  - Categories (matched by name, created if missing)
  - Tags (created if they don't exist)
  - Excerpt (if present)
  - Featured image (from media import)
- Original source URL stored in post meta: `_ccp_source_url`

### Post Status

All imported posts are created as **drafts** to allow review before publishing. This is intentional and cannot be changed (for safety).

### Author Attribution

The post author is set to the user who performed the import, not the original author. This ensures proper attribution in the destination site.

### Custom Fields

Basic custom fields in post meta are imported. However, complex custom fields from plugins like ACF require additional development.

### What Can Go Wrong

- **Database errors** - Insert failures
- **Permission issues** - User cannot create posts of that type
- **Duplicate content** - Post might be imported twice
- **Meta data loss** - Complex meta fields not supported

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
- Entry added to Import History table

See [Import History](import-history.md) for more details.

### What Can Go Wrong

- **Logging failures** - Database errors (non-critical)
- **Missing user data** - User information not available

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
- **Network timeouts**: Retries attempted automatically

### Error Reporting

Errors are reported in multiple places:

1. **Admin notice**: Immediate feedback in UI
2. **Import history**: Logged for later review
3. **JavaScript console**: Detailed debugging info
4. **PHP error log**: Server-side errors

## Hooks for Developers

Customize the import process with filters:

```php
// Modify post data before import
add_filter( 'ccp_pre_import_post', function( $post_data ) {
    // Modify $post_data
    return $post_data;
} );

// After successful import
add_action( 'ccp_post_imported', function( $post_id, $source_url ) {
    // Custom logic after import
}, 10, 2 );

// Modify image download behavior
add_filter( 'ccp_import_media', function( $should_import, $image_url ) {
    // Decide whether to import specific images
    return $should_import;
}, 10, 2 );
```

See the [Hooks and Filters](../extending/hooks.md) guide for complete documentation.

## Best Practices

### Before Importing

1. **Test connection** to verify authentication
2. **Preview content** using Post Diff
3. **Check image accessibility** in preview
4. **Verify post type** is correct

### During Import

1. **Monitor progress** for errors
2. **Don't close the browser** during bulk imports
3. **Check Import History** periodically

### After Import

1. **Review draft posts** before publishing
2. **Verify images** imported correctly
3. **Check formatting** matches source
4. **Test internal links** if present

## Next Steps

- [Content Validation](validation.md) - Understanding validation
- [Import History](import-history.md) - Tracking imports
- [Troubleshooting](../troubleshooting.md) - Common issues
- [Hooks and Filters](../extending/hooks.md) - Customization options
