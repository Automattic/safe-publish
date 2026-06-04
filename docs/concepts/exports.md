# Exports

The Exports admin page lists events logged when posts are served to destination sites via the REST API. The page is visible when the site is configured to export, or when historical export events remain from a prior configuration.

## Event data

Each row is a single audit log event from the `export` channel of the `{$wpdb->prefix}safe_publish_audit_log` table.

| Column      | Description                                                                        |
| ----------- | ---------------------------------------------------------------------------------- |
| Date        | When the event was recorded.                                                       |
| User        | WordPress user who triggered the export, or `System (<source>)` for system events. |
| Destination | URL of the destination site that requested the content.                            |
| Status      | Exported or Failed.                                                                |
| Posts       | Number of posts the event refers to.                                               |

System-triggered events (cron, CLI, HMAC, etc.) carry an actor source that disambiguates the invocation context; the actor's display name is captured at log time so the record survives renaming or deletion of the user.

## Event types

| Event                        | Meaning                                           |
| ---------------------------- | ------------------------------------------------- |
| `CONTENT_EXPORTED`           | Successful export served to the destination.      |
| `EXPORT_REQUEST_ERROR`       | The destination's request errored before serving. |
| `EXPORT_RESPONSE_BAD_STATUS` | The destination received a non-2xx HTTP response. |

## Privacy

Export events may contain destination URLs and post IDs. They do not contain post content. Audit log rows are append-only — there is no UI for deleting them — so this surface is suitable as the system of record.

## Next Steps

- [Imports](imports.md) — The inbound side of the migration.
- [Authentication](authentication.md) — Setting up source/destination credentials.
- [Troubleshooting](../troubleshooting.md) — Solving common issues.
