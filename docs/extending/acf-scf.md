# Migrating ACF and Secure Custom Fields Values

Advanced Custom Fields (ACF) and Secure Custom Fields (SCF) store their values in regular post meta, but Safe Publish imports only the core REST API `meta` object. ACF and SCF expose their values under a separate top-level `acf` object, which Safe Publish does not read by default. This recipe bridges that gap with two filters.

SCF is WordPress' drop-in fork of ACF: it keeps the same `acf` REST key, the same **Show in REST API** setting, and the same `update_field()` and `acf_add_local_field_group()` functions. Everything below applies to both.

It has two parts:

- A one-time setting on the **source** site that exposes ACF values in the REST response.
- A small integration on the **destination** site that folds those values into the imported meta.

> [!NOTE]
> The Step 1 scalar recipe works as shown. The complex-field pattern is a functional starting point — ACF configurations vary, so test any integration in a staging environment and expect to adapt the complex-field and reference-field handling (field keys, ID remapping) to your own field definitions.

## Prerequisite: expose the acf object on the source

ACF and SCF hide field groups from the REST API by default. Enable **Show in REST API** on each source field group whose values should migrate:

- In the field group editor, open the **Group Settings** tab and turn on **Show in REST API**; or
- When registering the group in code, set `show_in_rest`:

```php
acf_add_local_field_group( array(
    'key'          => 'group_hero',
    'title'        => 'Hero',
    'fields'       => array( /* ... */ ),
    'location'     => array( /* ... */ ),
    'show_in_rest' => 1,
) );
```

Once enabled, the source REST response for each post includes a top-level `acf` object keyed by field name. No query-string change is needed.

## Step 1: merge scalar values into meta

Use `safe_publish_source_post_meta` to read the `acf` object from the REST response and fold its values into the meta array Safe Publish persists:

```php
add_filter(
    'safe_publish_source_post_meta',
    function ( array $meta, array $data ): array {
        $acf = isset( $data['acf'] ) && is_array( $data['acf'] ) ? $data['acf'] : array();

        foreach ( $acf as $key => $value ) {
            $key = (string) $key;

            // Skip protected and Safe Publish-reserved keys.
            if (
                str_starts_with( $key, '_' ) ||
                str_starts_with( $key, 'safe_publish_' )
            ) {
                continue;
            }

            // Scalars map straight to meta. Repeater, group, and
            // flexible-content fields need update_field() instead.
            if ( is_scalar( $value ) ) {
                $meta[ $key ] = $value;
            }
        }

        return $meta;
    },
    10,
    2
);
```

The merged values are written to destination postmeta unchanged. For a text, number, or true/false field, that is all that is required: the stored value is the same value ACF reads back.

To migrate only a known set of fields, replace the loop with an explicit allowlist:

```php
foreach ( array( 'hero_title', 'subtitle' ) as $field ) {
    if ( isset( $acf[ $field ] ) && is_scalar( $acf[ $field ] ) ) {
        $meta[ $field ] = $acf[ $field ];
    }
}
```

## Complex fields: repeater, group, and flexible content

Repeater, group, and flexible-content fields are stored across many postmeta rows in ACF's internal format (a row count plus per-row subfield keys). Writing the REST `acf` shape directly as a single meta value does not reconstruct that format, so the field will not render.

To store them correctly, let ACF write them through `update_field()`. That call needs the destination post ID, which does not exist yet when `safe_publish_source_post_meta` runs — the filter fires while fetching from the source, before the post is created. Stash the payload in the filter, then replay it once the post is saved:

```php
// Stash the raw acf payload during the fetch.
add_filter(
    'safe_publish_source_post_meta',
    function ( array $meta, array $data ): array {
        if ( isset( $data['acf'] ) && is_array( $data['acf'] ) ) {
            $meta['_acf_import_payload'] = wp_json_encode( $data['acf'] );
        }
        return $meta;
    },
    10,
    2
);

// Replay it through ACF once the stash lands on the saved post.
$replay_acf = function ( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
    if ( '_acf_import_payload' !== $meta_key ) {
        return;
    }

    // Always clear the stash, even when ACF is unavailable to consume it.
    delete_post_meta( $post_id, '_acf_import_payload' );

    if ( function_exists( 'update_field' ) ) {
        $acf = json_decode( (string) $meta_value, true );
        if ( is_array( $acf ) ) {
            foreach ( $acf as $selector => $value ) {
                update_field( $selector, $value, $post_id );
            }
        }
    }
};
add_action( 'added_post_meta', $replay_acf, 10, 4 );
add_action( 'updated_post_meta', $replay_acf, 10, 4 );
```

## Optional: adjusting the source request

`safe_publish_source_fetch_query_args` modifies the query string of the source fetch. The `acf` object is already present once the field group opts into REST, so this filter is not needed for the recipe above. Reach for it when your source honors a custom query argument, or for other request tweaks:

```php
add_filter(
    'safe_publish_source_fetch_query_args',
    function ( array $query_args, array $context ): array {
        // Illustrative: a query argument your source site understands. The
        // acf object itself needs no query change.
        $query_args['example_source_flag'] = '1';
        return $query_args;
    },
    10,
    2
);
```

Avoid `_fields`: it is subtractive, so requesting `_fields=acf` drops the title, content, excerpt, and meta that Safe Publish requires and the import will fail. If you must use it, list every field Safe Publish reads alongside `acf`.

## Caveats

- **Source field groups must expose REST.** Values appear under `acf` only when the source field group has **Show in REST API** enabled.
- **Matching definitions on the destination.** Safe Publish stores the values, but the editor renders them as ACF/SCF controls only when ACF or SCF is active on the destination and has matching field groups with the same field keys. Safe Publish does not create or sync field groups.
- **Reference fields carry source IDs.** Image, file, post object, relationship, taxonomy, and user fields transfer as the source site's IDs. Remap them to destination IDs yourself when the referenced objects differ across sites.
- **Complex fields need `update_field()`.** The raw REST shape of repeater, group, and flexible-content fields is not a functional ACF value on its own.

## Stability

`meta` and `acf` are stable top-level keys for this pattern. `_links` and `_embedded` are WordPress core REST internals and may change between releases — rely on them at your own risk.

## Next Steps

- [Hooks and Filters](hooks.md) — the `safe_publish_source_fetch_query_args` and `safe_publish_source_post_meta` reference
- [Custom Post Types](post-types.md) — registering post types and scalar meta
- [Extending Guide](index.md) — back to the extending overview
