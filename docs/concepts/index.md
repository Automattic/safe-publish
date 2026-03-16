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

### Import History

Every import action is tracked and logged:

- Timestamp and user information
- Source URL and post ID
- Destination post ID
- Import status (success/failure)
- Error messages (if applicable)

View and manage your import history in the [Import History](import-history.md) tab.

## Technical concepts

If you want to understand the internals of Safe Publish so that you can write code to extend its functionality, head over to the [extending guide](../extending/index.md).

## Supported use cases

Like WordPress, Safe Publish is flexible. It can be used to enable advanced integrations with external data.

Below, you'll find specific use cases where Safe Publish shines. We are working to expand these use cases, but before you start, consider if Safe Publish is the right tool for the job.

### Safe Publish is a good fit if:

- Your remote data represents entities with a consistent schema.
  - **Example:** Product data representing items of clothing with defined attributes like “Name,” “Price,” “Color,” “Size,” etc.
- You want humans to select specific entities for display within the block editor.
  - **Example:** Select and display an item of clothing within a marketing post.
- You want to display arbitrary remote data based on a URL parameter and are willing to write a small amount of code.
  - **Example:** Create a page and rewrite rule for /products/{product_id}/ and configure a Remote Data Block on that page to display the referenced product.
- Your presentation of remote data aligns with the capabilities of [block bindings](block-bindings.md).
  - **Example:** Display an item of clothing using a core paragraph, heading, image, and button blocks.
- Your data is denormalized.
  - **Example:** A row from a Google Sheet with no references to external entities.

### Safe Publish may not be a good fit if:

- Your remote data is schema-less, or the schema changes over time.
  - Queries for remote data must define a schema for their return data. Schema changes result in broken blocks.
- You want to display remote data outside the context of the block editor.
  - Block bindings are only available in block content—posts, pages, or full-site editing. Using our plugin to define and resolve remote data may still provide some benefit (e.g., caching) but could require significant custom PHP code.
- Your data is normalized (and cannot be denormalized automatically by your API).
  - Some APIs can denormalize data by automatically “inflating” referenced records for you. For example, data representing an item of clothing might reference a color by ID instead of a renderable string like “forest green.” If your API does not denormalize this relationship automatically, you will need to write custom code to perform additional queries and stitch the responses together.
  - This can lead to a large number of API requests that your API may not tolerate. Airtable’s API, for example, imposes a rate limit of five requests per second, making multiple calls impractical.
- You have multiple remote data sources that require interaction with each other. Or, you want to implement a complex content architecture using safe-publish instead of leveraging WordPress custom post types and/or taxonomies.
  - These two challenges are directly related to the issues with normalized data. If you have data sources that relate to one another, you must write custom code to query missing data and stitch them together.
  - Judging complexity is difficult, but implementing large applications using safe-publish is not advisable.
- Your use case requires complex filtering of remote data or your API uses non-standard pagination.
  - Our UI components for filtering and pagination are still under development.

Over time, Safe Publish will grow and improve and these guidelines will change.
