# REST API Extension

Safe Publish provides REST API endpoints for programmatic access. This guide explains how to use and extend the API.

## API Endpoints

The plugin registers endpoints under the `safe-publish/v1` namespace.

### Content Endpoints

Require a WordPress user with `edit_post` capability for the target post.

| Method | Endpoint                                | Description                      |
| ------ | --------------------------------------- | -------------------------------- |
| `POST` | `/wp-json/safe-publish/v1/diff-preview` | Render a diff preview for a post |
| `POST` | `/wp-json/safe-publish/v1/update-post`  | Apply imported content to a post |

### Source Endpoints

Registered only on source-mode installs. HMAC-authenticated; called by the destination's import UI.

| Method | Endpoint                                      | Auth | Description                                |
| ------ | --------------------------------------------- | ---- | ------------------------------------------ |
| `GET`  | `/wp-json/safe-publish/v1/catalog/posts`      | HMAC | Browsable, server-paginated source catalog |
| `GET`  | `/wp-json/safe-publish/v1/catalog/post-types` | HMAC | Post types the catalog can serve           |

### Monitoring Endpoints

| Method   | Endpoint                               | Auth                     | Description                          |
| -------- | -------------------------------------- | ------------------------ | ------------------------------------ |
| `GET`    | `/wp-json/safe-publish/v1/auth-status` | `manage_options` or HMAC | Authentication health and statistics |
| `GET`    | `/wp-json/safe-publish/v1/auth-logs`   | `manage_options` or HMAC | Paginated authentication event log   |
| `DELETE` | `/wp-json/safe-publish/v1/auth-logs`   | `manage_options`         | Clear authentication logs            |
| `GET`    | `/wp-json/safe-publish/v1/auth-test`   | None (`WP_DEBUG` only)   | Authentication diagnostic test       |

All datetime fields returned by monitoring endpoints (e.g. `timestamp`, `last_success`, `last_failure`, `created_at_gmt`) are ISO 8601 UTC strings (e.g. `2026-05-05T14:30:00Z`).

## Authentication

### Content endpoints

Content endpoints are called from the WordPress admin UI and use **cookie authentication** with a nonce — no additional setup needed.

```javascript
fetch('/wp-json/safe-publish/v1/update-post', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({ postId: 123, content: '...' })
});
```

Application Passwords can also be used for external access to these endpoints, but are incompatible when [VIP Basic Authentication](https://docs.wpvip.com/security-controls/basic-authentication/) is enabled — see [Authentication](../concepts/authentication.md).

### Monitoring endpoints

Monitoring endpoints accept either a WordPress user with `manage_options` capability, or a valid HMAC shared secret signature. The HMAC method has no conflict with VIP Basic Authentication, since it uses the custom `X-Safe-Publish-Signature` header rather than `Authorization: Basic`.

## Registering Custom Endpoints

### Basic Custom Endpoint

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'safe-publish/v1', '/custom-action', [
        'methods' => 'POST',
        'callback' => 'safe_publish_custom_action_handler',
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
        'args' => [
            'post_id' => [
                'required' => true,
                'type' => 'integer',
                'validate_callback' => function( $param ) {
                    return is_numeric( $param );
                },
            ],
        ],
    ] );
} );

function safe_publish_custom_action_handler( WP_REST_Request $request ) {
    $post_id = $request->get_param( 'post_id' );

    // Your custom logic here

    return new WP_REST_Response( [
        'success' => true,
        'message' => 'Custom action completed',
    ], 200 );
}
```

## Webhook Integration

Use the [`safe_publish_event_logged`](hooks.md#safe_publish_event_logged) action to notify external systems when plugin events occur:

```php
add_action( 'safe_publish_event_logged', function( string $channel, string $event, array $data ): void {
    if ( 'import' !== $channel ) {
        return;
    }

    $webhook_url = get_option( 'safe_publish_webhook_url' );

    if ( ! $webhook_url ) {
        return;
    }

    wp_remote_post( $webhook_url, [
        'body'    => wp_json_encode( [
            'channel' => $channel,
            'event'   => $event,
            'data'    => $data,
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );
}, 10, 3 );
```

## Error Handling

Standard error responses:

```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": {
    "status": 403
  }
}
```

**Common error codes:**

- `rest_forbidden` - Permission denied (WordPress core)
- `rest_invalid_param` - Invalid parameter (e.g. non-positive post ID)
- `rest_post_not_found` - Post does not exist

## Security Considerations

1. **Always verify permissions:**

```php
'permission_callback' => function() {
    return current_user_can( 'manage_options' );
}
```

2. **Validate input parameters:**

```php
'args' => [
    'post_id' => [
        'validate_callback' => function( $param ) {
            return is_numeric( $param ) && $param > 0;
        },
        'sanitize_callback' => 'absint',
    ],
]
```

3. **Use nonces for same-site requests:**

```javascript
'X-WP-Nonce': wpApiSettings.nonce
```

4. **Rate limit external API calls**.

## Next Steps

- [Hooks and Filters](hooks.md) - Available customization hooks
- [Custom Post Types](post-types.md) - Post type support
- [Extending Guide](index.md) - Back to extending overview
