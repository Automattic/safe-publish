# Safe Publish

> [!WARNING]
> This plugin is currently in Beta. Breaking changes could occur with any update. Please test each release thoroughly before updating.

**Safe Publish** is a WordPress plugin that allows editors to securely promote content from non-production environments (staging, development) to production. It provides a user-friendly interface for browsing, previewing, and importing posts, pages, and custom post types while preserving all formatting, media, and metadata.

## Features

- **Secure Authentication**: Support for shared secret tokens and basic authentication
- **Content Preview**: View and compare content before importing with side-by-side diff view
- **Bulk Import**: Import multiple posts at once with progress tracking
- **Media Handling**: Automatically imports featured images and inline images
- **Block Preservation**: Maintains Gutenberg block formatting and structure
- **Import History**: Complete audit trail of all import actions
- **Post Type Support**: Works with posts, pages, and custom post types
- **VIP-Safe**: Built with WordPress VIP best practices and coding standards

## Use Cases

Safe Publish is ideal for:

- **Content Promotion Workflows**: Move approved content from staging to production
- **Editorial Review**: Create and review content in a safe environment before going live
- **Multi-Environment Publishing**: Separate content creation from publication
- **Compliance & Auditing**: Track all content imports with detailed history
- **Media-Rich Content**: Seamlessly import posts with multiple images

## Requirements

- **PHP**: 8.2 or higher
- **WordPress**: 6.8 or higher
- **HTTPS**: Required for secure communication between sites
- Administrator privileges on both source and destination sites

## Installation

### On WordPress VIP

The plugin is available in the VIP environment. Activate it through the WordPress admin panel and configure using the Safe Publish menu.

### On Other WordPress Environments

1. Download the plugin ZIP file from the repository
2. Upload to your `wp-content/plugins/` directory
3. Activate through the WordPress admin panel
4. Navigate to **Safe Publish** in the admin sidebar

## Quick Start

1. **Configure the Plugin**: Go to Safe Publish → Settings and enter your non-production site URL
2. **Set Up Authentication**: Install the MU plugin on your non-prod site and configure shared secret
3. **Test Connection**: Click "Test Connection" to verify everything is working
4. **Browse & Import**: Browse posts from your non-prod site and import them as drafts

See the [Quickstart Guide](docs/quickstart.md) for detailed instructions.

## Documentation

- **[Quickstart](docs/quickstart.md)** - Get started in minutes
- **[Core Concepts](docs/concepts/index.md)** - Understand how the plugin works
  - [Authentication](docs/concepts/authentication.md) - Setting up secure connections
  - [Content Validation](docs/concepts/validation.md) - Understanding validation checks
  - [Import Process](docs/concepts/import-process.md) - How imports work step-by-step
  - [Import History](docs/concepts/import-history.md) - Tracking and auditing imports
- **[Extending](docs/extending/index.md)** - Customize the plugin
  - [Hooks and Filters](docs/extending/hooks.md) - Available WordPress hooks
  - [Custom Post Types](docs/extending/post-types.md) - Supporting custom post types
  - [REST API Extension](docs/extending/api.md) - Extending the API
- **[Local Development](docs/local-development.md)** - Setting up a development environment
- **[Troubleshooting](docs/troubleshooting.md)** - Common issues and solutions

## Authentication Setup

The plugin requires authentication to connect to your non-production site. We recommend using the **shared secret** method:

1. Copy `client-mu-plugins/safe-publish-auth.php` to your non-prod site's `wp-content/mu-plugins/`
2. Add to both sites' `wp-config.php`:
   ```php
   define( 'SAFE_PUBLISH_SHARED_SECRET', 'your-secure-random-string-here' );
   ```
3. Generate a secure secret using: `openssl rand -base64 32`

See the [Authentication Guide](docs/concepts/authentication.md) for complete setup instructions.

## Contributing

Issues, pull requests, and discussions are welcome. Please see our [contribution guide](CONTRIBUTING.md) for more information.

## Support

- Report bugs and request features via GitHub Issues
- Check the [troubleshooting guide](docs/troubleshooting.md) for common issues
- Review [documentation](docs/index.md) for detailed information

## Security

If you discover a security vulnerability, please email security@wpvip.com instead of using the issue tracker.

## License

Safe Publish is licensed under the [GPLv2 (or later)](LICENSE).
