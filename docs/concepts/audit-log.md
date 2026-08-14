# Audit Log

The Audit Log admin page lists events recorded across every plugin channel. It reads the `{$wpdb->prefix}safe_publish_audit_log` table and is available in all environments to users with the `manage_options` capability.

Filter the view by channel, level (`info`, `warning`, `error`), event substring, and date range.

## Reviewing export events

Outbound exports — content served to destination sites via the REST API — are logged to the `export` channel. Filter the Audit Log to that channel to review them. Each export row carries its destination URL, post IDs, and post count inside the row's Details payload.

## Columns

| Column  | Description                                                                          |
| ------- | ------------------------------------------------------------------------------------ |
| Date    | When the event was recorded (stored as GMT, shown in browser-local time).            |
| Channel | The subsystem that logged the event.                                                 |
| Level   | `info`, `warning`, or `error`.                                                       |
| Event   | The event code (e.g. `CONTENT_EXPORTED`), shown with a human-readable label.         |
| User    | WordPress user who triggered the event, or `System (<source>)` for system events.    |
| Details | Event-specific payload as JSON (for exports: destination URL, post IDs, post count). |

System-triggered events (cron, CLI, HMAC, etc.) carry an actor source that disambiguates the invocation context; the actor's display name is captured at log time so the record survives renaming or deletion of the user.

## Channels

Every event belongs to one channel — the producer subsystem that logged it — so filtering by channel narrows the log to a single area.

| Channel     | What it logs                                                                        |
| ----------- | ----------------------------------------------------------------------------------- |
| `auth`      | Inbound HMAC request authentication and REST permission handling.                   |
| `content`   | Fetching source post content over REST.                                             |
| `dispatch`  | Non-export REST calls (list, preview, probe) that errored or returned a bad status. |
| `export`    | Content served to destination sites via REST.                                       |
| `import`    | Import item and session lifecycle: per-item failures, rollbacks, and deletions.     |
| `media`     | Media fetch, download, and sideload outcomes during import.                         |
| `reconcile` | Retry outcomes for degraded references (resolved, unresolved, absent, failed).      |
| `settings`  | Security-relevant settings changes (connected URL, Basic Auth, sync mode).          |

The exact event codes for each channel are defined in `Log_Events` (the contract the per-channel loggers enforce); the Event column renders each code as a human-readable label, and the Event filter matches any substring.

## Privacy

Audit log rows may contain destination URLs and post IDs. They do not contain post content. Rows are append-only — there is no UI for deleting them — so this surface is suitable as the system of record.

## Next Steps

- [Managing Imports](imports.md) — Browsing source content and reviewing imports.
- [Authentication](authentication.md) — Setting up source/destination credentials.
- [Troubleshooting](../troubleshooting.md) — Solving common issues.
