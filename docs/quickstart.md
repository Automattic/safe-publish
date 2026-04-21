# Quickstart

Get started with Safe Publish in minutes. This guide will walk you through setting up the plugin and importing your first post from a non-production WordPress site.

## Step 1: Install on Both Sites

Safe Publish must be installed on both sites.

1. Download the plugin from the releases page or clone the repository
2. Upload to your `wp-content/plugins/` directory

## Step 2: Set Up Authentication

Add a matching shared secret to both sites' `wp-config.php`:

```php
define( 'SAFE_PUBLISH_SHARED_SECRET', 'your-secure-random-string-here' );
```

You can generate a secure value with: `openssl rand -base64 32`. The secret must be at least 32 characters long. See the [Authentication guide](concepts/authentication.md) for full details.

## Step 3: Activate and Configure the Plugin

Activate the plugin through the WordPress admin panel or [code](https://docs.wpvip.com/plugins/activate-plugins-through-code/), and proceed with configuration.

### Source Site Configuration

1. Navigate to **Safe Publish** in the WordPress admin sidebar
2. Set the **Sync Mode** to `Export`
3. In **Connected Site URL**, enter the destination site's URL
4. Click **Save Settings**

### Destination Site Configuration

1. Navigate to **Safe Publish** in the WordPress admin sidebar
2. Set the **Sync Mode** to `Import`
3. In **Connected Site URL**, enter the source site's URL
4. Optionally set the **number of posts** to another value
5. Click **Save Settings**

### Optional: Basic Authentication

1. On your **source site**, install a basic auth plugin
2. In the Safe Publish settings, enter the username and password
3. Basic authentication is applied on top of Shared Secret authentication when credentials are configured

## Step 4: Test the Connection

1. Click the **Test Connection** button in the settings panel
2. If successful, you'll see a success message with the response time
3. If there's an error, see our [troubleshooting guide](troubleshooting.md)

## Step 5: Browse and Import Content

### Browse Posts

- After saving settings, the DataViews interface will display posts from your source site
- Use the **Post Type** dropdown to switch between Posts, Pages, and custom post types
- Search, sort, and filter posts using the built-in controls

### Import Options

You have three ways to import content:

**1. Single Post Import**

- Click the **Import** action on any post
- The post will be imported as a draft with all content, metadata, and images

**2. Bulk Import**

- Select multiple posts using checkboxes
- Click **Import / Update** in the bulk actions menu
- All selected posts will be imported as drafts

**3. Preview Before Import**

- Click **Post Diff** to see a side-by-side comparison
- Review the content before importing
- Useful for verifying changes or troubleshooting

## Step 6: Review Imported Content

1. Navigate to **Posts** (or the relevant post type) in your admin
2. Look for newly created drafts
3. Review the content, make any necessary adjustments
4. Publish when ready

## History

Track all your imports and exports in the **History** page. See the [History guide](concepts/history.md) for details.

## Next Steps

- Learn about [authentication methods](concepts/authentication.md) in detail
- Understand the [import process](concepts/import-process.md)
- Explore [content validation](concepts/validation.md)
- Customize behavior with [hooks and filters](extending/hooks.md)

## Need Help?

- Check the [troubleshooting guide](troubleshooting.md)
- Review [common issues](troubleshooting.md#common-issues)
- Report bugs on the GitHub repository
