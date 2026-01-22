# Content Validation

Before importing content, Compliant Content Publisher performs several validation checks to ensure data integrity and prevent errors.

## Validation Stages

### 1. URL Validation

**What it checks:**

- External site URL is properly formatted
- URL uses HTTPS (required for security)
- Domain is accessible and responds to requests
- Site is a WordPress installation with REST API enabled

**Common failures:**

- Invalid URL format (missing protocol, malformed)
- HTTP instead of HTTPS
- Site not accessible (DNS issues, firewall, down)
- Non-WordPress site or REST API disabled

**How to fix:**

- Ensure URL includes `https://` protocol
- Verify the site is accessible in a browser
- Check REST API: visit `https://your-site.com/wp-json`

### 2. Authentication Validation

**What it checks:**

- Credentials are provided (shared secret or basic auth)
- Authentication succeeds with the external site
- User has required permissions (for basic auth)

**Common failures:**

- Missing or incorrect shared secret
- Mismatched secrets between sites
- Wrong username/password
- User lacks required permissions

**How to fix:**

- See the [Authentication guide](authentication.md)
- Verify shared secret matches on both sites
- Check basic auth credentials are correct

### 3. Post Data Validation

**What it checks:**

- Post data structure is valid JSON
- Required fields are present (`id`, `title`, `content`, `link`)
- Post type is supported
- Content is not empty

**Common failures:**

- Malformed JSON response
- Missing required fields
- Unsupported post type
- Empty or corrupted content

**How to fix:**

- Check the source post in WordPress admin
- Verify the post type is enabled in REST API
- Try re-saving the post on the source site

### 4. Content Structure Validation

**What it checks:**

- HTML structure is well-formed
- Gutenberg blocks are properly formatted
- No dangerous HTML or scripts
- Block syntax is valid

**Common failures:**

- Malformed HTML
- Invalid block syntax
- Unclosed HTML tags
- Corrupted block comments

**How to fix:**

- Edit the post in the block editor and fix validation errors
- Remove any custom HTML that might be malformed
- Try switching to code editor view and fixing syntax

### 5. Media Validation

**What it checks:**

- Featured image URL is accessible
- Inline image URLs are valid and accessible
- Image file types are supported
- Images are not too large

**Common failures:**

- Broken image URLs (404 errors)
- Images on non-accessible domains
- Unsupported file types
- Images too large to import

**How to fix:**

- Verify images exist on the source site
- Check image URLs are publicly accessible
- Ensure images are in supported formats (JPEG, PNG, GIF, WebP)
- Resize oversized images

## Validation Error Messages

### `invalid_url`

**Cause**: The provided URL is not valid or accessible
**Solution**: Check URL format and accessibility

### `authentication_failed`

**Cause**: Cannot authenticate with the external site
**Solution**: Verify authentication credentials

### `no_posts_found`

**Cause**: No posts available from the external site
**Solution**: Check if posts exist and are published

### `invalid_post_data`

**Cause**: Post data structure is invalid
**Solution**: Check post data in source WordPress

### `media_import_failed`

**Cause**: Unable to import images
**Solution**: Verify image URLs and accessibility

### `content_validation_failed`

**Cause**: Content structure failed validation
**Solution**: Check HTML/block structure in source post

## Best Practices

### Before Importing

1. **Test the connection** first using the "Test Connection" button
2. **Preview content** using the "Post Diff" feature
3. **Check images** are displaying in the preview
4. **Verify post types** are correctly configured

### During Import

1. **Monitor the process** - watch for validation warnings
2. **Check Import History** for any errors
3. **Review imported drafts** before publishing

### After Import

1. **Verify content** appears correctly in the editor
2. **Check images** are imported and displaying
3. **Test links** within the content
4. **Review formatting** matches the source

## Disabling Validation (Not Recommended)

While not recommended, you can bypass certain validations using filters:

```php
// Disable URL validation (dangerous!)
add_filter( 'ccp_validate_url', '__return_false' );

// Disable content validation
add_filter( 'ccp_validate_content', '__return_false' );
```

**Warning**: Disabling validation can lead to:

- Security vulnerabilities
- Data corruption
- Import failures
- Broken content

Only disable validation for testing purposes in development environments.

## Custom Validation

Developers can add custom validation logic using hooks:

```php
add_filter( 'ccp_validate_post_data', function( $is_valid, $post_data ) {
    // Add custom validation logic
    if ( empty( $post_data['custom_field'] ) ) {
        return new WP_Error( 'missing_custom_field', 'Custom field is required' );
    }

    return $is_valid;
}, 10, 2 );
```

See the [Hooks and Filters](../extending/hooks.md) guide for more information.

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [Import History](import-history.md) - Track your imports
- [Troubleshooting](../troubleshooting.md) - Solve common issues
