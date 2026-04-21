# Content Validation

Before importing content, Safe Publish performs several validation checks to ensure data integrity and prevent errors.

## Validation Stages

### 1. URL Validation

**What it checks:**

- External site URL is properly formatted
- URL uses HTTPS (required for production domains; HTTP is allowed for local development domains like `.test`, `.local`, `.dev`)
- Domain is accessible and responds to requests
- Site is a WordPress installation with REST API enabled

**Common failures:**

- Invalid URL format (missing protocol, malformed)
- HTTP instead of HTTPS (on production domains)
- Site not accessible (DNS issues, firewall, down)
- Non-WordPress site or REST API disabled

**How to fix:**

- Ensure URL includes `https://` protocol
- Verify the site is accessible in a browser
- Check REST API: visit `https://your-site.com/wp-json`

### 2. Authentication Validation

**What it checks:**

- Credentials are provided (shared secret or basic auth)
- Authentication succeeds with the external site
- User has required permissions (for basic auth)

**Common failures:**

- Missing or incorrect shared secret
- Mismatched secrets between sites
- Wrong username/password
- User lacks required permissions

**How to fix:**

- See the [Authentication guide](authentication.md)
- Verify shared secret matches on both sites
- Check basic auth credentials are correct

### 3. Post Data Validation

**What it checks:**

- Post data structure is valid JSON
- Required fields are present (`id`, `title`)
- Post type is supported
- Content is not empty

**Common failures:**

- Malformed JSON response
- Missing required fields
- Unsupported post type
- Empty or corrupted content

**How to fix:**

- Check the source post in WordPress admin
- Verify the post type is enabled in REST API
- Try re-saving the post on the source site

### 4. Content Sanitization

**What happens:**

Content is sanitized at import time using WordPress core's `wp_kses_post()`. This is not a discrete pre-import validation step — it happens during the import itself.

- Dangerous HTML and scripts are stripped
- If sanitization modifies the content, the import reports a sanitization warning

**Common failures:**

- Content contains disallowed HTML tags or attributes
- Inline scripts or event handlers present

**How to fix:**

- Edit the post in the block editor and remove disallowed elements
- Remove any custom HTML that contains scripts or event handlers

### 5. Media Validation

Media is validated during the import process itself, not as a separate pre-import step. Failed media does not block the import — the post is still created and the original external URL is preserved.

**What it checks (at import time):**

- Image file types are supported (validated via `wp_check_filetype()`)
- Images can be downloaded from the source URL

**Common failures:**

- Broken image URLs (404 errors)
- Images on non-accessible domains
- Unsupported file types

**How to fix:**

- Verify images exist on the source site
- Check image URLs are publicly accessible
- Ensure images are in supported formats (JPEG, PNG, GIF, WebP)

For a list of validation error codes and solutions, see the [Troubleshooting guide](../troubleshooting.md#validation-errors).

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [History](history.md) - Track your imports
- [Troubleshooting](../troubleshooting.md) - Solve common issues
