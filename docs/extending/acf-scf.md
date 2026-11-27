# Migrating ACF and Secure Custom Fields Values

Advanced Custom Fields (ACF) and Secure Custom Fields (SCF) store their values in regular post meta, but Safe Publish imports only the core REST API `meta` object. ACF and SCF expose their values under a separate top-level `acf` object, which Safe Publish does not read by default. This recipe bridges that gap with two filters.

SCF is WordPress' drop-in fork of ACF: it keeps the same `acf` REST key, the same **Show in REST API** setting, and the same `update_field()` and `acf_add_local_field_group()` functions. Everything below applies to both.

It has two parts:

- A one-time setting on the **source** site that exposes ACF values in the REST response.
- A small integration on the **destination** site that folds those values into the imported meta.

> [!NOTE]
>
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
        $acf = is_array( $data['acf'] ?? null ) ? $data['acf'] : array();

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
                // Write the key last, so it replaces the source's value.
                unset( $meta[ $key ] );
                $meta[ $key ] = $value;
            }
        }

        return $meta;
    },
    10,
    2
);
```

The merged values are written to destination postmeta unchanged. Source values are untrusted, though: a compromised source could send markup. `get_field()` returns stored values unescaped, so a theme that echoes them risks stored XSS. Since ACF 6.2.7, and in SCF, `the_field()` escapes unsafe HTML, unless the `acf/the_field/allow_unsafe_html` filter turns that off. Sanitize each value for its field type before storing — `sanitize_text_field()` for plain text, `wp_kses_post()` for rich text — or make sure your theme escapes each value for its context on output.

A sanitizer in the loop covers only the fields the `acf` object holds. To migrate a known set of fields, and sanitize each one on every import, replace the loop with an explicit allowlist:

```php
foreach ( array( 'hero_title', 'subtitle' ) as $field ) {
    $value = $acf[ $field ] ?? $meta[ $field ] ?? '';
    $value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

    // Write the field last, so it replaces the source's value.
    unset( $meta[ $field ] );
    $meta[ $field ] = $value;
}
```

The allowlist writes each field on every import, after the source's meta, so the sanitized value replaces any value the source sent for that key. The value comes from the `acf` object, or from the source's own `meta` object when `acf` lacks it, and a missing or non-scalar value stores the field empty.

## Complex fields: repeater, group, and flexible content

Repeater, group, and flexible-content fields are stored across many postmeta rows in ACF's internal format (a row count plus per-row subfield keys). Writing the REST `acf` shape directly as a single meta value does not reconstruct that format, so the field will not render.

To store them correctly, let ACF write them through `update_field()`. That call needs the destination post ID, which does not exist yet when `safe_publish_source_post_meta` runs — the filter fires while fetching from the source, before the post is created. Stash the payload in the filter, then replay it once the post is saved:

```php
// Stash the raw acf payload during the fetch, on every import.
add_filter(
    'safe_publish_source_post_meta',
    function ( array $meta, array $data ): array {
        $acf = is_array( $data['acf'] ?? null ) ? $data['acf'] : array();

        // Write the stash last, so the replay runs after the source's meta.
        unset( $meta['_acf_import_payload'] );
        $meta['_acf_import_payload'] = wp_json_encode( $acf );

        return $meta;
    },
    PHP_INT_MAX,
    2
);

// Replay it through ACF once the stash lands on the saved post.
$replay_acf = function ( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
    if ( '_acf_import_payload' !== $meta_key ) {
        return;
    }

    // Always clear the stash, even when ACF is unavailable to consume it.
    delete_post_meta( $post_id, '_acf_import_payload' );

    if ( ! function_exists( 'update_field' ) ) {
        return;
    }

    $acf = json_decode( (string) $meta_value, true );

    // Replay only the expected fields, each sanitized for its shape. This
    // example expects a slides repeater with a caption text subfield.
    $rows = array();

    if ( isset( $acf['slides'] ) && is_array( $acf['slides'] ) ) {
        foreach ( $acf['slides'] as $row ) {
            $caption = is_array( $row ) ? ( $row['caption'] ?? '' ) : '';
            $caption = is_scalar( $caption ) ? (string) $caption : '';

            $rows[] = array( 'caption' => sanitize_text_field( $caption ) );
        }
    }

    // Write the field on every import, so ACF reads only sanitized rows. The
    // field key pins its definition, whatever the post's meta holds.
    update_field( 'field_650f1a2b3c4d5', $rows, $post_id );
};
add_action( 'added_post_meta', $replay_acf, 10, 4 );
add_action( 'updated_post_meta', $replay_acf, 10, 4 );
```

The replay calls `update_field()` only for the fields you expect, because `update_field()` stores a selector it does not recognize as a plain meta key. The source can send its own `_acf_import_payload` too, so the replay validates every payload it receives. The stash filter runs at the latest priority and writes the stash last, so the replay runs after the source's meta and after the keys that other filters add. When the payload holds no rows for the field, or fails to decode, the field is stored empty.

To adapt the replay, replace `field_650f1a2b3c4d5` with your repeater's field key, and include every subfield it defines in each row, sanitized, because ACF keeps a stored subfield that the replayed row omits.

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

- **Source values are untrusted.** Safe Publish stores meta verbatim and does not sanitize values. The allowlist and complex-field recipes sanitize only the fields they write. Step 1's values and the source's other meta import unchanged. Escaping each value for its context on output covers every key.
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
