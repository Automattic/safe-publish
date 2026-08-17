# Content Validation

Before importing content, Safe Publish performs several validation checks to ensure data integrity and prevent errors.

## Validation Stages

### 1. URL Validation

**What it checks:**

- Source site URL is properly formatted and includes a host.
- URL uses the `http` or `https` scheme.
- The host is not a literal localhost name or a private/reserved IP address.

URL validation checks only the configured value. The subsequent connection test
probes `wp-json/wp/v2/posts?context=edit&per_page=1` to check whether the site is
reachable and accepts Safe Publish authentication. It does not probe the
`safe-publish/v1` routes.

**Common failures:**

- Invalid URL format (missing protocol, malformed)
- Unsupported URL scheme
- A localhost or private/reserved IP literal
- Site not accessible (DNS issues, firewall, down)
- Non-WordPress site or REST API disabled

**How to fix:**

- Include `https://` outside local development. The validator also accepts
  `http://`, but it does not provide transport encryption.
- Verify the site is accessible in a browser.
- Check REST API: visit `https://your-site.com/wp-json`.

### 2. Authentication Validation

**What it checks:**

- A shared secret is configured on both sites.
- Authentication succeeds with the source site.
- Optional Basic Authentication credentials pass any upstream access gate.

**Common failures:**

- Missing or incorrect shared secret
- Mismatched secrets between sites
- Wrong username/password
- Basic Authentication is required by the source but missing or incorrect

**How to fix:**

- See the [Authentication guide](authentication.md).
- Verify shared secret matches on both sites.
- Check basic auth credentials are correct.

### 3. Post Data Validation

**What it checks:**

- The response is a non-empty JSON object.
- Required raw edit-context fields are present (`title`, `content`, `excerpt`).
- The source post has a positive ID and a non-empty title.
- Post type is supported.

Empty post content is valid and does not by itself block an import.

**Common failures:**

- Malformed JSON response
- Missing required fields
- Unsupported post type
- Missing raw edit-context fields, usually because authentication or edit
  permissions failed

**How to fix:**

- Check the source post in WordPress admin.
- Verify the post type is enabled in REST API.
- Try re-saving the post on the source site.

### 4. Content Sanitization

**What happens:**

By default, content is not sanitized during import — WordPress core save-time filters (including kses) are suppressed to preserve content fidelity. This matches WordPress core importer behavior and is appropriate because the source site is already authenticated via HMAC.

Kses sanitization can be opted into via the [`safe_publish_import_kses`](../extending/hooks.md#safe_publish_import_kses) filter. When enabled, content is checked against the allowed HTML tags before persisting. If sanitization would modify the content, the import fails with a descriptive error.

**Common failures (when kses is enabled):**

- Content contains HTML tags or attributes outside the allowlist.
- Inline scripts or event handlers present.

**How to fix:**

- Edit the post in the block editor and remove disallowed elements.
- Remove any custom HTML that contains scripts or event handlers.
- Customize the allowlist via the [`safe_publish_import_kses_allowed_html`](../extending/hooks.md#safe_publish_import_kses_allowed_html) filter.

### 5. Media Validation

Media is validated during the import process itself, not as a separate
pre-import step. A failed inline-media or featured-image import aborts the post
import. An inline-media failure cleans up attachments created earlier in the
same attempt. A featured-image failure leaves any inline-media attachments
created earlier in the attempt in place. In either case, an existing destination
post is left unchanged.

A link that looks like media but resolves to a page (for example, an HTML page
at a `.pdf` URL) is kept as a link. That case is not treated as a failed media
download.

**What it checks (at import time):**

- The downloaded content is media — image, video, audio, or PDF — identified from its bytes, not its URL extension.
- Media can be downloaded from the source URL.

**Common failures:**

- Broken image URLs (404 errors)
- Images on non-accessible domains
- Unsupported file types

**How to fix:**

- Verify images exist on the source site.
- Check image URLs are publicly accessible.
- Ensure media is a supported type (image, video, audio, or PDF).

For a list of validation error codes and solutions, see the [Troubleshooting guide](../troubleshooting.md#validation-errors).

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [Managing Imports](imports.md) - Browse and review your imports
- [Troubleshooting](../troubleshooting.md) - Solve common issues
