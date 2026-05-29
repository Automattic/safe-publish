# Import and Export History

The History feature provides complete tracking and auditing of all content imports and exports performed through Safe Publish.

## Overview

Every import and export action—whether successful or failed—is logged for future reference. This provides:

- **Audit trail**: Know who imported or exported what and when.
- **Troubleshooting**: Identify failed operations and their error messages.
- **Compliance**: Meet regulatory requirements for content tracking.
- **Reporting**: Review activity across both import and export directions.

## Accessing History

1. Navigate to **Safe Publish** in WordPress admin.
2. Click **History** in the submenu.
3. Use the **Import History** and **Export History** tabs (visible tabs depend on the site's sync mode).

## Data Tracked

### Import History

Imports are tracked in a session-based model. Each import operation creates one **session** containing one or more **log entries** (one per post).

#### Session Data

| Field        | Description                                            |
| ------------ | ------------------------------------------------------ |
| ID           | Unique session ID                                      |
| Date         | When the session started                               |
| User         | WordPress user who performed the import                |
| Source URL   | URL of the source WordPress site                       |
| Session Type | `bulk` (multiple posts) or `single` (one post)         |
| Total Items  | Number of posts in the session                         |
| Successful   | Count of newly imported posts                          |
| Failed       | Count of failed imports                                |
| Updated      | Count of posts that were updated (already existed)     |
| Status       | `in_progress`, `completed`, `failed`, or `rolled_back` |

#### Log Entry Data (per post in a session)

| Field          | Description                                    |
| -------------- | ---------------------------------------------- |
| ID             | Unique log entry ID                            |
| Title          | Title of the imported post                     |
| Source post ID | Post ID on the source site                     |
| Status         | `success`, `updated`, or `error`               |
| Post ID        | Local WordPress post ID (on success or update) |
| Error          | Error message (if failed)                      |
| Edit URL       | Link to edit the imported post (if available)  |
| Can Rollback   | Whether rollback is available for this entry   |

### Export History

Export events are logged automatically when posts are served to a destination site. Each event is stored as a row in the `{$wpdb->prefix}safe_publish_audit_log` database table. The table itself has only six columns — `id`, `channel`, `level`, `event`, `created_at_gmt`, and `data`. The fields below are read from the event: ID, Date, Level, and Event map to columns, while the remaining fields are stored together in the `data` column as JSON.

| Field              | Description                                                                                         |
| ------------------ | --------------------------------------------------------------------------------------------------- |
| ID                 | Unique event ID                                                                                     |
| Date               | When the export occurred                                                                            |
| Level              | `info` (successful) or `error` (failed)                                                             |
| Event              | Event type: `CONTENT_EXPORTED`, `EXPORT_REQUEST_ERROR`, or `EXPORT_RESPONSE_BAD_STATUS`             |
| Actor User ID      | WordPress user ID that triggered the event, or `0` for system events                                |
| Actor Display Name | Snapshot of the user's display name at log time; empty for system events                            |
| Actor Source       | Invocation context: `cli`, `cron`, `hmac`, `xmlrpc`, `ajax`, `rest`, `admin`, `front`, or `unknown` |
| Destination URL    | URL of the destination site                                                                         |
| Post IDs           | IDs of the exported posts                                                                           |
| Post Count         | Number of posts in the export                                                                       |

## Viewing History

### Import History Table

The import history table displays one row per session:

| Column | Description                                        |
| ------ | -------------------------------------------------- |
| Date   | When the session occurred (click to view details)  |
| User   | Who performed the import                           |
| Items  | Total, successful, failed, and updated item counts |
| Status | Session status indicator                           |
| Source | Source site URL                                    |

Click the **Date** link or the **View Details** action to drill into the session's individual log entries.

### Export History Table

| Column      | Description                                                                                 |
| ----------- | ------------------------------------------------------------------------------------------- |
| Date        | When the export occurred                                                                    |
| User        | WordPress user who triggered the export, or `System (<source>)` for system-triggered events |
| Destination | Destination site URL                                                                        |
| Status      | Exported or Failed                                                                          |
| Posts       | Number of posts exported                                                                    |

### Sorting

Click column headers to sort by Date, User, or Status.

## Import Session Details

Click **View Details** on a session to see:

```
Session ID: 123
Date: 2024-10-29 14:23:45
User: admin
Status: completed
Source URL: https://staging.example.com
Session Type: bulk

Items:
  Total:      5
  Successful: 4
  Failed:     1
  Updated:    0
```

Each log entry within the session shows the post title, its status (`success`, `updated`, or `error`), the local post ID (if created), and a link to edit the post. Failed entries include the error message.

## Managing Import Records

### Rollback

A completed session or individual log entry can be rolled back if the **Rollback** action is available:

1. Find the session in the table.
2. Click **Rollback** in the session actions, or open the session details to roll back individual items.
3. Confirm the rollback.

Rollback reverts imported content to its state before the import. Only successfully imported items that have not yet been rolled back are eligible.

**Note**: Rollback is only available for `completed` sessions that have at least one successful item.

### Deleting Sessions

1. Click **Delete** next to a session.
2. Confirm the deletion.
3. Session and all its log entries are removed.

**Note**: Deleting a history record does NOT delete the imported posts.

## Database Storage

### Import History

Import history is stored in two custom database tables:

| Table                                      | Purpose                                             |
| ------------------------------------------ | --------------------------------------------------- |
| `{$wpdb->prefix}safe_publish_imports`      | One row per import session                          |
| `{$wpdb->prefix}safe_publish_import_items` | One row per imported item (linked via `session_id`) |

### Export History

Export events are stored in a custom database table:

- Table name: `{$wpdb->prefix}safe_publish_audit_log`
- Indexed on: `channel` + `created_at_gmt`, `level`, `event`

`actor_display_name` is captured at log time so the record survives renaming or deletion of the originating user. For system-triggered events (where `actor_user_id` is `0`), `actor_source` disambiguates the origin.

## Using Import History

### Troubleshooting Failed Imports

1. Find sessions with a `failed` status.
2. Open the session details to review individual log entries.
3. Review error messages and identify common patterns.
4. Address underlying issues.

Common failure patterns:

- **Authentication errors**: Check shared secret.
- **Media errors**: Verify image URLs are accessible.
- **Timeout errors**: Reduce the number of posts per import.

### Auditing Content Changes

Track what content was imported and when:

1. Review sessions filtered by source URL.
2. Open session details to inspect individual items.
3. Use rollback to revert unwanted imports if needed.

### User Activity Monitoring

See who is importing content:

1. Review sessions sorted by user.
2. Identify training needs or unexpected activity.

### Reviewing Export Activity

Track what content has been exported to destination sites:

1. Switch to the **Export History** tab.
2. Review export events by destination URL and date.
3. Investigate any events with a Failed status.

## Privacy Considerations

History records may contain:

- **Usernames**: Of users who performed imports
- **URLs**: Of source/destination content (may be internal/private)
- **Post titles**: May contain sensitive information

### User Deletion

Import history records are not tied to WordPress user accounts. When a user is deleted, history records are preserved and continue to display the original user's name as it was at the time of the import.

## Best Practices

1. **Regular review**: Check history weekly for both imports and exports.
2. **Monitor failures**: Investigate failed sessions promptly.
3. **Use rollback**: Revert unwanted imports rather than manually reverting changes.
4. **Train users**: Show team members how to read session details and use rollback.

## Next Steps

- [Authentication](authentication.md) - Setup secure connections
- [Import Process](import-process.md) - How imports work
- [Troubleshooting](../troubleshooting.md) - Solve common issues
- [Hooks and Filters](../extending/hooks.md) - Extend functionality
