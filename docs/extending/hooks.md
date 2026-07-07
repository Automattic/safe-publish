# Hooks and Filters

Safe Publish provides WordPress actions and filters at key extension points.

**Actions** allow you to add functionality at specific points. Action callbacks do not return values.

**Filters** allow you to modify data as it passes through the plugin. Filter callbacks must return the modified value.

[Learn more about WordPress Hooks](https://developer.wordpress.org/plugins/hooks/)

## Actions

### `safe_publish_event_logged`

Fires after any event is recorded to the audit log (e.g. import, export, auth, content, media, or dispatch channels).

**Parameters:**

- `string $channel` — event channel (e.g. `'import'`, `'export'`, `'auth'`, `'content'`, `'media'`, `'dispatch'`)
- `string $event` — event type identifier (e.g. `'CONTENT_EXPORTED'`, `'SIGNATURE_INVALID'`)
- `array $data` — event payload. Always includes `timestamp` (GMT mysql), `site_url` (local site, from `get_site_url()`), `user_agent`, `request_uri`, `actor_user_id` (int), `actor_display_name` (string snapshot), and `actor_source` (one of `cli`, `cron`, `hmac`, `xmlrpc`, `ajax`, `rest`, `admin`, `front`, `unknown`). Unauthenticated contexts (e.g. webhook callbacks) record `actor_user_id` of `0` and an empty display name; `actor_source` then disambiguates the origin. Channel-specific fields are merged in alongside these reserved keys, which cannot be overridden by callers.

**Example:**

```php
add_action( 'safe_publish_event_logged', function( string $channel, string $event, array $data ): void {
    if ( 'export' === $channel && in_array( $event, array( 'EXPORT_REQUEST_ERROR', 'EXPORT_RESPONSE_BAD_STATUS' ), true ) ) {
        error_log( 'Safe Publish export failed: ' . wp_json_encode( $data ) );
    }
}, 10, 3 );
```

## Filters

### `safe_publish_request_timeout`

Filter the HTTP request timeout in seconds for REST API requests. Default: `10`.

Note: this filter applies only to REST API calls (e.g. fetching post data and featured image metadata). Image downloads use WordPress core's `download_url()` directly and are not affected by this filter.

**Parameters:**

- `int $timeout`

**Returns:** `int`

**Example:**

```php
add_filter( 'safe_publish_request_timeout', fn( int $timeout ): int => 30 );
```

### `safe_publish_request_args`

Filter HTTP request arguments before any outgoing request to a source site.

`$args` carries a default `limit_response_size` of 10 MB that caps the response body buffered into memory, preventing an oversized response from a source site from exhausting the PHP worker. Image downloads use WordPress core's `download_url()` directly and are not affected.

**Parameters:**

- `array $args` — `wp_remote_get`/`wp_remote_post` arguments
- `string $url` — request URL

**Returns:** `array`

**Example:**

```php
add_filter( 'safe_publish_request_args', function( array $args, string $url ): array {
    $args['headers']['X-Custom-Header'] = 'value';
    return $args;
}, 10, 2 );
```

### `safe_publish_source_fetch_query_args`

Filter the query string of the single-post fetch to the source site. Useful for requesting additional top-level response keys or passing source-specific arguments. Note that `_fields` is subtractive — using it drops the fields the import requires. See [Migrating ACF and Secure Custom Fields Values](acf-scf.md).

**Parameters:**

- `array $query_args` — query args appended to the fetch URL (includes `_embed`, plus `context` when authenticated)
- `array $context` — fetch context: `source_post_id` (int), `post_type` (string), `source_site_url` (string)

**Returns:** `array`

**Example:**

```php
add_filter( 'safe_publish_source_fetch_query_args', function( array $query_args, array $context ): array {
    $query_args['custom_source_arg'] = '1';
    return $query_args;
}, 10, 2 );
```

### `safe_publish_source_post_meta`

Filter the post meta imported from the source, before it is written to the destination. Receives the full decoded REST response, so integrations can merge in values from other top-level keys such as ACF/SCF's `acf` object. See [Migrating ACF and Secure Custom Fields Values](acf-scf.md).

**Parameters:**

- `array $meta` — meta from the REST `meta` object (empty array when the response has none)
- `array $data` — the full decoded REST response for the post
- `array $context` — fetch context: `source_post_id` (int), `post_type` (string), `source_site_url` (string)

**Returns:** `array`

**Example:**

```php
add_filter( 'safe_publish_source_post_meta', function( array $meta, array $data ): array {
    if ( isset( $data['acf']['hero_title'] ) ) {
        // Source values are untrusted; sanitize for the field's type.
        $meta['hero_title'] = sanitize_text_field( (string) $data['acf']['hero_title'] );
    }
    return $meta;
}, 10, 2 );
```

### `safe_publish_import_allow_author_fallback`

Filter whether the import may fall back to the importing author when the source author cannot be matched on the destination site. By default, an unmatched source author aborts the import.

When this filter returns `true`:

- **New post + no author match** — `post_author` is set to the importing user (`get_current_user_id()`).
- **Update + no author match** — the destination post's existing `post_author` is preserved unchanged.
- Either case records a warning on the import items row and surfaces it in the import results UI.

Imports still abort when the source post has no resolvable author (e.g., the author was deleted on the source).

Enabling the fallback relaxes the source-canonical guarantee.

**Parameters:**

- `bool $enabled` — whether the fallback is enabled (default `false`)

**Returns:** `bool`

**Example:**

```php
// Allow the importing user to take over when the source author is unmatched.
add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );
```

### `safe_publish_import_allow_orphans`

Filter whether a hierarchical post may be imported as orphan when its source parent cannot be resolved on the destination site. By default, an unresolved parent aborts the import.

When this filter returns `true`:

- The post is imported with `post_parent = 0`.
- A `parent_orphaned` warning is recorded on the import items row and surfaced in the import results UI. The warning carries a `reason` of either `not_imported` (parent never imported and not in the current bulk batch) or `failed_in_batch` (parent was in the batch but did not succeed).

Enabling the fallback relaxes the source-canonical guarantee for parent relationships. Review the import results UI or the Imports → Posts tab for warnings whenever it's enabled.

**Parameters:**

- `bool $enabled` — whether the fallback is enabled (default `false`)

**Returns:** `bool`

**Example:**

```php
// Allow hierarchical posts to be imported as orphans when their parent is unresolved.
add_filter( 'safe_publish_import_allow_orphans', '__return_true' );
```

### `safe_publish_import_kses`

Filter whether to apply kses sanitization to imported content and excerpts. By default, kses is disabled during import to preserve content fidelity, matching WordPress core importer behavior. Return `true` to enable kses sanitization.

**Parameters:**

- `bool $enabled` — whether to apply kses (default `false`)
- `string $field` — field being sanitized: `'content'` or `'excerpt'`

**Returns:** `bool`

**Examples:**

```php
// Enable kses sanitization during import.
add_filter( 'safe_publish_import_kses', '__return_true' );
```

```php
// Enable kses only for content, leaving excerpts unfiltered.
add_filter( 'safe_publish_import_kses', function( bool $enabled, string $field ): bool {
    return 'content' === $field;
}, 10, 2 );
```

### `safe_publish_import_kses_allowed_html`

Filter the allowed HTML tags and attributes when kses is enabled during import. Only applied when `safe_publish_import_kses` returns `true`.

**Parameters:**

- `array $allowed` — allowed HTML elements and attributes (default: `wp_kses_allowed_html( 'post' )`)
- `string $field` — field being sanitized: `'content'` or `'excerpt'`

**Returns:** `array`

**Example:**

```php
// Allow iframes when kses is enabled.
add_filter( 'safe_publish_import_kses_allowed_html', function( array $allowed ): array {
    $allowed['iframe'] = array(
        'src'    => true,
        'width'  => true,
        'height' => true,
    );
    return $allowed;
} );
```

### `safe_publish_dev_ssl_verify`

Filter SSL certificate verification. Primarily useful in local development environments with self-signed certificates. **Never disable in production.**

**Parameters:**

- `bool $verify` — current verification state
- `string $url` — request URL

**Returns:** `bool`

**Example:**

```php
// Force SSL verification even on .local domains.
add_filter( 'safe_publish_dev_ssl_verify', '__return_true' );
```

### `safe_publish_auth_max_time_diff`

Filter the maximum allowed time difference (in seconds) between a request's timestamp and the server time when validating HMAC signatures. Default: `300`.

**Parameters:**

- `int $max_diff`

**Returns:** `int`

**Example:**

```php
add_filter( 'safe_publish_auth_max_time_diff', fn( int $diff ): int => 600 );
```

## Best Practices

1. Always return values in filters.
2. Check that array keys exist before accessing them.
3. Handle errors gracefully — log but do not re-throw.
4. Test thoroughly when modifying core plugin logic.

## Next Steps

- [Custom Post Types](post-types.md) - Adding post type support
- [REST API Extension](api.md) - Extending the API
- [Extending Guide](index.md) - Back to extending overview
