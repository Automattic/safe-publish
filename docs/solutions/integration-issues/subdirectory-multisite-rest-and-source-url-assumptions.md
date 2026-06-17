---
title: Subdirectory multisite breaks REST-URL and source-site-URL assumptions
date: 2026-06-17
category: docs/solutions/integration-issues
module: safe-publish
problem_type: integration_issue
component: service_object
symptoms:
  - "Diff/Difference preview returns rest_no_route 404 on a subdirectory-multisite subsite while the post listing loads fine"
  - "Featured and in-content images silently fail to import with zero [Safe-Publish-Media] entries in the PHP error log"
  - "Failures reproduce only on subdirectory multisite (non-root subsite), never on single-site installs with identical code"
root_cause: config_error
resolution_type: code_fix
severity: high
related_components:
  - frontend_stimulus
  - rails_controller
tags:
  - multisite
  - rest-api
  - wordpress-vip
  - subdirectory-subsite
  - media-import
  - url-resolution
  - silent-failure
---

# Subdirectory multisite breaks REST-URL and source-site-URL assumptions

## Problem

On a subdirectory multisite (WordPress VIP) with the plugin active on a non-root subsite (e.g. `https://host.example/organon/`), the diff-preview returned `rest_no_route` (404) and both featured and in-content images were silently dropped during import, even though post bodies imported correctly. The same plugin code works on single-site, so multisite is the differentiator. The common root cause: **the subsite path is lost** when constructing REST URLs and deriving the source-site URL.

## Symptoms

- **Diff preview** fails with `{"code":"rest_no_route","message":"No route was found...","data":{"status":404}}`.
- **Featured images** never appear on imported posts; in-content `<img>` URLs still point at the source host.
- **No `[Safe-Publish-Media]` lines in the PHP error log** — the media path produced zero log output, not even a failed-fetch entry.
- The **catalog listing works** and **post bodies import correctly**, so auth and raw-content access are clearly functioning.

## What Didn't Work

These dead-ends were each disproved by concrete evidence, which is what made them valuable:

