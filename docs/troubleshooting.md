# Troubleshooting

This guide helps you resolve common issues with Safe Publish. If you can't find a solution here, check the [local development guide](local-development.md) for debugging tools.

## Common Issues

### Connection Issues

#### "Authentication failed" error

**Symptoms**: Cannot connect to external site, authentication error message

**Solutions**:

1. **Verify shared secret matches on both sites**:
   - Check `wp-config.php` on both sites
   - Secret must be identical (case-sensitive)
   - No extra spaces or quotes

2. **For basic auth**:
   - Verify username and password are correct
   - Ensure user has `edit_posts` capability
   - Check basic auth plugin is installed on source site

3. **Check HTTPS**:
   - Both sites must use HTTPS
   - Verify SSL certificates are valid
   - Test site URL in browser

4. **Verify MU plugin is installed** (shared secret method):
   - File exists at `client-mu-plugins/safe-publish-auth.php` on source site
   - File has not been modified
   - MU plugins directory exists and is readable

#### "Connection timeout" error

**Symptoms**: Request hangs then fails with timeout error

**Solutions**:

1. **Check network connectivity**:
   - Can you access the source site in a browser?
   - Ping the source site domain
   - Check for firewall rules blocking the connection

2. **Increase PHP timeout limits**:

   ```php
   // In wp-config.php
   set_time_limit( 300 ); // 5 minutes
   ```

3. **Check server resources**:
   - High CPU/memory usage can cause timeouts
   - Monitor server during import

4. **Try fewer posts**:
   - Reduce "Number of Posts" setting
   - Import in smaller batches

#### "REST API not found" error

**Symptoms**: 404 error when connecting to source site

**Solutions**:

1. **Verify REST API is enabled**:
   - Visit `https://your-site.com/wp-json/` in browser
   - Should return JSON, not 404

2. **Check permalink structure**:
   - Source site must have permalinks enabled
   - Go to Settings → Permalinks and save

3. **Disable conflicting plugins**:
   - Some security plugins block REST API
   - Temporarily disable and test

4. **Check .htaccess**:
   - Corrupted .htaccess can block REST API
   - Try resaving permalinks

### Import Issues

#### "No posts found" error

**Symptoms**: Connection works but no posts are displayed

**Solutions**:

1. **Check post status**:
   - Only published posts are fetched
   - Verify posts exist and are published on source site

2. **Verify post type is exposed in REST API**:
   - Custom post types must support `'show_in_rest' => true`
   - Check post type registration

3. **Check post permissions**:
   - Authenticated user must have read access to posts
   - Review user roles and capabilities

4. **Increase "Number of Posts" setting**:
   - Default is 10, try increasing to 50
   - Maybe your posts are pagination past first page

#### "Media import failed" error

**Symptoms**: Post imports but images are missing

**Solutions**:

1. **Check image URLs are accessible**:
   - Copy image URL and open in browser
   - Must return 200 OK, not 404 or 403

2. **Verify image URLs are absolute**:
   - Relative URLs may not work
   - Images must be publicly accessible

3. **Check file size limits**:
   - PHP `upload_max_filesize` and `post_max_size`
   - WordPress `WP_MEMORY_LIMIT`
   - Reduce image sizes if needed

4. **Check disk space**:
   - Ensure destination site has sufficient disk space
   - Check server quotas

5. **Review upload directory permissions**:
   - `wp-content/uploads/` must be writable
   - Check file permissions (755 for directories, 644 for files)

#### "Invalid content structure" error

**Symptoms**: Validation fails, content structure error

**Solutions**:

1. **Check for broken HTML**:
   - Edit source post in WordPress
   - Look for validation errors in block editor
   - Fix any invalid HTML or blocks

2. **Verify Gutenberg blocks are valid**:
   - Switch to code editor view
   - Check block comments are closed
   - Look for corrupted block syntax

3. **Test with simple content**:
   - Create a test post with simple content
   - If it imports, issue is with complex content
   - Simplify problematic content

4. **Check for unsupported blocks**:
   - Some third-party blocks may not transfer
   - Try converting to core blocks

#### Duplicate content imported

**Symptoms**: Same post imported multiple times

**Solutions**:

1. **Check Import History before importing**:
   - See if post was already imported
   - Delete duplicate drafts manually

