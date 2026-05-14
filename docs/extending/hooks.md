# Hooks and Filters

Safe Publish provides WordPress actions and filters at key extension points.

**Actions** allow you to add functionality at specific points. Action callbacks do not return values.

**Filters** allow you to modify data as it passes through the plugin. Filter callbacks must return the modified value.

[Learn more about WordPress Hooks](https://developer.wordpress.org/plugins/hooks/)

## Actions

### `safe_publish_event_logged`

Fires after any event is recorded to the audit log (e.g. import, export, auth, content, or media channels).

**Parameters:**

- `string $channel` — event channel (e.g. `'import'`, `'export'`, `'auth'`, `'content'`, `'media'`)
- `string $event` — event type identifier (e.g. `'CONTENT_EXPORTED'`, `'SIGNATURE_INVALID'`)
- `array $data` — event payload. Always includes `timestamp` (GMT mysql), `site_url` (local site, from `get_site_url()`), `user_agent`, `request_uri`, `actor_user_id` (int), `actor_display_name` (string snapshot), and `actor_source` (one of `cli`, `cron`, `hmac`, `xmlrpc`, `ajax`, `rest`, `admin`, `front`, `unknown`). Unauthenticated contexts (e.g. webhook callbacks) record `actor_user_id` of `0` and an empty display name; `actor_source` then disambiguates the origin. Channel-specific fields are merged in alongside these reserved keys, which cannot be overridden by callers.

**Example:**

```php
add_action( 'safe_publish_event_logged', function( string $channel, string $event, array $data ): void {
    if ( 'export' === $channel && in_array( $event, array( 'EXPORT_REQUEST_ERROR', 'EXPORT_BAD_STATUS' ), true ) ) {
        error_log( 'Safe Publish export failed: ' . wp_json_encode( $data ) );
    }
}, 10, 3 );
```

## Filters

### `safe_publish_api_query_args`

Filter query arguments sent to the source site's REST API when fetching the list of posts.

**Parameters:**

- `array $args` — query arguments
- `string $source_site_url` — source site URL
- `int $number_of_posts` — number of posts to fetch

**Returns:** `array`

**Example:**

```php
add_filter( 'safe_publish_api_query_args', function( array $args, string $source_site_url, int $number_of_posts ): array {
    $args['orderby'] = 'modified';
    $args['order']   = 'desc';
    return $args;
}, 10, 3 );
```

### `safe_publish_sanitized_post`

Filter sanitized post data after it is fetched and sanitized from the source REST API.

**Parameters:**

- `array $sanitized_post` — sanitized post data
- `array $post` — raw post data from the API

**Returns:** `array`

**Example:**

```php
add_filter( 'safe_publish_sanitized_post', function( array $sanitized_post, array $post ): array {
    // Override title casing
    $sanitized_post['title'] = mb_convert_case( $sanitized_post['title'], MB_CASE_TITLE );
    return $sanitized_post;
}, 10, 2 );
```

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

### `safe_publish_import_allow_author_fallback`

Filter whether the import may fall back to the importing author when the source author cannot be matched on the destination site. By default, an unmatched source author aborts the import.

When this filter returns `true`:

- **New post + no author match** — `post_author` is set to the importing user (`get_current_user_id()`).
- **Update + no author match** — the destination post's existing `post_author` is preserved unchanged.
- Either case records a warning on the import History item row and surfaces it in the import results UI.

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
