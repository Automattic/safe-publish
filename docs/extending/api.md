# REST API Extension

Compliant Content Publisher provides REST API endpoints for programmatic access. This guide explains how to use and extend the API.

## Base API Endpoints

The plugin registers endpoints under the `ccp/v1` namespace:

- `GET /wp-json/ccp/v1/status` - Plugin status and version
- `POST /wp-json/ccp/v1/import` - Import a single post
- `POST /wp-json/ccp/v1/bulk-import` - Bulk import multiple posts
- `GET /wp-json/ccp/v1/history` - Get import history
- `GET /wp-json/ccp/v1/post-types` - Get available post types from external site

## Authentication

API requests require proper WordPress authentication. Use one of these methods:

### Application Passwords (Recommended)

WordPress 5.6+ supports application passwords:

```bash
curl -X POST https://your-site.com/wp-json/ccp/v1/import \
  -u "username:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{"source_url": "https://staging.com/post-123", "post_id": 123}'
```

### Cookie Authentication

When making requests from the same site:

```javascript
fetch('/wp-json/ccp/v1/import', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    source_url: 'https://staging.com/post-123',
    post_id: 123
  })
});
```

## Using the API

### Get Plugin Status

```bash
curl https://your-site.com/wp-json/ccp/v1/status
```

**Response:**
```json
{
  "version": "1.0.0",
  "configured": true,
  "external_site": "https://staging.example.com",
  "authentication": "shared_secret"
}
```

### Import a Single Post

```bash
curl -X POST https://your-site.com/wp-json/ccp/v1/import \
  -u "username:app-password" \
  -H "Content-Type: application/json" \
  -d '{
    "source_url": "https://staging.com/wp-json/wp/v2/posts/123",
    "post_id": 123,
    "post_type": "post"
  }'
```

**Response (Success):**
```json
{
  "success": true,
  "post_id": 456,
  "message": "Post imported successfully",
  "edit_link": "https://your-site.com/wp-admin/post.php?post=456&action=edit"
}
```

**Response (Error):**
```json
{
  "success": false,
  "code": "import_failed",
  "message": "Failed to import post: Authentication failed",
  "data": {
    "status": 400
  }
}
```

### Bulk Import

```bash
curl -X POST https://your-site.com/wp-json/ccp/v1/bulk-import \
  -u "username:app-password" \
  -H "Content-Type: application/json" \
  -d '{
    "posts": [
      {"post_id": 123, "post_type": "post"},
      {"post_id": 124, "post_type": "post"},
      {"post_id": 125, "post_type": "page"}
    ]
  }'
```

**Response:**
```json
{
  "success": true,
  "imported": 2,
  "failed": 1,
  "results": [
    {
      "source_id": 123,
      "success": true,
      "post_id": 456
    },
    {
      "source_id": 124,
      "success": true,
      "post_id": 457
    },
    {
      "source_id": 125,
      "success": false,
      "error": "Invalid post data"
    }
  ]
}
```

### Get Import History

```bash
curl https://your-site.com/wp-json/ccp/v1/history \
  -u "username:app-password"
```

**Query Parameters:**
- `page` (int) - Page number (default: 1)
- `per_page` (int) - Results per page (default: 20, max: 100)
- `status` (string) - Filter by status: 'success' or 'failed'
- `user_id` (int) - Filter by user ID
- `after` (string) - After date (ISO 8601 format)
- `before` (string) - Before date (ISO 8601 format)

**Response:**
```json
{
  "total": 145,
  "pages": 8,
  "history": [
    {
      "id": 1,
      "timestamp": "2024-10-29T14:23:45",
      "user_id": 1,
      "user_name": "admin",
      "source_url": "https://staging.example.com",
      "source_post_id": 123,
      "destination_post_id": 456,
      "status": "success",
      "error_message": null
    }
  ]
}
```

## Registering Custom Endpoints

