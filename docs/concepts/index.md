# Core Concepts

Safe Publish is a WordPress plugin that allows editors to promote content from non-production environments to production. This guide will help you understand the core concepts of the plugin and how they work.

## What is Safe Publish?

**Safe Publish** is a plugin that enables controlled content migration from non-production WordPress environments (staging, development) to production environments. It provides a secure, user-friendly interface for:

- Browsing content from external WordPress sites via the WordPress REST API
- Previewing and comparing content before importing
- Importing posts, pages, and custom post types while preserving all formatting and media
- Tracking import history for auditing and compliance purposes

The plugin is designed with WordPress VIP best practices in mind, ensuring security, performance, and compatibility with enterprise WordPress environments.

## Key Concepts

### Authentication

Safe Publish supports two authentication methods for secure communication between sites:

- **[Shared Secret](authentication.md)** (Required): Uses a secure token defined in `wp-config.php` on both sites
- **Basic Authentication** (optional): Username/password authentication

See the [Authentication guide](authentication.md) for detailed setup instructions.

### Content Validation

Before importing, Safe Publish validates content to ensure data integrity:

- **URL validation**: Ensures the external site is a valid, accessible WordPress installation
- **Content structure validation**: Verifies post data structure and required fields
- **Media validation**: Checks image URLs and accessibility

Learn more about [Content Validation](validation.md).

### Import Process

The import process consists of several stages:

1. **Fetch**: Retrieve post data from external site via REST API
2. **Validate**: Check content structure and accessibility
3. **Transform**: Process Gutenberg blocks and extract media references
4. **Import Media**: Download and import featured images and inline images
5. **Create Post**: Create draft post with all content and metadata
6. **Track History**: Log the import for auditing

See the [Import Process guide](import-process.md) for a detailed breakdown.

### History

Every import action is tracked and logged:

- Timestamp and user information
- Source URL and post ID
- Destination post ID
- Import status (success/failure)
- Error messages (if applicable)

View and manage your import and export history in the [History](history.md) page.

## Technical concepts

If you want to understand the internals of Safe Publish so that you can write code to extend its functionality, head over to the [extending guide](../extending/index.md).

## Supported use cases

Safe Publish is designed specifically for controlled content promotion between WordPress environments.

### Safe Publish is a good fit if:

- You maintain separate staging and production WordPress sites and need a governed process to promote content between them.
- You want editors to review and selectively import content rather than syncing everything automatically.
- You need a complete audit trail of who imported what content and when (compliance or editorial governance requirements).
- You work with media-rich content and need images automatically imported alongside post content.
- You want to extend or customize the import workflow with WordPress hooks.

### Safe Publish may not be a good fit if:

- You need real-time or automatic content synchronization without human review.
- You need to import complex plugin-specific data (e.g., ACF field groups, WooCommerce product data) without custom development. Basic post meta is imported, but plugin-specific data structures require additional code.
- You are migrating an entire site — Safe Publish is optimized for ongoing selective content promotion, not one-time full migrations.
