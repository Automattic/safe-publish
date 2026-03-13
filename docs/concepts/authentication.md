# Authentication

Safe Publish uses Shared Secret (HMAC) authentication, which is required for all environments. Basic Authentication can optionally be layered on top.

## Shared Secret Authentication (Required)

The shared secret method uses HMAC signatures to authenticate requests without exposing user credentials.

### How it Works

The shared secret authentication flow:

1. The destination site generates an HMAC signature using the shared secret and request details
2. The signature is sent in the `X-Safe-Publish-Signature` header alongside a timestamp and content hash
3. The Safe Publish auth module on the source site validates the signature against its configured secret
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

1. **On the source site**, install a basic auth plugin:
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

See the [Troubleshooting guide](../troubleshooting.md#connection-issues) for help with authentication errors.

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [Content Validation](validation.md) - Understanding validation checks
- [Troubleshooting](../troubleshooting.md) - Common issues and solutions
