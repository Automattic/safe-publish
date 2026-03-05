# Authentication

Safe Publish uses Shared Secret (HMAC) authentication, which is required for all environments. Basic Authentication can optionally be layered on top.

## Shared Secret Authentication (Required)

The shared secret method uses HMAC signatures to authenticate requests without exposing user credentials.

### Setup

1. **On the non-production site**, install the included MU plugin:
   - Copy `/mu-plugins/safe-publish-auth.php` to your non-prod site's `client-mu-plugins` directory. This file is only needed on the non-production site.
   - If the `mu-plugins` directory doesn't exist, create it

2. **On both sites** (production and non-production), add this line to `wp-config.php`:

   ```php
   define( 'SAFE_PUBLISH_SHARED_SECRET', 'your-secure-random-string-here' );
   ```

3. **Generate a secure secret**:
   - Use a password generator or run: `openssl rand -base64 32`
   - The secret must be **identical** on both sites
   - Store it securely (treat it like a password)

4. **Verify the setup**:
   - In the Safe Publish admin panel, click "Test Connection"
   - You should see a success message if authentication is working

### How it Works

The shared secret authentication flow:

1. Production site generates an HMAC signature using the shared secret and request details
2. The signature is sent in the `X-Safe-Publish-Signature` header alongside a timestamp and content hash
3. Non-production site's MU plugin validates the signature against its configured secret
4. If valid, the request is authenticated and granted access to the REST API
5. No user credentials are transmitted

### Security Considerations

- **Never commit** the shared secret to version control
- Use different secrets for different environment pairs
- Rotate secrets periodically (every 90 days recommended)
- Use HTTPS for all connections (required)

## Basic Authentication (Optional)

Basic authentication uses WordPress username and password credentials. It is applied on top of the required Shared Secret authentication when credentials are configured.

### Setup

1. **On the non-production site**, install a basic auth plugin:
   - [Application Passwords](https://wordpress.org/plugins/application-passwords/) (WordPress 5.6+)
   - Or [Basic Auth Plugin](https://github.com/WP-API/Basic-Auth)

2. **In Safe Publish settings**, enter:
   - Username of a user with sufficient permissions
   - Password or application password

3. **Test the connection** to verify it works

### Limitations

- **Security**: Credentials are sent with each request (even over HTTPS)
- **User account dependency**: Changes to user account affect the connection
- **Audit trail**: All imports appear as actions by the authenticated user

## Troubleshooting

### "Authentication failed" error

- **Shared Secret**: Verify the secret is identical on both sites and properly formatted in `wp-config.php`
- **Basic Auth**: Check username/password are correct and the user has proper permissions
- **Both**: Ensure the non-prod site is accessible and HTTPS is working

### "Unauthorized" or "403 Forbidden" errors

- Check that the MU plugin is installed on the non-prod site
- Verify the user (basic auth) has `edit_posts` capability
- Check server firewall rules aren't blocking requests

### Connection works but no posts appear

- Authentication is working; this is likely a different issue
- Check the non-prod site has published posts
- Verify the REST API is working: visit `https://your-site.com/wp-json/wp/v2/posts`
- See the [Troubleshooting guide](../troubleshooting.md) for more help

## REST API Endpoints

Safe Publish uses these WordPress REST API endpoints:

- `/wp-json/wp/v2/types` - Fetch available post types
- `/wp-json/wp/v2/posts` - Fetch posts
- `/wp-json/wp/v2/pages` - Fetch pages
- `/wp-json/wp/v2/{post_type}` - Fetch custom post types

Authentication is required for all these endpoints to function correctly.

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [Content Validation](validation.md) - Understanding validation checks
- [Troubleshooting](../troubleshooting.md) - Common issues and solutions
