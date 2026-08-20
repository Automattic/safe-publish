# Quickstart

Get started with Safe Publish in minutes. This guide will walk you through setting up the plugin and importing your first post from a non-production WordPress site.

## Step 1: Install on Both Sites

Safe Publish must be installed on both sites.

1. Download the plugin from the releases page or clone the repository.
2. Upload to your `wp-content/plugins/` directory.

## Step 2: Set Up Authentication

Add a matching shared secret to both sites' `wp-config.php`:

```php
define( 'SAFE_PUBLISH_SHARED_SECRET', 'your-secure-random-string-here' );
```

You can generate a secure value with: `openssl rand -base64 32`. The secret must be at least 16 characters long; 32 or more is recommended for security. See the [Authentication guide](concepts/authentication.md) for full details.

## Step 3: Activate and Configure the Plugin

Activate the plugin through the WordPress admin panel or [code](https://docs.wpvip.com/plugins/activate-plugins-through-code/), and proceed with configuration.

### Source Site Configuration

1. Navigate to **Safe Publish → Settings** in the WordPress admin sidebar.
2. Set the **Sync Mode** to **Source - Content will come from this site.**
3. In **Connected Site URL**, enter the destination site's URL.
4. Click **Save Settings**.

### Destination Site Configuration

1. Navigate to **Safe Publish → Settings** in the WordPress admin sidebar.
2. Set the **Sync Mode** to **Destination - Content will be published to this site**.
3. In **Connected Site URL**, enter the source site's URL.
4. Click **Save Settings**.

### Optional: Basic Authentication

1. On your **source site**, install a basic auth plugin.
2. In **Safe Publish → Settings** on the destination, enter the username and password.
3. Basic authentication is applied on top of Shared Secret authentication when credentials are configured.

## Step 4: Test the Connection

1. Click the **Test Connection** button in the settings panel.
2. If successful, you'll see a success message with the response time.
3. If there's an error, see our [troubleshooting guide](troubleshooting.md).

## Step 5: Browse and Import Content

### Browse Posts

- Open **Safe Publish → Manage**. The Posts tab displays content from the source site.
- Use the **Type** dropdown to switch between Posts, Pages, and custom post types.
- Search, sort, and filter posts using the built-in controls.
- Use **Local State** to show _All_, _Not imported_, _Up to date_, or _Outdated_ posts. A failed live comparison is shown as _Sync check failed_; it is not a separate local state.

### Import Options

You have three ways to import content:

**1. Single Post Import**

- Click the **Import** action on any post.
- A new post is imported as a draft with supported content, media, terms, and REST-exposed metadata.

**2. Bulk Import**

- Select multiple posts using checkboxes.
- Click **Import** in the bulk actions menu.
- Importable posts are imported as drafts. Posts that are already up to date or cannot be imported are skipped, and the confirmation shows how many.

**3. Manage Already-Imported Posts**

- Set **Local State** to **Up to date** or **Outdated**.
- Depending on the row state, use **Compare**, **Import**, **Edit**, **Trash**, or **Roll back**. Compare and re-import are offered when the source is newer.

## Step 6: Review Imported Content

1. Navigate to **Posts** (or the relevant post type) in your admin.
2. Look for newly created drafts.
3. Review the content, make any necessary adjustments.
4. Publish when ready.

## Imports and exports

The **Manage** page combines the source catalog, imported content, and the Needs attention inbox — see [Managing Imports](concepts/imports.md). The **Audit Log** page lists logged events across every channel, including exports served to destinations — see [Audit Log](concepts/audit-log.md).

## Next Steps

- Learn about [authentication methods](concepts/authentication.md) in detail.
- Understand the [import process](concepts/import-process.md).
- Explore [content validation](concepts/validation.md).
- Customize behavior with [hooks and filters](extending/hooks.md).

## Need Help?

- Check the [troubleshooting guide](troubleshooting.md).
- Review [common issues](troubleshooting.md#common-issues).
- Report bugs on the GitHub repository.
