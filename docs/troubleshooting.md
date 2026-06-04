# Troubleshooting

This guide helps you resolve common issues with Safe Publish. See the [Debugging Tools](#debugging-tools) section below for further diagnostic steps.

## Common Issues

### Connection Issues

#### "Authentication failed" error

**Symptoms**: Cannot connect to source site, authentication error message

**Solutions**:

1. **Verify Safe Publish is configured on both sites**:
   - Safe Publish must be installed and active on both sites.
   - Sync Mode and Connected Site URL must be set with correct values on both sites.

2. **Verify shared secret matches on both sites**:
   - Check `wp-config.php` on both sites.
   - Secret must be identical (case-sensitive).
   - No extra spaces or quotes

3. **For basic auth**:
   - Verify username and password are correct.
   - Ensure user has `edit_posts` capability.
   - Check basic auth plugin is installed on source site.

4. **Check HTTPS**:
   - Production domains must use HTTPS (HTTP is allowed for local development domains like `.test`, `.local`, `.dev`).
   - Verify SSL certificates are valid.
   - Test site URL in browser.

#### "Connection timeout" error

**Symptoms**: Request hangs then fails with timeout error

**Solutions**:

1. **Check network connectivity**:
   - Can you access the source site in a browser?
   - Ping the source site domain.
   - Check for firewall rules blocking the connection.

2. **Increase PHP timeout limits**:

   ```php
   // In wp-config.php
   set_time_limit( 300 ); // 5 minutes
   ```

3. **Check server resources**:
   - High CPU/memory usage can cause timeouts.
   - Monitor server during import.

4. **Try fewer posts**:
   - Reduce "Number of Posts" setting.
   - Import in smaller batches.

#### "Unauthorized" or "403 Forbidden" error

**Symptoms**: Authentication request rejected with 401 or 403 response

**Solutions**:

1. Verify Safe Publish is installed on the source site with **Sync Mode** set to `Export`.
2. For basic auth, confirm the user has the `edit_posts` capability.
3. Check server firewall rules aren't blocking incoming requests.

#### "REST API not found" error

**Symptoms**: 404 error when connecting to source site

**Solutions**:

1. **Verify REST API is enabled**:
   - Visit `https://your-site.com/wp-json/` in browser.
   - Should return JSON, not 404.

2. **Check permalink structure**:
   - Source site must have permalinks enabled.
   - Go to Settings → Permalinks and save.

3. **Disable conflicting plugins**:
   - Some security plugins block REST API.
   - Temporarily disable and test.

4. **Check .htaccess**:
   - Corrupted .htaccess can block REST API.
   - Try resaving permalinks.

### Import Issues

#### "No posts found" error

**Symptoms**: Connection works but no posts are displayed

**Solutions**:

1. **Check post status**:
   - By default, the REST API returns only published posts (authenticated requests with `context=edit` may include other statuses).
   - Verify posts exist and are published on source site.

2. **Verify post type is exposed in REST API**:
   - Custom post types must support `'show_in_rest' => true`.
   - Check post type registration.

3. **Check post permissions**:
   - Authenticated user must have read access to posts.
   - Review user roles and capabilities.

4. **Increase "Number of Posts" setting**:
   - Default is 10, try increasing to 50.
   - Maybe your posts are pagination past first page.

#### "Media import failed" error

**Symptoms**: Post imports but images are missing

**Solutions**:

1. **Check image URLs are accessible**:
   - Copy image URL and open in browser.
   - Must return 200 OK, not 404 or 403.

2. **Verify image URLs are absolute**:
   - Relative URLs may not work.
   - Images must be publicly accessible.

3. **Check file size limits**:
   - PHP `upload_max_filesize` and `post_max_size`
   - WordPress `WP_MEMORY_LIMIT`
   - Reduce image sizes if needed.

4. **Check disk space**:
   - Ensure destination site has sufficient disk space.
   - Check server quotas.

5. **Review upload directory permissions**:
   - `wp-content/uploads/` must be writable.
   - Check file permissions (755 for directories, 644 for files).

#### "Invalid content structure" error

**Symptoms**: Validation fails, content structure error

**Solutions**:

1. **Check for broken HTML**:
   - Edit source post in WordPress.
   - Look for validation errors in block editor.
   - Fix any invalid HTML or blocks.

2. **Verify Gutenberg blocks are valid**:
   - Switch to code editor view.
   - Check block comments are closed.
   - Look for corrupted block syntax.

3. **Test with simple content**:
   - Create a test post with simple content.
   - If it imports, issue is with complex content.
   - Simplify problematic content.

4. **Check for unsupported blocks**:
   - Some third-party blocks may not transfer.
   - Try converting to core blocks.

#### Post creation failed

**Symptoms**: Import process completes but no draft post appears

**Solutions**:

1. **Check user permissions**: The importing user must have permission to create posts of that post type.
2. **Check for database errors**: Enable `WP_DEBUG_LOG` and review `wp-content/debug.log` for insert failures.
3. **Verify post type is registered** on the destination site — custom post types must exist on both sites.

#### ACF or SCF fields do not appear in the destination editor

**Symptoms**: ACF or Secure Custom Fields (SCF) values were imported as post meta, but the destination editor does not show the matching field controls.

**Solutions**:

1. **Verify the source REST response**:
   - Fetch the source post with `context=edit`.
   - Confirm the expected keys appear under the core `meta` object.
   - Keys exposed only under a top-level `acf` object are not imported.

2. **Check the imported destination meta**:
   - Confirm the destination post has the value key, such as `hero_title`.
   - Confirm it also has the companion reference key, such as `_hero_title`, when editor rendering is required.

3. **Check the destination field definitions**:
   - ACF or SCF must be active on the destination.
   - The destination must have matching field groups and field keys.
   - Safe Publish stores the meta values but does not currently create or sync ACF/SCF field groups.

4. **Resolve the mismatch**:
   - Add or sync the matching field group definitions on the destination.
   - If editor rendering is not needed, no action is required; the values are already stored as post meta.

#### Duplicate content imported

**Symptoms**: Same post imported multiple times

**Solutions**:

Safe Publish tracks imported posts using the `safe_publish_source_post_id` meta key and automatically detects already-imported content. Posts that already exist locally are shown with an **Update** action instead of **Import**.

If duplicates still occur:

1. **Check the Imports → Posts tab** to see whether the post was imported from different sessions.
2. Delete duplicate drafts manually.

#### Embedded posts display as plain links

This is a known limitation of WordPress' embed cache when imported posts reference each other while still in draft. See [Embedded posts may render as plain links](concepts/import-process.md#embedded-posts-may-render-as-plain-links) for the cause and recovery steps.

### Validation Errors

| Error code                 | Cause                                                  | Solution                                                 |
| -------------------------- | ------------------------------------------------------ | -------------------------------------------------------- |
| `invalid_url`              | URL not valid or accessible                            | Check URL format and ensure the site is reachable        |
| `request_failed`           | HTTP request to the source site failed                 | Check network connectivity and site availability         |
| `meta_update_failed`       | One or more post meta keys failed to save              | Check destination site database permissions              |
| `unknown_taxonomy`         | A taxonomy from the source post does not exist locally | Register the taxonomy on the destination site            |
| `source_author_unresolved` | Source post has no author or its author was deleted    | Restore the source author or attribute the post manually |
| `source_author_not_found`  | Source author's email has no match on the destination  | Create a user with the same email on the destination     |

### Performance Issues

#### Slow import times

**Symptoms**: Imports take a very long time to complete

**Solutions**:

1. **Reduce content size**:
   - Fewer images = faster imports
   - Smaller images = faster downloads
   - Optimize images before importing.

2. **Increase PHP limits**:

   ```php
   // In wp-config.php or php.ini
   max_execution_time = 300
   memory_limit = 256M
   ```

3. **Check network speed**:
   - Slow network connection affects image downloads.
   - Consider importing during off-peak hours.

4. **Import in smaller batches**:
   - Don't bulk import too many posts at once.
   - Try 5-10 posts at a time.

5. **Enable object cache**:
   - Install Redis or Memcached.
   - Significantly improves performance.

#### Browser becomes unresponsive

**Symptoms**: Browser freezes during bulk import

**Solutions**:

1. **Don't bulk import too many posts**:
   - Limit to 5-10 posts at a time.
   - Browser needs to process responses.

2. **Close unnecessary browser tabs**:
   - Frees up memory.
   - Improves responsiveness.

3. **Use modern browser**:
   - Chrome or Firefox recommended
   - Keep browser updated.

4. **Increase browser memory** (advanced):
   - Some browsers allow memory limit increases.
   - Or use different browser.

### Admin UI Issues

#### DataViews not loading

**Symptoms**: Posts list doesn't appear, loading spinner forever

**Solutions**:

1. **Check JavaScript console**:
   - Open browser DevTools (F12).
   - Look for JavaScript errors.
   - Report errors with details.

2. **Clear browser cache**:
   - Hard refresh (Ctrl+Shift+R / Cmd+Shift+R).
   - Clear cache and cookies.

3. **Check for JavaScript conflicts**:
   - Disable other plugins temporarily.
   - Test if issue persists.

4. **Verify assets are loading**:
   - Check Network tab in DevTools.
   - Look for 404 errors on JS/CSS files.

#### Settings not saving

**Symptoms**: Changes to settings don't persist

**Solutions**:

1. **Check for PHP errors**:
   - Enable WP_DEBUG in wp-config.php.
   - Check debug.log for errors.

2. **Verify user permissions**:
   - User must have `manage_options` capability.
   - Check user role.

3. **Check for plugin conflicts**:
   - Disable other plugins.
   - Test if settings save.

4. **Database issues**:
   - Check wp_options table is writable.
   - Review database errors in logs.

## Debugging Tools

### Enable WordPress Debug Mode

Add the [WordPress debug constants](local-development.md#debugging) to `wp-config.php`, then check `wp-content/debug.log` for error messages.

### Browser Developer Tools

1. Open DevTools (F12).
2. Check Console tab for JavaScript errors.
3. Check Network tab for failed requests.
4. Review request/response details.

### Query Monitor Plugin

Install [Query Monitor](https://wordpress.org/plugins/query-monitor/) for advanced debugging:

- Database queries
- HTTP requests
- PHP errors
- Hook execution

### Test Authentication Separately

Use the **Test Connection** button in settings to test authentication independently of imports.

### Imports → Failures Tab

Check the **Imports → Failures** tab for:

- Error messages recorded at import time
- Source URL of each failed attempt
- Timestamp of the attempt

Recovery is fixing the underlying issue and re-importing from Source Posts. Once an item is no longer needed, use the **Remove** action (per-row or bulk) to clear it from the tab; this only deletes the record and does not affect the source.

## Getting Help

If you still can't resolve the issue:

1. **Check the Imports → Failures tab** for detailed error messages.
2. **Enable debug mode** and collect error logs.
3. **Reproduce the issue** in a clean environment if possible.
4. **Report the issue** via GitHub Issues with:
   - WordPress and PHP versions
   - Steps to reproduce
   - Error messages
   - Screenshots if applicable
   - Debug log excerpts

## Support Channels

- **GitHub Issues**: Bug reports and feature requests
- **Documentation**: Check docs for detailed guides
- **Local Development**: Use dev environment for testing

## Resetting Configuration

If you need to start fresh:

### Reset Plugin Settings

```php
// Using WP-CLI
wp option delete safe_publish_connected_site_url
wp option delete safe_publish_sync_mode
wp option delete safe_publish_basic_auth_username
wp option delete safe_publish_basic_auth_password
```

### Clear Import History

Import history is stored in two custom tables (`safe_publish_imports` and `safe_publish_import_items`). Individual rows can be rolled back from the **Imports → Posts** tab (per-row or bulk). To clear history entirely, use the Complete Reset below.

### Complete Reset

Delete the options listed above using WP-CLI, then deactivate and reactivate the plugin. Reactivation restores default option values.

## Next Steps

- [Local Development](local-development.md) - Set up debugging environment
- [Authentication](concepts/authentication.md) - Detailed auth setup
- [Import Process](concepts/import-process.md) - How imports work
- [Hooks and Filters](extending/hooks.md) - Customize behavior
