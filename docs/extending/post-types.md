# Custom Post Type Support

Safe Publish supports importing posts, pages, and custom post types. This guide explains how to enable and configure custom post type support.

## Default Supported Types

By default, Safe Publish supports:

- **Posts** (`post`)
- **Pages** (`page`)
- Any custom post type registered with `'show_in_rest' => true`

## Enabling Custom Post Types

### Automatic Support

If your custom post type is registered with REST API support, it's automatically available:

```php
register_post_type( 'book', [
    'label' => 'Books',
    'public' => true,
    'show_in_rest' => true, // This enables REST API support
    'rest_base' => 'books', // Optional: customize REST endpoint
    // ... other arguments
] );
```

After registering with REST API support:

1. Go to **Safe Publish** in WordPress admin
2. Your custom post type should appear in the **Post Type** dropdown automatically

## REST API Configuration

### Basic REST API Setup

Ensure your post type exposes required fields:

```php
register_post_type( 'book', [
    'public' => true,
    'show_in_rest' => true,
    'rest_base' => 'books',
    'rest_controller_class' => 'WP_REST_Posts_Controller',
    'supports' => [
        'title',
        'editor',
        'thumbnail',
        'excerpt',
        'custom-fields',
    ],
] );
```

### Custom REST Fields

Add custom fields to REST API response:

```php
register_rest_field( 'book', 'isbn', [
    'get_callback' => function( $post ) {
        return get_post_meta( $post['id'], 'isbn', true );
    },
    'schema' => [
        'description' => 'Book ISBN',
        'type' => 'string',
    ],
] );

register_rest_field( 'book', 'author_name', [
    'get_callback' => function( $post ) {
        return get_post_meta( $post['id'], 'author_name', true );
    },
    'schema' => [
        'description' => 'Book Author',
        'type' => 'string',
    ],
] );
```

## Custom Taxonomies

### Automatic Taxonomy Support

Taxonomies registered with REST API support are automatically imported:

```php
register_taxonomy( 'genre', 'book', [
    'label' => 'Genres',
    'public' => true,
    'show_in_rest' => true,
    'rest_base' => 'genres',
] );
```

## Hierarchical Post Types

Hierarchical post types (like pages) can be imported, but parent-child relationships are not mapped across sites. Imported posts will not retain their parent assignment from the source.

```php
register_post_type( 'documentation', [
    'public' => true,
    'hierarchical' => true,
    'show_in_rest' => true,
    'supports' => [ 'title', 'editor', 'page-attributes' ],
] );
```

## ACF (Advanced Custom Fields) Support

ACF fields are not exposed via the REST API by default. Once exposed (e.g. via ACF's built-in REST API setting or `register_rest_field()`), they are imported automatically like any other post meta.

## Troubleshooting

### Custom Post Type Not Appearing

**Check REST API support:**

```bash
# Test if post type is available via REST API
curl https://your-staging-site.com/wp-json/wp/v2/your-post-type
```

**Common issues:**

- `show_in_rest` not set to `true`
- Custom rest_base not matching
- REST API disabled on source site

### Custom Fields Not Importing

**Expose fields in REST API:**

```php
add_action( 'rest_api_init', function() {
    register_rest_field( 'your_post_type', 'your_field', [
        'get_callback' => function( $post ) {
            return get_post_meta( $post['id'], 'your_field', true );
        },
    ] );
} );
```

### Taxonomies Not Importing

**Check taxonomy REST support:**

```php
// Ensure taxonomy is exposed
register_taxonomy( 'your_taxonomy', 'your_post_type', [
    'show_in_rest' => true,
    // other args...
] );
```

## Examples

### Complete Book Post Type

```php
// Register custom post type
function register_book_post_type() {
    register_post_type( 'book', [
        'label' => 'Books',
        'public' => true,
        'show_in_rest' => true,
        'rest_base' => 'books',
        'supports' => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
        'taxonomies' => [ 'genre' ],
    ] );

    register_taxonomy( 'genre', 'book', [
        'label' => 'Genres',
        'public' => true,
        'show_in_rest' => true,
        'rest_base' => 'genres',
    ] );
}
add_action( 'init', 'register_book_post_type' );

// Add custom fields to REST API
function expose_book_fields() {
    register_rest_field( 'book', 'isbn', [
        'get_callback' => fn( $post ) => get_post_meta( $post['id'], 'isbn', true ),
        'schema' => [ 'type' => 'string' ],
    ] );

    register_rest_field( 'book', 'author_name', [
        'get_callback' => fn( $post ) => get_post_meta( $post['id'], 'author_name', true ),
        'schema' => [ 'type' => 'string' ],
    ] );
}
add_action( 'rest_api_init', 'expose_book_fields' );
```

## Next Steps

- [Hooks and Filters](hooks.md) - Available customization hooks
- [REST API Extension](api.md) - Extending the API
- [Extending Guide](index.md) - Back to extending overview
