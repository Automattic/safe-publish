# Migrating Yoast SEO Meta

Yoast SEO stores its per-post settings — meta description, SEO title, focus keyphrase, robots directives, and social metadata — in regular post meta under keys prefixed with `_yoast_wpseo_`. The leading underscore makes them protected meta, and WordPress core's REST API omits a meta key from the `meta` object unless it is registered with `show_in_rest`. Yoast registers only a few of these keys for REST, so most of them are absent from the `meta` object Safe Publish imports and do not reach the destination by default.

This is the same root cause as the ACF/SCF gap: Safe Publish imports only what appears in the core REST `meta` object. Unlike ACF, though, Yoast exposes no separate top-level object carrying the raw stored values — `yoast_head_json` is rendered, read-only output, not the underlying meta — so a destination-side filter has nothing to read. The fix must run on the **source**: register the Yoast keys for REST so they enter the `meta` object. From there Safe Publish writes them to destination post meta unchanged.

> [!NOTE]
>
> This exposes the raw stored values, not Yoast's rendered output. The destination must have Yoast SEO active for the values to be interpreted as SEO settings; Safe Publish stores the meta but does not install or configure Yoast.

## Prerequisite: expose the Yoast keys on the source

Register each Yoast meta key you want to migrate with `show_in_rest` on the source site. Registering a protected (underscore-prefixed) key for REST is allowed and does not un-hide it from any other consumer — it only adds it to the core `meta` object:

```php
add_action( 'init', function (): void {
    $keys = array(
        // Some of these may already be registered for REST by Yoast;
        // re-registering is harmless.
        '_yoast_wpseo_title'                 => 'string',
        '_yoast_wpseo_metadesc'              => 'string',
        '_yoast_wpseo_focuskw'               => 'string',
        '_yoast_wpseo_meta-robots-noindex'   => 'string',
        '_yoast_wpseo_meta-robots-nofollow'  => 'string',
        '_yoast_wpseo_opengraph-title'       => 'string',
        '_yoast_wpseo_opengraph-description' => 'string',
        '_yoast_wpseo_twitter-title'         => 'string',
        '_yoast_wpseo_twitter-description'   => 'string',
    );

    foreach ( $keys as $key => $type ) {
        register_post_meta( '', $key, array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => $type,
            // Authorizes REST writes to this protected key (core needs an
            // auth_callback to write a protected key over REST). Reads are
            // governed by show_in_rest alone, not by this callback.
            'auth_callback' => function (): bool {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }
}, 20 );
```

Register the keys against every post type whose SEO settings should migrate. Passing `''` as the object subtype registers them globally, which is the simplest choice; pass a specific post type instead if you want to scope it. A post type exposes the `meta` object in REST only when it supports `custom-fields`; confirm with `post_type_supports( $type, 'custom-fields' )` and add it via `add_post_type_support()` where missing.

Once registered, fetch a source post with `context=edit` and confirm the keys now appear under the core `meta` object. No destination-side code is required — Safe Publish's import writes every key in `meta` verbatim, including underscore-prefixed ones.

## Optional: restrict to a known set on the destination

If you prefer to control exactly which keys land on the destination — for example to accept the description and title but drop robots directives — filter the imported meta with `safe_publish_source_post_meta`:

```php
add_filter(
    'safe_publish_source_post_meta',
    function ( array $meta ): array {
        $allowed = array(
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_focuskw',
        );

        foreach ( array_keys( $meta ) as $key ) {
            if (
                str_starts_with( (string) $key, '_yoast_wpseo_' ) &&
                ! in_array( $key, $allowed, true )
            ) {
                unset( $meta[ $key ] );
            }
        }

        return $meta;
    }
);
```

## Caveats

- **Register on the source, not the destination.** The raw values are absent from the REST response until the source registers the keys for REST. A destination-only filter cannot recover a value the payload never carried.
- **Registering exposes the values to REST reads.** `show_in_rest` makes each key readable in the source's REST API — including unauthenticated `view`-context requests on published posts, not just Safe Publish's authenticated import. Yoast strips its own title, description, and focus keyphrase keys for readers without `edit_post`; the keys you register here have no such guard. To keep a key out of public responses, remove it for those users with a `rest_prepare_{post_type}` filter.
- **Some values are source-specific.** `_yoast_wpseo_canonical` holds a full URL and `_yoast_wpseo_primary_category` holds a source term ID; migrating them verbatim points the destination at source values. Remap or omit these — the allowlist filter above is a convenient place to do so.
- **Social image keys carry source references.** `_yoast_wpseo_opengraph-image` and `_yoast_wpseo_twitter-image` store source URLs (and companion `-id` keys store source attachment IDs) that Safe Publish does not remap.
- **Yoast must be active on the destination.** The values are stored as post meta regardless, but are interpreted as SEO settings only when Yoast SEO is installed and active on the destination.
- **Values are stored verbatim.** As with all imported meta, Safe Publish does not sanitize these. They originate from an editor-authored SEO panel, but treat the source as untrusted and sanitize or escape per context if that assumption does not hold.

## Next Steps

- [Migrating ACF and Secure Custom Fields Values](acf-scf.md) — the same pattern for custom fields
- [Hooks and Filters](hooks.md) — the `safe_publish_source_post_meta` reference
- [Custom Post Types](post-types.md) — registering post types and scalar meta
- [Extending Guide](index.md) — back to the extending overview