### Basic Custom Endpoint

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'ccp/v1', '/custom-action', [
        'methods' => 'POST',
        'callback' => 'ccp_custom_action_handler',
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

function ccp_custom_action_handler( WP_REST_Request $request ) {
    $post_id = $request->get_param( 'post_id' );

    // Your custom logic here

    return new WP_REST_Response( [
        'success' => true,
        'message' => 'Custom action completed',
    ], 200 );
}
```

### Extend Existing Endpoints

Modify response data for existing endpoints:

```php
add_filter( 'rest_prepare_ccp_history', function( $response, $item, $request ) {
    $data = $response->get_data();

    // Add custom fields
    $data['custom_field'] = get_post_meta( $item->destination_post_id, 'custom_field', true );

    $response->set_data( $data );
    return $response;
}, 10, 3 );
```

## Webhook Integration

Create webhooks to notify external systems:

```php
// Trigger webhook after successful import
add_action( 'ccp_post_imported', function( $post_id, $source_url ) {
    $webhook_url = get_option( 'ccp_webhook_url' );

    if ( ! $webhook_url ) {
        return;
    }

    $post = get_post( $post_id );

    wp_remote_post( $webhook_url, [
        'body' => json_encode( [
            'event' => 'post_imported',
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'source_url' => $source_url,
            'timestamp' => current_time( 'mysql' ),
        ] ),
        'headers' => [
            'Content-Type' => 'application/json',
        ],
    ] );
}, 10, 2 );
```

## JavaScript SDK Example

Create a simple JavaScript client:

```javascript
class CCPClient {
    constructor(baseUrl, credentials) {
        this.baseUrl = baseUrl;
        this.credentials = credentials;
    }

    async import(postId, postType = 'post') {
        const response = await fetch(`${this.baseUrl}/wp-json/ccp/v1/import`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Basic ${btoa(this.credentials)}`
            },
            body: JSON.stringify({ post_id: postId, post_type: postType })
        });

        return await response.json();
    }

    async bulkImport(posts) {
        const response = await fetch(`${this.baseUrl}/wp-json/ccp/v1/bulk-import`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Basic ${btoa(this.credentials)}`
            },
            body: JSON.stringify({ posts })
        });

        return await response.json();
    }

    async getHistory(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        const response = await fetch(
            `${this.baseUrl}/wp-json/ccp/v1/history?${queryString}`,
            {
                headers: {
                    'Authorization': `Basic ${btoa(this.credentials)}`
                }
            }
        );

        return await response.json();
    }
}

// Usage
const client = new CCPClient(
    'https://your-site.com',
    'username:app-password'
);

// Import a post
const result = await client.import(123, 'post');
console.log(result);

// Bulk import
const bulkResult = await client.bulkImport([
    { post_id: 123, post_type: 'post' },
    { post_id: 124, post_type: 'page' }
]);
console.log(bulkResult);

// Get history
const history = await client.getHistory({
    per_page: 50,
    status: 'success'
});
console.log(history);
```

## Rate Limiting

Implement custom rate limiting:

```php
add_filter( 'rest_pre_dispatch', function( $result, $server, $request ) {
    // Only apply to CCP endpoints
    if ( strpos( $request->get_route(), '/ccp/v1/' ) !== 0 ) {
        return $result;
    }

    $user_id = get_current_user_id();
    $rate_key = 'ccp_rate_limit_' . $user_id;
    $max_requests = 60; // 60 requests
    $time_window = 60; // per minute

    $requests = get_transient( $rate_key ) ?: 0;

    if ( $requests >= $max_requests ) {
        return new WP_Error(
            'rate_limit_exceeded',
            'Too many requests. Please try again later.',
            [ 'status' => 429 ]
        );
    }

    set_transient( $rate_key, $requests + 1, $time_window );

    return $result;
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
- `rest_forbidden` - Permission denied
- `invalid_param` - Invalid parameter
- `import_failed` - Import operation failed
- `authentication_failed` - Cannot authenticate with external site
- `invalid_post_data` - Post data validation failed

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

4. **Rate limit external API calls**

## Next Steps

- [Hooks and Filters](hooks.md) - Available customization hooks
- [Custom Post Types](post-types.md) - Post type support
- [Extending Guide](index.md) - Back to extending overview
