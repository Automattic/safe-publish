# Hooks and Filters

Compliant Content Publisher provides WordPress hooks (actions and filters) at key points in the import process, allowing you to customize behavior without modifying core plugin code.

## Understanding Hooks

**Actions** allow you to add functionality or trigger events at specific points. Action callbacks don't return values.

**Filters** allow you to modify data as it passes through the plugin. Filter callbacks must return the modified value.

[Learn more about WordPress Hooks](https://developer.wordpress.org/plugins/hooks/)

## Actions

Actions trigger at specific points in the import process, allowing you to add custom functionality.

### ccp_init

Fires when CCP is fully loaded and initialized.

**Parameters:** None

**Example:**
```php
add_action( 'ccp_init', function() {
    // Initialize your custom functionality
    // All CCP classes are now available
} );
```

### ccp_post_imported

Fires after a post is successfully imported.

**Parameters:**
- `int $post_id` - The ID of the newly created post
- `string $source_url` - The URL of the source post

**Example:**
```php
add_action( 'ccp_post_imported', function( $post_id, $source_url ) {
    // Send notification
    wp_mail(
        'editor@example.com',
        'New post imported',
        sprintf( 'Post #%d imported from %s', $post_id, $source_url )
    );

    // Log to external system
    do_action( 'my_import_logger', $post_id, $source_url );
}, 10, 2 );
```

### ccp_import_failed

Fires when an import attempt fails.

**Parameters:**
- `WP_Error $error` - The error object
- `array $post_data` - The post data that failed to import
- `string $source_url` - The URL of the source post

**Example:**
```php
add_action( 'ccp_import_failed', function( $error, $post_data, $source_url ) {
    error_log( sprintf(
        'CCP Import Failed: %s - Source: %s',
        $error->get_error_message(),
        $source_url
    ) );

    // Send alert to monitoring system
    do_action( 'send_alert', 'import_failure', $error );
}, 10, 3 );
```

### ccp_media_imported

Fires after a media item is successfully imported.

**Parameters:**
- `int $attachment_id` - The ID of the imported media attachment
- `string $source_url` - The URL of the source media file
- `int $post_id` - The post this media belongs to

**Example:**
```php
add_action( 'ccp_media_imported', function( $attachment_id, $source_url, $post_id ) {
    // Add custom metadata
    update_post_meta( $attachment_id, '_source_url', $source_url );
    update_post_meta( $attachment_id, '_imported_for_post', $post_id );
}, 10, 3 );
```

### ccp_bulk_import_start

Fires at the start of a bulk import operation.

**Parameters:**
- `array $post_ids` - Array of post IDs to be imported

**Example:**
```php
add_action( 'ccp_bulk_import_start', function( $post_ids ) {
    update_option( 'ccp_bulk_import_in_progress', true );
    update_option( 'ccp_bulk_import_count', count( $post_ids ) );
} );
```

### ccp_bulk_import_complete

Fires when a bulk import operation completes.

**Parameters:**
- `array $results` - Array of import results (success/failure for each post)

**Example:**
```php
add_action( 'ccp_bulk_import_complete', function( $results ) {
    update_option( 'ccp_bulk_import_in_progress', false );

    $success_count = count( array_filter( $results, fn($r) => $r['success'] ) );
    $total_count = count( $results );

    // Send summary notification
    wp_mail(
        'admin@example.com',
        'Bulk import complete',
        sprintf( '%d of %d posts imported successfully', $success_count, $total_count )
    );
} );
```

## Filters

Filters allow you to modify data as it flows through the plugin. Always return the modified value.

### ccp_validate_url

Filter whether to validate the source URL.

**Parameters:**
- `bool $should_validate` - Whether to validate (default: true)
- `string $url` - The URL being validated

**Returns:** `bool`

**Example:**
```php
add_filter( 'ccp_validate_url', function( $should_validate, $url ) {
    // Skip validation for trusted internal URLs
    if ( strpos( $url, 'internal.staging.example.com' ) !== false ) {
        return false;
    }
    return $should_validate;
}, 10, 2 );
```

### ccp_pre_import_post

Filter post data before importing.

**Parameters:**
- `array $post_data` - The post data to be imported

**Returns:** `array` - Modified post data

**Example:**
```php
add_filter( 'ccp_pre_import_post', function( $post_data ) {
    // Change post status
    $post_data['post_status'] = 'pending';

    // Add custom taxonomy terms
    $post_data['tax_input']['custom_taxonomy'] = [ 'imported' ];

    // Modify content
    $post_data['post_content'] = str_replace(
        '[old-shortcode]',
        '[new-shortcode]',
        $post_data['post_content']
    );

    return $post_data;
} );
```

### ccp_validate_post_data

Filter post data validation results.

**Parameters:**
- `bool|WP_Error $is_valid` - Validation result
- `array $post_data` - The post data being validated

**Returns:** `bool|WP_Error`

**Example:**
```php
add_filter( 'ccp_validate_post_data', function( $is_valid, $post_data ) {
    // Custom validation rule
    if ( empty( $post_data['post_excerpt'] ) ) {
        return new WP_Error(
            'missing_excerpt',
            'Post excerpt is required for import'
        );
    }

    // Check custom field
    if ( isset( $post_data['meta']['_required_field'] )
        && empty( $post_data['meta']['_required_field'] ) ) {
        return new WP_Error(
            'missing_required_field',
            'Required custom field is missing'
        );
    }

    return $is_valid;
}, 10, 2 );
```

### ccp_authentication_headers

Filter authentication headers sent to the external site.

**Parameters:**
- `array $headers` - HTTP request headers
- `string $site_url` - The external site URL

**Returns:** `array` - Modified headers

**Example:**
```php
add_filter( 'ccp_authentication_headers', function( $headers, $site_url ) {
    // Add custom authentication token
    if ( strpos( $site_url, 'staging.example.com' ) !== false ) {
        $headers['X-Custom-Auth'] = 'my-custom-token';
    }

    // Add API key
    $headers['X-API-Key'] = get_option( 'my_api_key' );

    return $headers;
}, 10, 2 );
```

### ccp_import_media

Filter whether to import a specific media file.

**Parameters:**
- `bool $should_import` - Whether to import (default: true)
- `string $image_url` - The image URL
- `int $post_id` - The post ID

**Returns:** `bool`

**Example:**
```php
add_filter( 'ccp_import_media', function( $should_import, $image_url, $post_id ) {
    // Skip images from CDN (already accessible)
    if ( strpos( $image_url, 'cdn.example.com' ) !== false ) {
        return false;
    }

    // Skip large GIF files
    $file_size = @filesize( $image_url );
    if ( $file_size && $file_size > 5 * 1024 * 1024 ) { // 5MB
        return false;
    }

    return $should_import;
}, 10, 3 );
```

### ccp_media_import_timeout

Filter the timeout for media downloads (in seconds).

**Parameters:**
- `int $timeout` - Timeout in seconds (default: 30)
- `string $image_url` - The image URL being downloaded

**Returns:** `int`

**Example:**
```php
add_filter( 'ccp_media_import_timeout', function( $timeout, $image_url ) {
    // Increase timeout for large files
    if ( strpos( $image_url, 'highres' ) !== false ) {
        return 60; // 60 seconds for high-res images
    }
    return $timeout;
}, 10, 2 );
```

### ccp_supported_post_types

Filter the list of supported post types.

**Parameters:**
- `array $post_types` - Array of post type slugs

**Returns:** `array`

**Example:**
```php
add_filter( 'ccp_supported_post_types', function( $post_types ) {
    // Add custom post types
    $post_types[] = 'my_custom_type';
    $post_types[] = 'another_type';

    // Remove a post type
    $post_types = array_diff( $post_types, [ 'page' ] );

    return $post_types;
} );
```

### ccp_rest_api_namespace

Filter the REST API namespace.

**Parameters:**
- `string $namespace` - The namespace (default: 'ccp/v1')

**Returns:** `string`

**Example:**
```php
add_filter( 'ccp_rest_api_namespace', function( $namespace ) {
    return 'my-custom-namespace/v1';
} );
```

### ccp_import_history_retention_days

Filter how many days to retain import history.

**Parameters:**
- `int $days` - Number of days (default: 0 = unlimited)

**Returns:** `int`

**Example:**
```php
add_filter( 'ccp_import_history_retention_days', function( $days ) {
    return 90; // Keep 90 days of history
} );
```

### ccp_http_request_args

Filter HTTP request arguments before making requests to external site.

**Parameters:**
- `array $args` - Request arguments
- `string $url` - The URL being requested

**Returns:** `array`

**Example:**
```php
add_filter( 'ccp_http_request_args', function( $args, $url ) {
    // Increase timeout for slow sites
    $args['timeout'] = 45;

    // Add custom headers
    $args['headers']['X-Custom-Header'] = 'value';

    // Use custom SSL verification
    $args['sslverify'] = false; // Only for development!

    return $args;
}, 10, 2 );
```

## Hook Priority

When using multiple callbacks for the same hook, use priority to control execution order:

```php
// Run first (priority 5)
add_filter( 'ccp_pre_import_post', 'my_early_filter', 5 );

// Run with default priority (10)
add_filter( 'ccp_pre_import_post', 'my_normal_filter' );

// Run last (priority 20)
add_filter( 'ccp_pre_import_post', 'my_late_filter', 20 );
```

Lower numbers run first. Default priority is 10.

## Best Practices

1. **Always return values in filters**: Forgetting to return breaks the filter chain
2. **Check data before modifying**: Verify array keys exist before accessing
3. **Handle errors gracefully**: Use try-catch blocks and log errors
4. **Respect existing data**: Don't overwrite unless necessary
5. **Test thoroughly**: Especially when modifying core import logic

## Debugging Hooks

Enable WordPress debug mode to see hook execution:

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Log hook execution
add_filter( 'ccp_pre_import_post', function( $post_data ) {
    error_log( 'CCP Filter: ' . print_r( $post_data, true ) );
    return $post_data;
} );
```

## Next Steps

- [Custom Post Types](post-types.md) - Adding post type support
- [REST API Extension](api.md) - Extending the API
- [Extending Guide](index.md) - Back to extending overview
