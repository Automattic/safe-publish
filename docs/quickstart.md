# Quickstart

Get started with Compliant Content Publisher in minutes. This guide will walk you through setting up the plugin and importing your first post from a non-production WordPress site.

## Prerequisites

- WordPress 6.8 or higher
- PHP 8.2 or higher
- Access to both your production and non-production WordPress sites
- Administrator privileges on both sites

## Step 1: Install and Activate

1. Download the plugin from the releases page or clone the repository
2. Upload to your `wp-content/plugins/` directory
3. Activate through the WordPress admin panel

## Step 2: Configure the Plugin

1. Navigate to **CC Publisher** in your WordPress admin sidebar
2. Enter your **non-production site URL** (e.g., `https://staging.example.com`)
3. Set the **number of posts** to fetch (default: 10, recommended: 10-50)
4. Click **Save Settings**

## Step 3: Set Up Authentication

The plugin supports two authentication methods:

### Option A: Shared Secret (Recommended for Production)

1. On your **non-production site**, install the included `ccp-auth.php` mu-plugin:
   - Copy `client-mu-plugins/ccp-auth.php` to your non-prod site's `wp-content/mu-plugins/` directory
2. Define a shared secret in both sites' `wp-config.php`:
   ```php
   define( 'CCP_SHARED_SECRET', 'your-secure-random-string-here' );
   ```
3. The plugin will automatically use this for secure authentication

### Option B: Basic Authentication (Development Only)

1. On your **non-production site**, install a basic auth plugin
2. In the CC Publisher settings, enter the username and password
3. **Note**: This method is only recommended for local development environments

## Step 4: Test the Connection

1. Click the **Test Connection** button in the settings panel
2. If successful, you'll see a green checkmark and available post types
3. If there's an error, see our [troubleshooting guide](troubleshooting.md)

## Step 5: Browse and Import Content

### Browse Posts

- After saving settings, the DataViews interface will display posts from your non-prod site
- Use the **Post Type** dropdown to switch between Posts, Pages, and custom post types
- Search, sort, and filter posts using the built-in controls

### Import Options

You have three ways to import content:

**1. Single Post Import**
- Click the **Create Draft** action on any post
- The post will be imported as a draft with all content, metadata, and images

**2. Bulk Import**
- Select multiple posts using checkboxes
- Click **Bulk Import** in the bulk actions menu
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

## Import History

Track all your imports in the **Import History** tab:

- View timestamp, source URL, and import status
- See which user performed each import
- Filter by date range or status
- Export history for auditing purposes

## Next Steps

- Learn about [authentication methods](concepts/authentication.md) in detail
- Understand the [import process](concepts/import-process.md)
- Explore [content validation](concepts/validation.md)
- Customize behavior with [hooks and filters](extending/hooks.md)

## Need Help?

- Check the [troubleshooting guide](troubleshooting.md)
- Review [common issues](troubleshooting.md#common-issues)
- Report bugs on the GitHub repository
