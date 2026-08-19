# Safe Publish Documentation

**Safe Publish** is a WordPress plugin for securely promoting content from non-production WordPress environments (staging, development) to production. It provides a user-friendly interface for browsing, comparing, and importing posts while preserving supported formatting, media, and REST-exposed metadata.

## Table of Contents

- [Quickstart](quickstart.md) - Get started with the plugin in minutes
- [Core Concepts](concepts/index.md)
  - [Authentication](concepts/authentication.md) - Connecting to source WordPress sites
  - [Content Validation](concepts/validation.md) - How content is validated before import
  - [Import Process](concepts/import-process.md) - Understanding the content import workflow
  - [Managing Imports](concepts/imports.md) - Browsing source content and managing imports
  - [Audit Log](concepts/audit-log.md) - Reviewing logged events, including exports

- [Extending](extending/index.md)
  - [Hooks and Filters](extending/hooks.md) - Available WordPress hooks
  - [Custom Post Type Support](extending/post-types.md) - Supporting custom post types
  - [REST API Extension](extending/api.md) - Extending the plugin's API

- [Telemetry & Pendo plan](telemetry.md) - Backend events, frontend tagging, and the Pendo configuration plan
- [Local Development](local-development.md) - Setting up a development environment
- [Content Seeding](content-seeding.md) - Populating environments with test content
- [Troubleshooting](troubleshooting.md) - Common issues and solutions
