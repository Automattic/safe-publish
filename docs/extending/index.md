# Extending Safe Publish

> [!TIP]
> Make sure you've read the [core concepts](../concepts/index.md) before extending the plugin.

Safe Publish provides hooks, filters, and extensibility points to customize its behavior. Whether you need to modify the import process, add custom validation, or integrate with other systems, the plugin offers flexible options for developers.

## Extension Points

### Hooks and Filters

The plugin provides WordPress actions and filters at key points in the import process:

- [Hooks and Filters Reference](hooks.md) - Complete list of available hooks

### Custom Post Type Support

Learn how to enable import support for custom post types:

- [Custom Post Type Support](post-types.md) - Registering and configuring custom post types

### REST API Extension

Extend the plugin's REST API capabilities:

- [REST API Extension](api.md) - Custom endpoints and authentication

## Common Customizations

### Modify Import Behavior

Use filters to customize how content is imported:

```php
// Modify post data before import
add_filter( 'safe_publish_pre_import_post', function( $post_data ) {
    // Add custom logic
    $post_data['post_status'] = 'pending'; // Override to pending instead of draft
    return $post_data;
} );

// After successful import
add_action( 'safe_publish_post_imported', function( $post_id, $source_url ) {
    // Notify team, trigger workflows, etc.
    do_action( 'my_custom_workflow', $post_id );
}, 10, 2 );
```

### Custom Validation

Add your own validation rules:

```php
add_filter( 'safe_publish_validate_post_data', function( $is_valid, $post_data ) {
    // Custom validation logic
    if ( empty( $post_data['custom_field'] ) ) {
        return new WP_Error( 'missing_field', 'Required field missing' );
    }
    return $is_valid;
}, 10, 2 );
```

### Custom Authentication

Implement custom authentication methods:

```php
add_filter( 'safe_publish_authentication_headers', function( $headers, $site_url ) {
    // Add custom authentication headers
    $headers['X-Custom-Auth'] = 'your-token';
    return $headers;
}, 10, 2 );
```

## Integration Examples

### Slack Notifications

Notify your team when content is imported:

```php
add_action( 'safe_publish_post_imported', function( $post_id, $source_url ) {
    $post = get_post( $post_id );
    $message = sprintf(
        'New post imported: %s from %s',
        $post->post_title,
        $source_url
    );

    // Send to Slack webhook
    wp_remote_post( 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL', [
        'body' => json_encode( [ 'text' => $message ] ),
    ] );
}, 10, 2 );
```

### Custom Metadata

Import and map custom fields:

```php
add_action( 'safe_publish_post_imported', function( $post_id, $source_url ) {
    // Get source post data
    $source_post = get_post_meta( $post_id, '_safe_publish_source_data', true );

    // Map custom fields
    if ( isset( $source_post['acf'] ) ) {
        foreach ( $source_post['acf'] as $key => $value ) {
            update_field( $key, $value, $post_id );
        }
    }
}, 10, 2 );
```

### Automated Publishing

Auto-publish certain types of content:

```php
add_filter( 'safe_publish_pre_import_post', function( $post_data ) {
    // Auto-publish posts from specific category
    if ( in_array( 'auto-publish', $post_data['categories'] ?? [] ) ) {
        $post_data['post_status'] = 'publish';
    }
    return $post_data;
} );
```

## Local Development

Set up a development environment for testing your extensions:

- [Local Development Guide](../local-development.md) - Development environment setup

## Best Practices

### 1. Use Appropriate Hooks

- Use **actions** for side effects (notifications, logging)
- Use **filters** for modifying data (validation, transformation)
- Check hook priority if order matters

### 2. Error Handling

Always handle errors gracefully:

```php
add_filter( 'safe_publish_pre_import_post', function( $post_data ) {
    try {
        // Your custom logic
        return $post_data;
    } catch ( Exception $e ) {
        error_log( 'Safe Publish Extension Error: ' . $e->getMessage() );
        return $post_data; // Return unchanged on error
    }
} );
```

### 3. Performance

- Avoid expensive operations in filters that run frequently
- Use caching where appropriate
- Be mindful of external API calls

### 4. VIP Compatibility

When extending Safe Publish on WordPress VIP:

- Follow [VIP coding standards](https://docs.wpvip.com/technical-references/vip-codebase/)
- Avoid filesystem writes
- Use `wpcom_vip_file_get_contents()` for external requests
- Test thoroughly in VIP environments

## Documentation

- [Hooks Reference](hooks.md) - Complete list of actions and filters
- [Custom Post Types](post-types.md) - Adding post type support
- [REST API](api.md) - Extending the API
- [Troubleshooting](../troubleshooting.md) - Common issues

## Need Help?

- Check the [hooks documentation](hooks.md) for available extension points
- Review example code in this guide
- Report issues or request features via GitHub Issues
