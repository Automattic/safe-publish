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

1. Go to **Safe Publish** in WordPress admin.
2. Your custom post type should appear in the **Post Type** dropdown automatically.

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

### Custom Post Meta

Safe Publish imports the core REST API `meta` object. It does not import arbitrary top-level REST fields added with `register_rest_field()`. For custom post types, the post type must also support `custom-fields`.

Register each meta key that should migrate:

```php
add_action( 'init', function() {
    register_post_meta( 'book', 'isbn', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
    ] );

    register_post_meta( 'book', 'author_name', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
    ] );
} );
```

For private or sensitive meta, add an `auth_callback` that requires edit access to the post. Safe Publish requests authenticated with the shared secret use `context=edit`, so edit-only meta remains available to the importer without making it public in unauthenticated REST responses.

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

Hierarchical post types (like pages) can be imported, and parent-child relationships are mapped across sites. A child post's source parent is matched to its destination counterpart through the `safe_publish_source_post_id` meta lookup, so the parent must already be imported — or be part of the same bulk batch — when the child imports. By default an unresolved parent aborts the import; the `safe_publish_import_allow_orphans` filter relaxes this, importing the child as a top-level post with a warning. See [Parent Resolution](../concepts/import-process.md#parent-resolution) for full details.

```php
register_post_type( 'documentation', [
    'public' => true,
    'hierarchical' => true,
    'show_in_rest' => true,
    'supports' => [ 'title', 'editor', 'page-attributes' ],
] );
```

## ACF and Secure Custom Fields Support

ACF and Secure Custom Fields (SCF) store field values in regular post meta, but Safe Publish reads only the core REST API `meta` object — not the separate top-level `acf` object those plugins expose.

The recommended way to migrate ACF/SCF values, including repeater, group, and flexible-content fields, is the filter-based recipe in [Migrating ACF and Secure Custom Fields Values](acf-scf.md).

For a few scalar fields, registering the value keys with `register_post_meta()` and `show_in_rest => true` on the source remains a valid lightweight option. Register only the keys that should migrate — exposing every ACF/SCF or underscored key can leak internal values into REST responses — plus the companion reference key (such as `_hero_title` for `hero_title`) when the destination editor must recognize the value:

```php
add_action( 'init', function() {
    register_post_meta( 'post', 'hero_title', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
    ] );

    register_post_meta( 'post', '_hero_title', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
    ] );
} );
```

Either way, the destination stores the meta without field registration, but renders ACF/SCF controls only when ACF or SCF is active there with matching field definitions. Safe Publish does not create or sync field groups.

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

**Expose fields through the REST API `meta` object:**

```php
add_action( 'init', function() {
    register_post_meta( 'your_post_type', 'your_field', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
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

// Add custom fields to the REST API meta object.
function expose_book_fields() {
    register_post_meta( 'book', 'isbn', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
    ] );

    register_post_meta( 'book', 'author_name', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => true,
    ] );
}
add_action( 'init', 'expose_book_fields' );
```

## Next Steps

- [Hooks and Filters](hooks.md) - Available customization hooks
- [REST API Extension](api.md) - Extending the API
- [Extending Guide](index.md) - Back to extending overview
