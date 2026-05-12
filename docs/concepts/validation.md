# Content Validation

Before importing content, Safe Publish performs several validation checks to ensure data integrity and prevent errors.

## Validation Stages

### 1. URL Validation

**What it checks:**

- Source site URL is properly formatted.
- URL uses HTTPS (required for production domains; HTTP is allowed for local development domains like `.test`, `.local`, `.dev`).
- Domain is accessible and responds to requests.
- Site is a WordPress installation with REST API enabled.

**Common failures:**

- Invalid URL format (missing protocol, malformed)
- HTTP instead of HTTPS (on production domains)
- Site not accessible (DNS issues, firewall, down)
- Non-WordPress site or REST API disabled

**How to fix:**

- Ensure URL includes `https://` protocol.
- Verify the site is accessible in a browser.
- Check REST API: visit `https://your-site.com/wp-json`.

### 2. Authentication Validation

**What it checks:**

- Credentials are provided (shared secret or basic auth).
- Authentication succeeds with the source site.
- User has required permissions (for basic auth).

**Common failures:**

- Missing or incorrect shared secret
- Mismatched secrets between sites
- Wrong username/password
- User lacks required permissions

**How to fix:**

- See the [Authentication guide](authentication.md).
- Verify shared secret matches on both sites.
- Check basic auth credentials are correct.

### 3. Post Data Validation

**What it checks:**

- Post data structure is valid JSON.
- Required fields are present (`id`, `title`).
- Post type is supported.
- Content is not empty.

**Common failures:**

- Malformed JSON response
- Missing required fields
- Unsupported post type
- Empty or corrupted content

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

Media is validated during the import process itself, not as a separate pre-import step. Failed media does not block the import — the post is still created and the original source URL is preserved.

**What it checks (at import time):**

- Image file types are supported (validated via `wp_check_filetype()`).
- Images can be downloaded from the source URL.

**Common failures:**

- Broken image URLs (404 errors)
- Images on non-accessible domains
- Unsupported file types

**How to fix:**

- Verify images exist on the source site.
- Check image URLs are publicly accessible.
- Ensure images are in supported formats (JPEG, PNG, GIF, WebP).

For a list of validation error codes and solutions, see the [Troubleshooting guide](../troubleshooting.md#validation-errors).

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [History](history.md) - Track your imports
- [Troubleshooting](../troubleshooting.md) - Solve common issues
