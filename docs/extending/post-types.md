# Custom Post Type Support

Compliant Content Publisher supports importing posts, pages, and custom post types. This guide explains how to enable and configure custom post type support.

## Default Supported Types

By default, CCP supports:

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

1. Go to **CC Publisher** in WordPress admin
2. Click **Fetch Post Types** in the settings
3. Your custom post type should appear in the dropdown

### Manual Support

If you need to add support for a post type without modifying its registration:

```php
add_filter( 'ccp_supported_post_types', function( $post_types ) {
    $post_types[] = 'book';
    $post_types[] = 'product';
    return $post_types;
} );
```

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

### Custom Metadata Import

Import custom fields during the import process:

```php
add_action( 'ccp_post_imported', function( $post_id, $source_url ) {
    // Get the source post data
    $source_post_id = get_post_meta( $post_id, '_ccp_source_post_id', true );

    // Fetch additional data from source
    $response = wp_remote_get( add_query_arg( [
        'fields' => 'isbn,author_name',
    ], $source_url ) );

    if ( ! is_wp_error( $response ) ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // Import custom fields
        if ( isset( $data['isbn'] ) ) {
            update_post_meta( $post_id, 'isbn', sanitize_text_field( $data['isbn'] ) );
        }

        if ( isset( $data['author_name'] ) ) {
            update_post_meta( $post_id, 'author_name', sanitize_text_field( $data['author_name'] ) );
        }
    }
}, 10, 2 );
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

### Custom Taxonomy Mapping

Map taxonomies from source to destination:

```php
add_filter( 'ccp_pre_import_post', function( $post_data ) {
    // Map source taxonomy to destination taxonomy
    if ( isset( $post_data['tax_input']['source_genre'] ) ) {
        $post_data['tax_input']['genre'] = $post_data['tax_input']['source_genre'];
        unset( $post_data['tax_input']['source_genre'] );
    }

    // Create terms if they don't exist
    if ( isset( $post_data['tax_input']['genre'] ) ) {
        foreach ( $post_data['tax_input']['genre'] as $term_name ) {
            if ( ! term_exists( $term_name, 'genre' ) ) {
                wp_insert_term( $term_name, 'genre' );
            }
        }
    }

    return $post_data;
} );
```

## Hierarchical Post Types

For hierarchical post types (like pages), parent-child relationships are preserved:

```php
register_post_type( 'documentation', [
    'public' => true,
    'hierarchical' => true, // Enables parent-child relationships
    'show_in_rest' => true,
    'supports' => [ 'title', 'editor', 'page-attributes' ],
] );
```

### Custom Parent Handling

Modify how parent relationships are imported:

```php
add_filter( 'ccp_pre_import_post', function( $post_data ) {
    // Skip parent relationship (import as top-level)
    if ( isset( $post_data['post_parent'] ) ) {
        $post_data['post_parent'] = 0;
    }

    // Or map to a different parent
    if ( isset( $post_data['post_parent'] ) && $post_data['post_parent'] > 0 ) {
        // Find corresponding parent in destination site
        $source_parent_id = $post_data['post_parent'];
        $mapped_parent = get_posts( [
            'post_type' => 'documentation',
            'meta_key' => '_ccp_source_post_id',
            'meta_value' => $source_parent_id,
            'posts_per_page' => 1,
        ] );

        if ( ! empty( $mapped_parent ) ) {
            $post_data['post_parent'] = $mapped_parent[0]->ID;
        }
    }

    return $post_data;
} );
```

## Post Type-Specific Configuration

### Different Settings Per Type

Apply different import logic based on post type:

```php
add_filter( 'ccp_pre_import_post', function( $post_data ) {
    switch ( $post_data['post_type'] ) {
        case 'book':
            // Books always go to 'pending' status
            $post_data['post_status'] = 'pending';
            break;

        case 'product':
            // Products need review before publish
            $post_data['post_status'] = 'draft';
            $post_data['tax_input']['product_status'] = [ 'needs-review' ];
            break;

        case 'documentation':
            // Documentation auto-published if from trusted source
            if ( strpos( $post_data['_source_url'], 'docs.internal.com' ) !== false ) {
                $post_data['post_status'] = 'publish';
            }
            break;
    }

    return $post_data;
} );
```

### Validation Per Type

Add type-specific validation:

```php
add_filter( 'ccp_validate_post_data', function( $is_valid, $post_data ) {
    $post_type = $post_data['post_type'] ?? 'post';

    switch ( $post_type ) {
        case 'book':
            // Books require ISBN
            if ( empty( $post_data['meta']['isbn'] ) ) {
                return new WP_Error( 'missing_isbn', 'Books require an ISBN' );
            }
            break;

        case 'product':
            // Products require price
            if ( empty( $post_data['meta']['price'] ) ) {
                return new WP_Error( 'missing_price', 'Products require a price' );
            }
            break;
    }

    return $is_valid;
}, 10, 2 );
```

## ACF (Advanced Custom Fields) Support

If using ACF with custom post types:

```php
add_action( 'ccp_post_imported', function( $post_id, $source_url ) {
    // Get ACF field data from source
    $response = wp_remote_get( add_query_arg( [
        'acf_format' => 'standard',
    ], $source_url ) );

    if ( ! is_wp_error( $response ) ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['acf'] ) ) {
            foreach ( $data['acf'] as $field_name => $field_value ) {
                update_field( $field_name, $field_value, $post_id );
            }
        }
    }
}, 10, 2 );
```

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

// Import custom fields
function import_book_fields( $post_id, $source_url ) {
    if ( get_post_type( $post_id ) !== 'book' ) {
        return;
    }

    $response = wp_remote_get( $source_url );
    if ( is_wp_error( $response ) ) {
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data['isbn'] ) ) {
        update_post_meta( $post_id, 'isbn', sanitize_text_field( $data['isbn'] ) );
    }

    if ( isset( $data['author_name'] ) ) {
        update_post_meta( $post_id, 'author_name', sanitize_text_field( $data['author_name'] ) );
    }
}
add_action( 'ccp_post_imported', 'import_book_fields', 10, 2 );
```

## Next Steps

- [Hooks and Filters](hooks.md) - Available customization hooks
- [REST API Extension](api.md) - Extending the API
- [Extending Guide](index.md) - Back to extending overview
