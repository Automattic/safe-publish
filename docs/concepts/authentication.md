# Authentication

Safe Publish uses Shared Secret (HMAC) authentication, which is required for all environments. Basic Authentication can optionally be layered on top.

## Shared Secret Authentication (Required)

The shared secret method uses HMAC signatures to authenticate requests without exposing user credentials.

### How it Works

The shared secret authentication flow:

1. The destination site generates an HMAC signature using the shared secret and request details
2. The request is sent with four headers: `X-Safe-Publish-Signature`, `X-Safe-Publish-Timestamp`, `X-Safe-Publish-Content-Hash`, and `X-Safe-Publish-Site-URL`
3. The Safe Publish auth module on the source site validates the signature against its configured secret
4. If valid, the request is authenticated and granted access to the REST API
5. No user credentials are transmitted

### Rotating the Secret

When you rotate `SAFE_PUBLISH_SHARED_SECRET` on either site, the authentication status indicator in the admin UI may continue to show the previous state for up to 5 minutes. The indicator reads from a short-lived cache that is refreshed on its next probe, so no action is required — it will catch up on its own.

To verify a rotation immediately, use the **Test Connection** button on the settings screen.

## Basic Authentication (Optional)

Optionally, it's possible to use [VIP Basic Authentication](https://docs.wpvip.com/security-controls/basic-authentication/) if the source site needs it.

### Setup

1. Create the credentials for your source site
2. On the destination site, enter the credentials under Safe Publish settings
3. Test the connection to verify it works

### Limitations

- **Security**: Credentials are sent with each request
- **Credential dependency**: Rotating or removing VIP Basic Authentication credentials will break the connection until Safe Publish settings are updated
- Read about all other [VIP Basic Authentication limitations](https://docs.wpvip.com/security-controls/basic-authentication/#h-limitations)

## Troubleshooting

See the [Troubleshooting guide](../troubleshooting.md#connection-issues) for help with authentication errors.

## Next Steps

- [Import Process](import-process.md) - Learn how content is imported
- [Content Validation](validation.md) - Understanding validation checks
- [Troubleshooting](../troubleshooting.md) - Common issues and solutions
