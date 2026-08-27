# Extending Safe Publish

> [!TIP]
>
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

Extend the plugin's REST API capabilities. The REST API surface is intentionally small; most extension points are WordPress hooks and filters.

- [REST API Extension](api.md) - Custom endpoints and authentication

## Common Customizations

- **Control import sanitization** — use [`safe_publish_import_kses`](hooks.md#safe_publish_import_kses) to enable kses sanitization, and [`safe_publish_import_kses_allowed_html`](hooks.md#safe_publish_import_kses_allowed_html) to customize allowed tags.
- **Add custom request headers** — use [`safe_publish_request_args`](hooks.md#safe_publish_request_args) to inject authentication headers or other HTTP arguments.
- **Migrate ACF/SCF field values** — use [`safe_publish_source_post_meta`](hooks.md#safe_publish_source_post_meta) to fold the source `acf` object into imported meta. See [Migrating ACF and Secure Custom Fields Values](acf-scf.md).
- **Migrate Yoast SEO meta** — expose the `_yoast_wpseo_*` keys for REST on the source so they enter the core `meta` object. See [Migrating Yoast SEO Meta](yoast.md).

See the [Hooks and Filters Reference](hooks.md) for full parameter documentation and examples.

## Integration Examples

- **Event notifications** — use [`safe_publish_event_logged`](hooks.md#safe_publish_event_logged) to react to any plugin event (e.g. send a Slack message on failure).

See the [Hooks and Filters Reference](hooks.md) for full parameter documentation and examples.

## Local Development

Set up a development environment for testing your extensions:

- [Local Development Guide](../local-development.md) - Development environment setup

## Best Practices

### 1. Use Appropriate Hooks

- Use **actions** for side effects (notifications, logging).
- Use **filters** for modifying data (validation, transformation).
- Check hook priority if order matters.

### 2. Error Handling

Always handle errors gracefully:

```php
add_filter( 'safe_publish_request_args', function( array $args, string $url ): array {
    try {
        // Your custom logic
        return $args;
    } catch ( Exception $e ) {
        error_log( 'Safe Publish Extension Error: ' . $e->getMessage() );
        return $args; // Return unchanged on error
    }
}, 10, 2 );
```

### 3. Performance

- Avoid expensive operations in filters that run frequently.
- Use caching where appropriate.
- Be mindful of source API calls.

### 4. VIP Compatibility

When extending Safe Publish on WordPress VIP:

- Follow [VIP coding standards](https://docs.wpvip.com/technical-references/vip-codebase/).
- Avoid filesystem writes.
- Use `vip_safe_wp_remote_get()` for external requests.
- Test thoroughly in VIP environments.

## Documentation

- [Hooks Reference](hooks.md) - Complete list of actions and filters
- [Custom Post Types](post-types.md) - Adding post type support
- [ACF and SCF Values](acf-scf.md) - Migrating custom field values
- [Yoast SEO Meta](yoast.md) - Migrating Yoast SEO settings
- [REST API](api.md) - Extending the API
- [Troubleshooting](../troubleshooting.md) - Common issues

## Need Help?

- Check the [hooks documentation](hooks.md) for available extension points.
- Review example code in this guide.
- Report issues or request features via GitHub Issues.