2. **Feature request**:
   - Automatic duplicate detection is planned
   - Currently requires manual checking

3. **Use unique post meta** (developers):
   ```php
   // Check if post was already imported
   $existing = get_posts( [
       'meta_key' => '_safe_publish_source_url',
       'meta_value' => $source_url,
       'post_status' => 'any',
   ] );
   ```

### Performance Issues

#### Slow import times

**Symptoms**: Imports take a very long time to complete

**Solutions**:

1. **Reduce content size**:
   - Fewer images = faster imports
   - Smaller images = faster downloads
   - Optimize images before importing

2. **Increase PHP limits**:

   ```php
   // In wp-config.php or php.ini
   max_execution_time = 300
   memory_limit = 256M
   ```

3. **Check network speed**:
   - Slow network connection affects image downloads
   - Consider importing during off-peak hours

4. **Import in smaller batches**:
   - Don't bulk import too many posts at once
   - Try 5-10 posts at a time

5. **Enable object cache**:
   - Install Redis or Memcached
   - Significantly improves performance

#### Browser becomes unresponsive

**Symptoms**: Browser freezes during bulk import

**Solutions**:

1. **Don't bulk import too many posts**:
   - Limit to 5-10 posts at a time
   - Browser needs to process responses

2. **Close unnecessary browser tabs**:
   - Frees up memory
   - Improves responsiveness

3. **Use modern browser**:
   - Chrome or Firefox recommended
   - Keep browser updated

4. **Increase browser memory** (advanced):
   - Some browsers allow memory limit increases
   - Or use different browser

### Admin UI Issues

#### DataViews not loading

**Symptoms**: Posts list doesn't appear, loading spinner forever

**Solutions**:

1. **Check JavaScript console**:
   - Open browser DevTools (F12)
   - Look for JavaScript errors
   - Report errors with details

2. **Clear browser cache**:
   - Hard refresh (Ctrl+Shift+R / Cmd+Shift+R)
   - Clear cache and cookies

3. **Check for JavaScript conflicts**:
   - Disable other plugins temporarily
   - Test if issue persists

4. **Verify assets are loading**:
   - Check Network tab in DevTools
   - Look for 404 errors on JS/CSS files

#### Settings not saving

**Symptoms**: Changes to settings don't persist

**Solutions**:

1. **Check for PHP errors**:
   - Enable WP_DEBUG in wp-config.php
   - Check debug.log for errors

2. **Verify user permissions**:
   - User must have `manage_options` capability
   - Check user role

3. **Check for plugin conflicts**:
   - Disable other plugins
   - Test if settings save

4. **Database issues**:
   - Check wp_options table is writable
   - Review database errors in logs

## Debugging Tools

### Enable WordPress Debug Mode

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
```

Check `wp-content/debug.log` for error messages.

### Browser Developer Tools

1. Open DevTools (F12)
2. Check Console tab for JavaScript errors
3. Check Network tab for failed requests
4. Review request/response details

### Query Monitor Plugin

Install [Query Monitor](https://wordpress.org/plugins/query-monitor/) for advanced debugging:

- Database queries
- HTTP requests
- PHP errors
- Hook execution

### Test Authentication Separately

Use the **Debug Auth** button in settings to test authentication independently of imports.

### Import History

Check Import History tab for:

- Detailed error messages
- Failed import attempts
- Success rates
- Pattern analysis

## Getting Help

If you still can't resolve the issue:

1. **Check Import History** for detailed error messages
2. **Enable debug mode** and collect error logs
3. **Reproduce the issue** in a clean environment if possible
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
wp option delete safe_publish_sync_direction
wp option delete safe_publish_number_of_posts
wp option delete safe_publish_auth_username
wp option delete safe_publish_auth_password
```

### Clear Import History

```php
// Using WP-CLI (caution: permanently deletes history)
wp db query "DELETE FROM {$wpdb->prefix}safe_publish_import_history"
```

### Complete Reset

Deactivate and reactivate the plugin to reset all settings.

## Next Steps

- [Local Development](local-development.md) - Set up debugging environment
- [Authentication](concepts/authentication.md) - Detailed auth setup
- [Import Process](concepts/import-process.md) - How imports work
- [Hooks and Filters](extending/hooks.md) - Customize behavior