- **"Jetpack Photon rewrites image URLs to `i0.wp.com` so they don't match the source host."** Ruled out: the import uses `content.raw` (Photon doesn't rewrite raw DB content), there is zero Photon code in the plugin, and the user confirmed Photon runs in *both* the working single-site and the failing multisite. If Photon were the cause, single-site would fail too.
- **"The HMAC signed-path breaks on subdirectory multisite."** Ruled out by reading `includes/auth/class-vip-safe-auth.php:232-235`, which correctly strips everything after `/wp-json` before signing. Since the catalog listing succeeds, the signature and `home_url()` origin validation already pass for authenticated requests.
- **"The diff fails on `raw_data_unavailable` because `context=edit` doesn't grant raw content."** Ruled out: the diff and the body import fetch raw from the *same* endpoint (`wp/v2/{type}/{id}?context=edit&_embed=1`). If raw were unavailable, the body import would also fail — but it imports fine.
- **"The featured-image fetch 404'd and was logged."** Ruled out by the *absence* of evidence: `Logger::write()` (`includes/utils/class-logger.php:75`) writes every failure to `error_log()` with a `[Safe-Publish-<Channel>]` prefix. No `[Safe-Publish-Media]` lines meant the code never reached a fetch — it exited at a silent guard *before* attempting one.

Separately, a reported **"Date Filter" 400** was a stale-build red herring, not a multisite bug: v0.0.4 sent millisecond ISO timestamps that the backend `Datetime_Sanitizer` (`includes/utils/class-datetime-sanitizer.php:53`, which accepts only `DATE_ATOM`, `Y-m-d\TH:i:s`, and `Y-m-d`) rejects. Fixed in `trunk` by commit `f20b2bc`; resolved by deploying a current build.

## Solution

> Note: both fixes were prescribed and filed as Linear issues (VIPCMS-1986 diff, VIPCMS-1987 media, VIPCMS-1988 multisite tests). They are **not yet merged**.

### Bug 1 — Diff `rest_no_route` 404 (`src/api/diff.ts:105`)

The diff is the *only* frontend caller that hardcodes a domain-root-relative REST URL; every other request goes through `window.safePublishAdminData.ajaxurl` (admin-ajax), which is why listing works.

```ts
// Before — root-relative URL resolves to the network ROOT site on a subsite,
// where the plugin is inactive → rest_no_route 404.
const res = await fetch( '/wp-json/safe-publish/v1/diff-preview', {
	method: 'POST',
	headers,
	body: JSON.stringify( payload ),
} );
```

Preferred fix — use `@wordpress/api-fetch`, which resolves the correct subsite REST root and injects the nonce automatically:

```ts
import apiFetch from '@wordpress/api-fetch';

return apiFetch( {
	path: '/safe-publish/v1/diff-preview',
	method: 'POST',
	data: payload,
} );
```

Add `wp-api-fetch` as a script dependency; the manual `restNonce` threading can then be dropped. (Alternative: localize `'restUrl' => esc_url_raw( rest_url( 'safe-publish/v1/' ) )` in `includes/admin/class-admin-page.php:106-109` and build the URL from it — the localized data currently exposes `ajaxurl`, `nonce`, and `restNonce`, but no REST root.)

### Bug 2 — Featured and content images silently skipped (`includes/admin/class-post-import-service.php`)

The media source URL is derived per-post from `source_link` via `extract_site_url()` (`:741`), which returns `scheme://host` only — it strips the subsite path and comes back effectively unusable here. The guards then skip silently:

```php
// import_featured_image_attachment() returns 0 — not false — when unset (:1970):
if ( empty( $featured_media_id ) || empty( $source_link ) ) {
	return 0;
}

// ...but the caller only treats a strict false as failure (:1119), so the 0
// sails through silently and this logged-error branch is never reached:
if ( false === $featured_attachment_id ) {
	return $this->build_error_result( $fields, $error_message );
}
```

```php
// :760-762 — content path returns plain-sanitized content (no media import)
// when source_link is empty, leaving <img> URLs pointing at the source.
if ( empty( $source_link ) ) {
	return $this->sanitize_field( $content, self::FIELD_CONTENT );
}
```

Fix: derive the media source URL from the stored `Options::OPTION_CONNECTED_SITE_URL` — which *includes* the subsite path and is already used by the catalog at `includes/admin/class-admin-ajax-controller.php:968`, `:1068`, and `:1410` — instead of re-deriving a host-only URL from `source_link`. Replace the silent `empty()` skips with a `Media_Logger` warning event so a missed image is observable. Apply to **both** the single-import and bulk-import paths (AGENTS.md requires the two paths stay in sync).

## Why This Works

Both bugs share one root cause: **the subsite path is lost.**

- Bug 1: a root-relative `/wp-json/...` URL resolves against the document origin (the domain root), not the subsite. On `https://host.example/organon/`, the real REST root is `…/organon/wp-json/`, but the browser hits `…/wp-json/` at the network root site, where the plugin isn't active — hence `rest_no_route`. `@wordpress/api-fetch` knows the subsite's REST root and builds the correct URL.
- Bug 2: `extract_site_url()` reduces a full URL to `scheme://host`, discarding `/organon`. The connected-site option already stores the full subsite URL, so using it preserves the path the media importer needs.

The `rest_no_route` (404) status itself was the localizing clue: a 404 means URL/routing, not auth — a 401/403 would have pointed back at the signature. And the *missing* `[Safe-Publish-Media]` log lines proved the media code exited at a guard before any fetch, sending the investigation to the `empty()` checks rather than down a fetch-failure rabbit hole.

## Prevention

- **Never hardcode root-relative REST URLs in the frontend.** Standardize on `@wordpress/api-fetch` (or a localized `rest_url()`-derived base) for every REST call. A lint rule flagging the string literal `/wp-json/` in `src/**` would have caught Bug 1 at authoring time.
- **Don't conflate "not configured" with "success."** A guard returning `0` that a caller only checks for `false` is a silent-skip trap. Either return a consistent error sentinel (`false`/`WP_Error`) or emit a `Media_Logger` warning so skipped media is observable — silence should never be a valid success state for a data-migration step.
- **Log absence is a signal.** The decisive diagnostic was that `Logger::write()` (`class-logger.php:75`) emits a `[Safe-Publish-<Channel>]` prefix on every failure, so *no* log line meant *no* attempt. Keep failure logging unconditional at the entry of each import step so "it never ran" is always distinguishable from "it ran and failed."
- **Add multisite integration coverage.** There is currently no multisite test in `tests/integration/sync-*` (filed as VIPCMS-1988). A subdirectory-multisite fixture asserting that (a) the diff endpoint resolves against the subsite REST root and (b) featured + content images import with subsite-pathed source URLs would have caught both bugs, since single-site tests pass on identical code.
- **Verify environment parity before blaming a shared component.** Photon ran in both working and failing environments; confirming that early eliminated it and pointed straight at multisite as the true differentiator. When a bug is environment-specific, list what differs *and* what's shared, and rule out the shared parts first.

## Related Issues

- Linear: **VIPCMS-1986** (diff `rest_no_route` 404), **VIPCMS-1987** (media silent skip), **VIPCMS-1988** (multisite integration test coverage).
- Docs that assume a single-site REST root and should note the multisite limitation (refresh candidates):
  - `docs/troubleshooting.md` — the "REST API not found"/404 section has no multisite/subdirectory note; natural home for the `rest_no_route` subsite symptom.
  - `docs/concepts/import-process.md` — the Fetch stage and the "base URL (scheme and host)" description silently assume a single-site REST root and that media URLs carry the subdirectory path.
  - `docs/concepts/validation.md` — the REST API check points at `https://your-site.com/wp-json` with no subsite caveat.
  - `docs/extending/api.md` — documents the `/wp-json/safe-publish/v1/diff-preview` endpoint that is hardcoded client-side.
- Adjacent GitHub issues (source-site-URL identity / media import): #116 (`META_SOURCE_SITE_URL` and source/destination ID remap), #78 (gallery/playlist shortcodes leak unresolved attachment IDs).
